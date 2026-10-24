CREATE TABLE public.employee_work_calendars (
 tenant_id uuid NOT NULL, employee_id uuid NOT NULL, branch_id uuid NOT NULL, version bigint NOT NULL DEFAULT 0,
 PRIMARY KEY(tenant_id,employee_id,branch_id), FOREIGN KEY(tenant_id,employee_id) REFERENCES public.employee_profiles(tenant_id,id), FOREIGN KEY(tenant_id,branch_id) REFERENCES public.branches(tenant_id,id)
);
CREATE TABLE public.employee_work_schedules (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), tenant_id uuid NOT NULL, employee_id uuid NOT NULL, branch_id uuid NOT NULL,
 weekday int NOT NULL CHECK(weekday BETWEEN 1 AND 7), start_time time NOT NULL, end_time time NOT NULL,
 receives_patients boolean NOT NULL DEFAULT false,
 CHECK(end_time>start_time AND extract(second FROM start_time)=0 AND extract(second FROM end_time)=0),
 UNIQUE(tenant_id,employee_id,branch_id,weekday), FOREIGN KEY(tenant_id,employee_id,branch_id) REFERENCES public.employee_work_calendars(tenant_id,employee_id,branch_id)
);
CREATE FUNCTION governance.employee_work_visible(eid uuid,bid uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT governance.scheduling_identity() AND public.permission_scope('employees.view') IS NOT NULL
 AND EXISTS(SELECT 1 FROM public.branches WHERE tenant_id=public.context_tenant() AND id=bid)
 AND (public.permission_scope('employees.view')='entire_clinic' OR public.permission_scope('employees.view')='assigned_branches' AND public.branch_assigned(bid))
 AND (EXISTS(SELECT 1 FROM public.employee_profiles p WHERE p.tenant_id=public.context_tenant() AND p.id=eid AND
  (public.permission_scope('employees.view')='entire_clinic' OR p.membership_id IS NOT NULL AND public.can_view_member(p.membership_id)))
 OR EXISTS(SELECT 1 FROM public.tenant_memberships m WHERE m.tenant_id=public.context_tenant() AND m.id=eid AND public.can_view_member(m.id)))
$$;
ALTER FUNCTION governance.employee_work_visible(uuid,uuid) OWNER TO blusser_security;
REVOKE ALL ON FUNCTION governance.employee_work_visible(uuid,uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION governance.employee_work_visible(uuid,uuid) TO blusser_app;
ALTER TABLE public.employee_work_calendars ENABLE ROW LEVEL SECURITY; ALTER TABLE public.employee_work_calendars FORCE ROW LEVEL SECURITY;
ALTER TABLE public.employee_work_schedules ENABLE ROW LEVEL SECURITY; ALTER TABLE public.employee_work_schedules FORCE ROW LEVEL SECURITY;
CREATE POLICY migration_owner ON public.employee_work_calendars TO blusser_owner USING(true) WITH CHECK(true);
CREATE POLICY migration_owner ON public.employee_work_schedules TO blusser_owner USING(true) WITH CHECK(true);
CREATE POLICY work_calendar_read ON public.employee_work_calendars FOR SELECT TO blusser_app USING(tenant_id=public.context_tenant() AND governance.employee_work_visible(employee_id,branch_id));
CREATE POLICY work_schedule_read ON public.employee_work_schedules FOR SELECT TO blusser_app USING(tenant_id=public.context_tenant() AND governance.employee_work_visible(employee_id,branch_id));
GRANT SELECT ON public.employee_work_calendars,public.employee_work_schedules TO blusser_app;
GRANT SELECT,INSERT,UPDATE,DELETE ON public.employee_work_calendars,public.employee_work_schedules TO blusser_security;
CREATE FUNCTION governance.employee_work_read(eid uuid,bid uuid) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN
 PERFORM governance.actor();
 IF NOT governance.employee_work_visible(eid,bid) THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
 RETURN jsonb_build_object('version',coalesce((SELECT version FROM public.employee_work_calendars WHERE tenant_id=public.context_tenant() AND employee_id=eid AND branch_id=bid),0),
 'data',coalesce((SELECT jsonb_agg(jsonb_build_object('id',id,'employee_id',employee_id,'branch_id',branch_id,'weekday',weekday,'start_time',to_char(start_time,'HH24:MI'),'end_time',to_char(end_time,'HH24:MI'),'receives_patients',receives_patients) ORDER BY weekday) FROM public.employee_work_schedules WHERE tenant_id=public.context_tenant() AND employee_id=eid AND branch_id=bid),'[]'::jsonb));
END $$;
CREATE FUNCTION governance.employee_work_save(eid uuid,bid uuid,day_rows jsonb,removals jsonb,expected bigint,intent uuid) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE tid uuid:=public.context_tenant(); p public.employee_profiles; memo public.access_editor_intents; digest text; current_version bigint; r jsonb; result jsonb;
BEGIN
 PERFORM governance.lock_users(ARRAY[public.context_user(),(SELECT m.user_id FROM public.employee_profiles ep JOIN public.tenant_memberships m ON (m.tenant_id,m.id)=(ep.tenant_id,ep.membership_id) WHERE ep.tenant_id=tid AND ep.id=eid)]);
 PERFORM governance.tenant('employees.update');
 PERFORM id FROM public.tenants WHERE id=tid FOR UPDATE;
 IF NOT governance.employee_work_visible(eid,bid) THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
 SELECT * INTO p FROM public.employee_profiles WHERE tenant_id=tid AND id=eid;
 IF NOT FOUND OR NOT EXISTS(SELECT 1 FROM public.branches WHERE tenant_id=tid AND id=bid AND active)
 OR NOT (p.profile->>'branch'=bid::text OR EXISTS(SELECT 1 FROM public.membership_branches WHERE tenant_id=tid AND membership_id=p.membership_id AND branch_id=bid)) THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
 IF intent IS NULL OR expected IS NULL THEN RAISE EXCEPTION 'Version required' USING ERRCODE='P0003'; END IF;
 IF jsonb_typeof(day_rows) IS DISTINCT FROM 'array' OR jsonb_array_length(day_rows)>7 OR jsonb_typeof(removals) IS DISTINCT FROM 'array' THEN RAISE EXCEPTION 'Invalid day_rows' USING ERRCODE='22023'; END IF;
 IF EXISTS(SELECT 1 FROM jsonb_array_elements(removals) x WHERE jsonb_typeof(x)<>'number' OR x::text !~ '^[1-7]$') THEN RAISE EXCEPTION 'Invalid removal' USING ERRCODE='22023'; END IF;
 FOR r IN SELECT value FROM jsonb_array_elements(day_rows) LOOP
  IF jsonb_typeof(r) IS DISTINCT FROM 'object' OR NOT(r?&ARRAY['weekday','start_time','end_time','receives_patients']) OR r-ARRAY['weekday','start_time','end_time','receives_patients']<>'{}'::jsonb
  OR jsonb_typeof(r->'weekday')<>'number' OR r->>'weekday' !~ '^[1-7]$' OR jsonb_typeof(r->'receives_patients')<>'boolean'
  OR jsonb_typeof(r->'start_time')<>'string' OR jsonb_typeof(r->'end_time')<>'string' OR r->>'start_time' !~ '^([01][0-9]|2[0-3]):[0-5][0-9]$' OR r->>'end_time' !~ '^([01][0-9]|2[0-3]):[0-5][0-9]$'
  OR r->>'end_time'<=r->>'start_time' THEN RAISE EXCEPTION 'Invalid day' USING ERRCODE='22023'; END IF;
 END LOOP;
 IF (SELECT count(DISTINCT value->>'weekday') FROM jsonb_array_elements(day_rows))<>jsonb_array_length(day_rows) THEN RAISE EXCEPTION 'Duplicate day' USING ERRCODE='22023'; END IF;
 digest:=md5(jsonb_build_object('employee',eid,'branch',bid,'day_rows',day_rows,'removals',removals,'version',expected)::text);
 SELECT * INTO memo FROM public.access_editor_intents WHERE tenant_id=tid AND actor_id=public.context_user() AND key=intent;
 IF FOUND THEN IF memo.operation<>'employee.work' OR memo.payload_hash<>digest THEN RAISE EXCEPTION 'Replay conflict' USING ERRCODE='23505'; END IF; RETURN memo.result; END IF;
 INSERT INTO public.employee_work_calendars VALUES(tid,eid,bid,0) ON CONFLICT DO NOTHING;
 SELECT version INTO current_version FROM public.employee_work_calendars WHERE tenant_id=tid AND employee_id=eid AND branch_id=bid FOR UPDATE;
 IF current_version<>expected THEN RAISE EXCEPTION 'Stale work schedule' USING ERRCODE='40001'; END IF;
 IF EXISTS(SELECT 1 FROM public.employee_work_schedules s WHERE s.tenant_id=tid AND s.employee_id=eid AND s.branch_id=bid AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(day_rows) x WHERE (x->>'weekday')::int=s.weekday) AND NOT removals @> to_jsonb(ARRAY[s.weekday])) THEN RAISE EXCEPTION 'Confirm removed days' USING ERRCODE='23505'; END IF;
 DELETE FROM public.employee_work_schedules s WHERE tenant_id=tid AND employee_id=eid AND branch_id=bid AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(day_rows) x WHERE (x->>'weekday')::int=s.weekday);
 FOR r IN SELECT value FROM jsonb_array_elements(day_rows) LOOP
  INSERT INTO public.employee_work_schedules(tenant_id,employee_id,branch_id,weekday,start_time,end_time,receives_patients)
  VALUES(tid,eid,bid,(r->>'weekday')::int,(r->>'start_time')::time,(r->>'end_time')::time,(r->>'receives_patients')::boolean)
  ON CONFLICT(tenant_id,employee_id,branch_id,weekday) DO UPDATE SET start_time=EXCLUDED.start_time,end_time=EXCLUDED.end_time,receives_patients=EXCLUDED.receives_patients;
 END LOOP;
 UPDATE public.employee_work_calendars SET version=version+1 WHERE tenant_id=tid AND employee_id=eid AND branch_id=bid;
 PERFORM governance.event('employee.work_saved','employee',eid,tid);
 result:=governance.employee_work_read(eid,bid);
 INSERT INTO public.access_editor_intents VALUES(tid,public.context_user(),intent,'employee.work',digest,result);
 RETURN result;
END $$;
ALTER FUNCTION governance.employee_work_read(uuid,uuid) OWNER TO blusser_security;
ALTER FUNCTION governance.employee_work_save(uuid,uuid,jsonb,jsonb,bigint,uuid) OWNER TO blusser_security;
REVOKE ALL ON FUNCTION governance.employee_work_read(uuid,uuid),governance.employee_work_save(uuid,uuid,jsonb,jsonb,bigint,uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION governance.employee_work_read(uuid,uuid),governance.employee_work_save(uuid,uuid,jsonb,jsonb,bigint,uuid) TO blusser_app;
