ALTER TABLE queue_tickets DROP CONSTRAINT queue_tickets_status_check;
ALTER TABLE queue_tickets ADD CONSTRAINT queue_tickets_status_check CHECK(status IN ('waiting','called','in_progress','completed','skipped','cancelled'));
ALTER TABLE queue_tickets ADD COLUMN called_at timestamptz, ADD COLUMN started_at timestamptz, ADD COLUMN completed_at timestamptz;
CREATE INDEX queue_tickets_list ON queue_tickets(tenant_id,created_at,id);

CREATE FUNCTION governance.queue_scope(code text,did uuid,bid uuid) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT governance.scheduling_identity() AND code IN ('queues.view','queues.call','queues.recall','queues.start','queues.complete','queues.skip','queues.cancel','queues.requeue') AND
 CASE public.permission_scope(code) WHEN 'entire_clinic' THEN true WHEN 'assigned_branches' THEN public.branch_assigned(bid)
 WHEN 'own' THEN governance.doctor_branch_live(did,bid) AND EXISTS(SELECT 1 FROM public.doctor_profiles d JOIN public.tenant_memberships m ON (m.tenant_id,m.id)=(d.tenant_id,d.membership_id) WHERE d.tenant_id=public.context_tenant() AND d.id=did AND m.user_id=public.context_user() AND m.active)
 ELSE false END
$$;
CREATE FUNCTION governance.queue_visible(qid uuid) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT EXISTS(SELECT 1 FROM public.queue_tickets q JOIN public.visits v ON (v.tenant_id,v.id)=(q.tenant_id,q.visit_id) WHERE q.tenant_id=public.context_tenant() AND q.id=qid AND governance.queue_scope('queues.view',v.doctor_id,q.branch_id))
$$;
CREATE FUNCTION governance.queue_projection(qid uuid) RETURNS jsonb LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT jsonb_build_object('id',q.id,'visit_id',q.visit_id,'patient_id',v.patient_id,'doctor_id',v.doctor_id,'branch_id',q.branch_id,'appointment_id',v.appointment_id,'status',q.status,'version',q.version,'number',q.number,'kind',q.kind,'local_date',q.local_date)
 FROM public.queue_tickets q JOIN public.visits v ON (v.tenant_id,v.id)=(q.tenant_id,q.visit_id) WHERE q.tenant_id=public.context_tenant() AND q.id=qid AND governance.queue_visible(q.id)
$$;
-- Private full synchronized response: never callable directly by the runtime role.
CREATE FUNCTION governance.queue_attendance(qid uuid) RETURNS jsonb LANGUAGE sql STABLE SET search_path=pg_catalog AS $$
 SELECT governance.attendance_projection(q.visit_id) FROM public.queue_tickets q WHERE q.tenant_id=public.context_tenant() AND q.id=qid
$$;
CREATE FUNCTION governance.queue_command(action_code text,rid uuid,payload jsonb,request_key uuid) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE tid uuid:=public.context_tenant(); actor_id uuid; target_user uuid; q public.queue_tickets; v public.visits; a public.appointments; saved public.operation_idempotency;
 digest_value text; operation_code text:='queue.'||action_code; next_q text; next_v text; next_a text; room uuid; lo timestamptz; hi timestamptz; zone text; res_id uuid; result jsonb; internal_key uuid;
