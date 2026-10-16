-- Preserve authorization and replay checks before optimistic version validation.
CREATE OR REPLACE FUNCTION governance.patient_command(action_code text,pid uuid,payload jsonb,request_key uuid) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE aid uuid; tid uuid:=public.context_tenant(); permission_code text; scope_value text;
 bid uuid; result_id uuid; saved public.patient_idempotency; p public.patients; number_value bigint; digest_value text;
BEGIN
 IF action_code NOT IN ('create','update','link_branch','archive','restore') OR request_key IS NULL
 OR payload IS NULL OR jsonb_typeof(payload)<>'object' THEN RAISE EXCEPTION 'Invalid request' USING ERRCODE='22023'; END IF;
 PERFORM governance.lock_users(ARRAY[public.context_user()]); PERFORM governance.actor();
 PERFORM id FROM public.tenants WHERE id=tid AND status='active' FOR UPDATE;
 SELECT id INTO aid FROM public.tenant_memberships WHERE tenant_id=tid AND user_id=public.context_user() AND active;
 IF aid IS NULL OR NOT public.is_active_member(tid) THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 permission_code:='patients.'||action_code; scope_value:=public.permission_scope(permission_code);
 IF scope_value IS NULL OR (action_code IN ('archive','restore') AND scope_value<>'entire_clinic') THEN
 RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 IF EXISTS(SELECT 1 FROM jsonb_object_keys(payload) k WHERE NOT k=ANY(CASE action_code
 WHEN 'create' THEN ARRAY['name','phone','date_of_birth','gender','branch_id']
 WHEN 'update' THEN ARRAY['name','phone','date_of_birth','gender','expected_version']
 WHEN 'link_branch' THEN ARRAY['branch_id'] ELSE ARRAY['expected_version','reason_code'] END))
 THEN RAISE EXCEPTION 'Invalid fields' USING ERRCODE='22023'; END IF;
 IF EXISTS(SELECT 1 FROM jsonb_each(payload) e WHERE
 (e.key IN ('name','date_of_birth','branch_id','reason_code') AND jsonb_typeof(e.value)<>'string')
 OR (e.key IN ('phone','gender') AND jsonb_typeof(e.value) NOT IN ('string','null'))
 OR (e.key='expected_version' AND (jsonb_typeof(e.value)<>'number' OR e.value::text !~ '^[1-9][0-9]*$')))
 THEN RAISE EXCEPTION 'Invalid field type' USING ERRCODE='22023'; END IF;
 IF payload?'date_of_birth' AND (payload->>'date_of_birth')::date>current_date THEN RAISE EXCEPTION 'Invalid birth date' USING ERRCODE='22023'; END IF;
 IF action_code='update' AND NOT payload?'expected_version' THEN RAISE EXCEPTION 'Version required' USING ERRCODE='22023'; END IF;
 IF action_code='create' AND pid IS NOT NULL THEN RAISE EXCEPTION 'Invalid resource' USING ERRCODE='22023'; END IF;
 IF action_code<>'create' THEN
  IF NOT governance.patient_allowed(pid,'patients.view') THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
  IF NOT governance.patient_allowed(pid,permission_code) THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 END IF;
 IF scope_value='own' THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 IF action_code IN ('create','link_branch') THEN
 bid:=(payload->>'branch_id')::uuid;
 IF NOT EXISTS(SELECT 1 FROM public.branches WHERE id=bid AND tenant_id=tid AND active)
 OR (scope_value='assigned_branches' AND NOT public.branch_assigned(bid)) THEN
 RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
 END IF;
 digest_value:=encode(sha256(convert_to(jsonb_build_object('patient_id',pid,'payload',payload)::text,'UTF8')),'hex');
 SELECT * INTO saved FROM public.patient_idempotency WHERE tenant_id=tid AND actor_id=aid AND operation=action_code AND key=request_key;
 IF FOUND AND saved.expires_at>now() THEN
  IF NOT governance.patient_allowed(saved.patient_id,'patients.view') THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
  IF saved.request_digest<>digest_value THEN RAISE EXCEPTION 'Conflict' USING ERRCODE='23505'; END IF;
  IF NOT governance.patient_allowed(saved.patient_id,permission_code) THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
  RETURN jsonb_build_object('patient',governance.patient_projection(saved.patient_id),'version',(SELECT version FROM public.patients WHERE id=saved.patient_id AND tenant_id=tid));
 END IF;
 DELETE FROM public.patient_idempotency WHERE tenant_id=tid AND actor_id=aid AND operation=action_code AND key=request_key;
 IF action_code='create' THEN
  IF NOT (payload ?& ARRAY['name','date_of_birth','branch_id']) THEN RAISE EXCEPTION 'Required fields' USING ERRCODE='22023'; END IF;
  INSERT INTO public.patient_counters(tenant_id,value) VALUES(tid,1) ON CONFLICT(tenant_id) DO UPDATE SET value=public.patient_counters.value+1 RETURNING value INTO number_value;
  INSERT INTO public.patients(tenant_id,patient_number,name,phone,date_of_birth,gender)
  VALUES(tid,'P-'||lpad(number_value::text,greatest(6,length(number_value::text)),'0'),payload->>'name',payload->>'phone',(payload->>'date_of_birth')::date,payload->>'gender') RETURNING id INTO result_id;
  INSERT INTO public.patient_branches(tenant_id,patient_id,branch_id,linked_by) VALUES(tid,result_id,bid,aid);
 ELSE
  SELECT * INTO p FROM public.patients WHERE tenant_id=tid AND id=pid FOR UPDATE; result_id:=pid;
  IF action_code='update' THEN
   IF (payload->>'expected_version')::bigint IS DISTINCT FROM p.version THEN RAISE EXCEPTION 'Version conflict' USING ERRCODE='23505'; END IF;
   IF payload-'expected_version'='{}'::jsonb THEN RAISE EXCEPTION 'Empty changes' USING ERRCODE='22023'; END IF;
   UPDATE public.patients SET name=CASE WHEN payload?'name' THEN payload->>'name' ELSE name END,
    phone=CASE WHEN payload?'phone' THEN payload->>'phone' ELSE phone END,
    date_of_birth=CASE WHEN payload?'date_of_birth' THEN (payload->>'date_of_birth')::date ELSE date_of_birth END,
    gender=CASE WHEN payload?'gender' THEN payload->>'gender' ELSE gender END,version=version+1,updated_at=now() WHERE id=pid AND tenant_id=tid;
  ELSIF action_code='link_branch' THEN
   IF p.archived_at IS NOT NULL THEN RAISE EXCEPTION 'Archived' USING ERRCODE='23505'; END IF;
   INSERT INTO public.patient_branches(tenant_id,patient_id,branch_id,linked_by) VALUES(tid,pid,bid,aid) ON CONFLICT(tenant_id,patient_id,branch_id) DO NOTHING;
   UPDATE public.patients SET version=version+1,updated_at=now() WHERE id=pid AND tenant_id=tid;
  ELSE
   IF (payload->>'expected_version')::bigint IS DISTINCT FROM p.version
   OR (action_code='archive' AND p.archived_at IS NOT NULL) OR (action_code='restore' AND p.archived_at IS NULL)
   THEN RAISE EXCEPTION 'State conflict' USING ERRCODE='23505'; END IF;
   UPDATE public.patients SET archived_at=CASE WHEN action_code='archive' THEN now() ELSE NULL END,version=version+1,updated_at=now() WHERE id=pid AND tenant_id=tid;
  END IF;
 END IF;
 IF NOT governance.patient_allowed(result_id,'patients.view') THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 INSERT INTO public.patient_idempotency(tenant_id,actor_id,operation,key,request_digest,patient_id) VALUES(tid,aid,action_code,request_key,digest_value,result_id);
 PERFORM governance.event('patient.'||action_code,'patient',result_id,tid);
 RETURN jsonb_build_object('patient',governance.patient_projection(result_id),'version',(SELECT version FROM public.patients WHERE id=result_id AND tenant_id=tid));
END $$;