BEGIN
 IF action_code NOT IN ('create','call','recall','start','complete','skip','cancel','requeue') OR request_key IS NULL OR (action_code='create' AND rid IS NOT NULL) OR (action_code<>'create' AND rid IS NULL) THEN RAISE EXCEPTION 'Invalid command' USING ERRCODE='22023'; END IF;
 IF action_code='create' THEN
  PERFORM governance.booking_validate_payload('walk_in',payload);
  SELECT m.user_id INTO target_user FROM public.doctor_profiles d JOIN public.tenant_memberships m ON (m.tenant_id,m.id)=(d.tenant_id,d.membership_id) WHERE d.tenant_id=tid AND d.id=(payload->>'doctor_id')::uuid;
  PERFORM governance.lock_users(ARRAY[public.context_user(),target_user]); PERFORM governance.actor();
  PERFORM id FROM public.tenants WHERE id=tid AND status='active' FOR UPDATE;
  IF NOT FOUND OR NOT governance.scheduling_identity() OR NOT governance.booking_scope('visits.create',(payload->>'doctor_id')::uuid,(payload->>'branch_id')::uuid) OR NOT governance.booking_scope('queues.create',(payload->>'doctor_id')::uuid,(payload->>'branch_id')::uuid) THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
  SELECT id INTO actor_id FROM public.tenant_memberships WHERE tenant_id=tid AND user_id=public.context_user() AND active;
  IF NOT governance.patient_allowed((payload->>'patient_id')::uuid,'patients.view') OR NOT governance.doctor_branch_live((payload->>'doctor_id')::uuid,(payload->>'branch_id')::uuid)
   OR NOT EXISTS(SELECT 1 FROM public.patient_branches WHERE (tenant_id,patient_id,branch_id)=(tid,(payload->>'patient_id')::uuid,(payload->>'branch_id')::uuid))
   OR NOT EXISTS(SELECT 1 FROM public.doctor_services ds JOIN public.services s ON (s.tenant_id,s.id)=(ds.tenant_id,ds.service_id) AND s.active WHERE (ds.tenant_id,ds.doctor_id,ds.service_id)=(tid,(payload->>'doctor_id')::uuid,(payload->>'service_id')::uuid)) THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
  IF EXISTS(SELECT 1 FROM public.patients WHERE (tenant_id,id)=(tid,(payload->>'patient_id')::uuid) AND archived_at IS NOT NULL) THEN RAISE EXCEPTION 'Archived' USING ERRCODE='23505'; END IF;
  digest_value:=encode(sha256(convert_to(payload::text,'UTF8')),'hex');
  SELECT * INTO saved FROM public.operation_idempotency WHERE tenant_id=tid AND actor_membership_id=actor_id AND operation=operation_code AND key=request_key;
  IF FOUND AND saved.expires_at>now() THEN
   IF saved.request_digest<>digest_value THEN RAISE EXCEPTION 'Conflict' USING ERRCODE='23505'; END IF;
   SELECT id INTO rid FROM public.queue_tickets WHERE tenant_id=tid AND visit_id=saved.visit_id;
   RETURN governance.queue_attendance(rid);
  END IF;
  -- Reuse Stage-4 allocation atomically. Replace its private transient ledger entry
  -- with the public queue operation/key, never persist a transformed client key.
  internal_key:=gen_random_uuid();
  result:=governance.booking_command('walk_in',NULL,payload,internal_key);
  DELETE FROM public.operation_idempotency WHERE tenant_id=tid AND actor_membership_id=actor_id AND operation='walk_in' AND key=internal_key;
  DELETE FROM public.operation_idempotency WHERE tenant_id=tid AND actor_membership_id=actor_id AND operation=operation_code AND key=request_key;
  INSERT INTO public.operation_idempotency(tenant_id,actor_membership_id,operation,key,request_digest,visit_id) VALUES(tid,actor_id,operation_code,request_key,digest_value,(result->'visit'->>'id')::uuid);
  PERFORM governance.event(operation_code,'queue_ticket',(result->'ticket'->>'id')::uuid,tid);
  RETURN result;
 END IF;
 IF action_code='start' THEN
  PERFORM governance.booking_validate_payload('reschedule',payload || jsonb_build_object('doctor_id','00000000-0000-0000-0000-000000000000','branch_id','00000000-0000-0000-0000-000000000000'));
  IF payload ?| ARRAY['doctor_id','branch_id'] THEN RAISE EXCEPTION 'Invalid fields' USING ERRCODE='22023'; END IF;
 ELSE PERFORM governance.booking_validate_payload('queue',payload); END IF;
 SELECT m.user_id INTO target_user FROM public.queue_tickets t JOIN public.visits vi ON (vi.tenant_id,vi.id)=(t.tenant_id,t.visit_id) JOIN public.doctor_profiles d ON (d.tenant_id,d.id)=(vi.tenant_id,vi.doctor_id) JOIN public.tenant_memberships m ON (m.tenant_id,m.id)=(d.tenant_id,d.membership_id) WHERE t.tenant_id=tid AND t.id=rid;
 PERFORM governance.lock_users(ARRAY[public.context_user(),target_user]); PERFORM governance.actor();
 PERFORM id FROM public.tenants WHERE id=tid AND status='active' FOR UPDATE;
 IF NOT FOUND OR NOT governance.scheduling_identity() OR public.permission_scope('queues.'||action_code) IS NULL THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 SELECT id INTO actor_id FROM public.tenant_memberships WHERE tenant_id=tid AND user_id=public.context_user() AND active;
 IF NOT governance.queue_visible(rid) THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
 SELECT * INTO q FROM public.queue_tickets WHERE (tenant_id,id)=(tid,rid);
 SELECT * INTO v FROM public.visits WHERE (tenant_id,id)=(tid,q.visit_id);
 IF NOT governance.queue_scope('queues.'||action_code,v.doctor_id,v.branch_id) THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 -- Attendance includes all three projections: authorize each independently, also on replay.
 IF NOT governance.booking_visible('visits',v.id,'visits.view') OR (v.appointment_id IS NOT NULL AND NOT governance.booking_visible('appointments',v.appointment_id,'appointments.view')) THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
 IF v.appointment_id IS NOT NULL AND action_code IN ('start','complete','cancel') AND NOT governance.booking_scope(CASE WHEN action_code='cancel' THEN 'appointments.cancel' ELSE 'appointments.update' END,v.doctor_id,v.branch_id) THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 -- Required read/reference capabilities are reauthorized on idempotency replay too.
 IF action_code IN ('start','requeue') AND NOT governance.patient_allowed(v.patient_id,'patients.view') THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
 IF action_code='start' AND (public.permission_scope('services.view') IS DISTINCT FROM 'entire_clinic' OR governance.scheduling_branch('doctors.view',v.branch_id) IS NOT TRUE OR governance.scheduling_branch('rooms.view',v.branch_id) IS NOT TRUE OR governance.scheduling_branch('schedules.view',v.branch_id) IS NOT TRUE) THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 IF action_code IN ('call','recall') AND (NOT governance.doctor_branch_live(v.doctor_id,v.branch_id) OR NOT EXISTS(SELECT 1 FROM public.doctor_services ds JOIN public.services s ON (s.tenant_id,s.id)=(ds.tenant_id,ds.service_id) AND s.active WHERE (ds.tenant_id,ds.doctor_id,ds.service_id)=(tid,v.doctor_id,v.service_id))) THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
 digest_value:=encode(sha256(convert_to(jsonb_build_object('id',rid,'payload',payload)::text,'UTF8')),'hex');
 SELECT * INTO saved FROM public.operation_idempotency WHERE tenant_id=tid AND actor_membership_id=actor_id AND operation=operation_code AND key=request_key;
 IF FOUND AND saved.expires_at>now() THEN
  IF saved.request_digest<>digest_value OR saved.visit_id IS DISTINCT FROM v.id THEN RAISE EXCEPTION 'Conflict' USING ERRCODE='23505'; END IF;
  RETURN governance.queue_attendance(rid);
 END IF;
 -- Order matches Stage 4: users -> tenant -> rooms -> appointment/reservation -> visit -> ticket.
 PERFORM id FROM public.rooms WHERE tenant_id=tid AND id IN ((payload->>'room_id')::uuid,(SELECT rr.room_id FROM public.resource_reservations rr WHERE (rr.tenant_id,rr.id)=(tid,v.reservation_id))) ORDER BY id FOR UPDATE;
 IF v.appointment_id IS NOT NULL THEN SELECT * INTO a FROM public.appointments WHERE (tenant_id,id)=(tid,v.appointment_id) FOR UPDATE; END IF;
 PERFORM id FROM public.resource_reservations WHERE (tenant_id,id)=(tid,v.reservation_id) FOR UPDATE;
 SELECT * INTO v FROM public.visits WHERE (tenant_id,id)=(tid,q.visit_id) FOR UPDATE;
 SELECT * INTO q FROM public.queue_tickets WHERE (tenant_id,id)=(tid,rid) FOR UPDATE;
 IF q.version IS DISTINCT FROM (payload->>'expected_version')::bigint THEN RAISE EXCEPTION 'Stale version' USING ERRCODE='23505'; END IF;
 next_q:=CASE
 WHEN action_code='call' AND q.status='waiting' THEN 'called'
 WHEN action_code='recall' AND q.status='called' THEN 'called'
 WHEN action_code='start' AND q.status='called' THEN 'in_progress'
 WHEN action_code='complete' AND q.status='in_progress' THEN 'completed'
 WHEN action_code='skip' AND q.status IN ('waiting','called') THEN 'skipped'
 WHEN action_code='requeue' AND q.status='skipped' THEN 'waiting'
 WHEN action_code='cancel' AND q.status IN ('waiting','called','skipped') THEN 'cancelled' END;
 IF next_q IS NULL THEN RAISE EXCEPTION 'State conflict' USING ERRCODE='23505'; END IF;
 next_v:=CASE next_q WHEN 'waiting' THEN 'queued' ELSE next_q END;
 IF action_code IN ('start','requeue') THEN
  IF NOT governance.patient_allowed(v.patient_id,'patients.view') OR EXISTS(SELECT 1 FROM public.patients WHERE (tenant_id,id)=(tid,v.patient_id) AND archived_at IS NOT NULL)
   OR NOT EXISTS(SELECT 1 FROM public.patient_branches WHERE (tenant_id,patient_id,branch_id)=(tid,v.patient_id,v.branch_id)) OR NOT governance.doctor_branch_live(v.doctor_id,v.branch_id)
   OR NOT EXISTS(SELECT 1 FROM public.doctor_services ds JOIN public.services s ON (s.tenant_id,s.id)=(ds.tenant_id,ds.service_id) AND s.active WHERE (ds.tenant_id,ds.doctor_id,ds.service_id)=(tid,v.doctor_id,v.service_id)) THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
  IF v.appointment_id IS NOT NULL AND (a.ends_at<=clock_timestamp() OR NOT governance.booking_slot_valid(a.doctor_id,a.branch_id,a.service_id,a.room_id,a.starts_at,a.ends_at) OR NOT EXISTS(SELECT 1 FROM public.resource_reservations WHERE (tenant_id,id)=(tid,v.reservation_id) AND active)) THEN RAISE EXCEPTION 'Reservation conflict' USING ERRCODE='23505'; END IF;
 END IF;
 IF action_code='start' THEN
  room:=(payload->>'room_id')::uuid; lo:=(payload->>'starts_at')::timestamptz; hi:=(payload->>'ends_at')::timestamptz;
  SELECT coalesce(b.timezone,t.timezone) INTO zone FROM public.branches b JOIN public.tenants t ON t.id=b.tenant_id WHERE (b.tenant_id,b.id)=(tid,v.branch_id);
  IF ((payload->>'starts_at') !~ '(Z|[+-]00:00)$' AND regexp_replace(payload->>'starts_at','[+-]\d{2}:\d{2}$','')::timestamp IS DISTINCT FROM lo AT TIME ZONE zone)
   OR ((payload->>'ends_at') !~ '(Z|[+-]00:00)$' AND regexp_replace(payload->>'ends_at','[+-]\d{2}:\d{2}$','')::timestamp IS DISTINCT FROM hi AT TIME ZONE zone)
   OR hi<=clock_timestamp() OR NOT governance.booking_slot_valid(v.doctor_id,v.branch_id,v.service_id,room,lo,hi) THEN RAISE EXCEPTION 'Invalid slot' USING ERRCODE='23505'; END IF;
  IF v.appointment_id IS NOT NULL THEN
   IF (room,lo,hi) IS DISTINCT FROM (a.room_id,a.starts_at,a.ends_at) THEN RAISE EXCEPTION 'Existing reservation required' USING ERRCODE='23505'; END IF;
  ELSE
   res_id:=gen_random_uuid();
   INSERT INTO public.resource_reservations(id,tenant_id,doctor_id,branch_id,room_id,visit_id,starts_at,ends_at) VALUES(res_id,tid,v.doctor_id,v.branch_id,room,v.id,lo,hi);
   UPDATE public.visits SET reservation_id=res_id WHERE (tenant_id,id)=(tid,v.id);
  END IF;
 END IF;
 UPDATE public.queue_tickets SET status=next_q,version=version+1,updated_at=now(),called_at=CASE WHEN action_code IN ('call','recall') THEN clock_timestamp() ELSE called_at END,started_at=CASE WHEN action_code='start' THEN clock_timestamp() ELSE started_at END,completed_at=CASE WHEN action_code IN ('complete','cancel') THEN clock_timestamp() ELSE completed_at END WHERE (tenant_id,id)=(tid,rid);
 UPDATE public.visits SET status=next_v,version=version+1,updated_at=now() WHERE (tenant_id,id)=(tid,v.id);
 PERFORM governance.booking_event('ticket',rid,v.doctor_id,v.branch_id,q.status,next_q,CASE WHEN action_code='cancel' THEN 'queue_cancel' ELSE action_code END,actor_id);
 PERFORM governance.booking_event('visit',v.id,v.doctor_id,v.branch_id,v.status,next_v,CASE WHEN action_code='cancel' THEN 'queue_cancel' ELSE action_code END,actor_id);
 IF v.appointment_id IS NOT NULL AND action_code IN ('start','complete','cancel') THEN
  next_a:=CASE action_code WHEN 'start' THEN 'in_progress' WHEN 'complete' THEN 'completed' ELSE 'cancelled' END;
  UPDATE public.appointments SET status=next_a,version=version+1,updated_at=now() WHERE (tenant_id,id)=(tid,a.id);
  PERFORM governance.booking_event('appointment',a.id,v.doctor_id,v.branch_id,a.status,next_a,CASE WHEN action_code='cancel' THEN 'queue_cancel' ELSE action_code END,actor_id);
 END IF;
 IF action_code IN ('complete','cancel') THEN UPDATE public.resource_reservations SET active=false,updated_at=now() WHERE (tenant_id,id)=(tid,coalesce(res_id,v.reservation_id)); END IF;
 DELETE FROM public.operation_idempotency WHERE tenant_id=tid AND actor_membership_id=actor_id AND operation=operation_code AND key=request_key;
 INSERT INTO public.operation_idempotency(tenant_id,actor_membership_id,operation,key,request_digest,visit_id) VALUES(tid,actor_id,operation_code,request_key,digest_value,v.id);
 PERFORM governance.event(operation_code,'queue_ticket',rid,tid);
 RETURN governance.queue_attendance(rid);
END $$;
CREATE FUNCTION governance.queue_integrity() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE j jsonb:=to_jsonb(NEW); vid uuid:=CASE WHEN TG_TABLE_NAME='visits' THEN (j->>'id')::uuid ELSE (j->>'visit_id')::uuid END;
BEGIN
 IF EXISTS(SELECT 1 FROM public.queue_tickets q JOIN public.visits v ON (v.tenant_id,v.id)=(q.tenant_id,q.visit_id) WHERE q.tenant_id=NEW.tenant_id AND v.id=vid AND (v.status IS DISTINCT FROM CASE q.status WHEN 'waiting' THEN 'queued' ELSE q.status END OR q.kind IS DISTINCT FROM CASE WHEN v.appointment_id IS NULL THEN 'walk_in' ELSE 'scheduled' END OR (v.status='in_progress' AND v.reservation_id IS NULL))) THEN RAISE EXCEPTION 'Queue consistency' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER queue_integrity AFTER INSERT OR UPDATE ON queue_tickets DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION governance.queue_integrity();
CREATE CONSTRAINT TRIGGER queue_integrity AFTER INSERT OR UPDATE ON visits DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION governance.queue_integrity();
-- Extend the existing deferred schedule guard to active walk-in consultations.
CREATE OR REPLACE FUNCTION governance.booking_schedule_guard() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE tid uuid:=coalesce(NEW.tenant_id,OLD.tenant_id);
BEGIN
 IF EXISTS(SELECT 1 FROM public.appointments a WHERE a.tenant_id=tid AND a.status IN ('scheduled','checked_in','in_progress') AND NOT governance.booking_slot_valid(a.doctor_id,a.branch_id,a.service_id,a.room_id,a.starts_at,a.ends_at,false))
 OR EXISTS(SELECT 1 FROM public.resource_reservations r JOIN public.visits v ON (v.tenant_id,v.id)=(r.tenant_id,r.visit_id) WHERE r.tenant_id=tid AND r.active AND r.appointment_id IS NULL AND NOT governance.booking_slot_valid(v.doctor_id,v.branch_id,v.service_id,r.room_id,r.starts_at,r.ends_at,false)) THEN RAISE EXCEPTION 'Existing attendance would be invalidated' USING ERRCODE='23505'; END IF;
 RETURN NULL;
END $$;
