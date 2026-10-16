CREATE TABLE patients (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
 patient_number varchar(32) NOT NULL, name varchar(255) NOT NULL CHECK (length(btrim(name))>0),
 phone varchar(32) CHECK (phone IS NULL OR phone ~ '^\+?[0-9]{3,20}$'),
 date_of_birth date NOT NULL,
 gender varchar(16) CHECK (gender IN ('female','male','unspecified')), archived_at timestamptz,
 version bigint NOT NULL DEFAULT 1 CHECK (version>0),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,patient_number)
);
CREATE INDEX patients_tenant_created ON patients(tenant_id,created_at,id);
CREATE TABLE patient_branches (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
 patient_id uuid NOT NULL, branch_id uuid NOT NULL, linked_by uuid NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(tenant_id,id), UNIQUE(tenant_id,patient_id,branch_id),
 FOREIGN KEY(tenant_id,patient_id) REFERENCES patients(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,branch_id) REFERENCES branches(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,linked_by) REFERENCES tenant_memberships(tenant_id,id) ON DELETE RESTRICT
);
CREATE INDEX patient_branches_branch ON patient_branches(tenant_id,branch_id,patient_id);
CREATE TABLE patient_counters (
 tenant_id uuid PRIMARY KEY REFERENCES tenants(id) ON DELETE RESTRICT, value bigint NOT NULL CHECK(value>0)
);
CREATE TABLE patient_idempotency (
 tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, actor_id uuid NOT NULL,
 operation varchar(32) NOT NULL, key uuid NOT NULL, request_digest char(64) NOT NULL,
 patient_id uuid NOT NULL, expires_at timestamptz NOT NULL DEFAULT now()+interval '24 hours',
 PRIMARY KEY(tenant_id,actor_id,operation,key),
 FOREIGN KEY(tenant_id,actor_id) REFERENCES tenant_memberships(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,patient_id) REFERENCES patients(tenant_id,id) ON DELETE RESTRICT
);
GRANT SELECT,INSERT,UPDATE ON patients,patient_branches,patient_counters,patient_idempotency TO blusser_security;

-- Pure boolean projections: no identity or patient data is returned. Own remains
-- false until real doctor profiles and active care relationships are implemented.
CREATE FUNCTION governance.patient_allowed(pid uuid, permission_code text) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT EXISTS(SELECT 1 FROM public.patients p
 WHERE permission_code IN ('patients.view','patients.create','patients.update','patients.link_branch','patients.archive','patients.restore','patients.export')
 AND p.id=pid AND p.tenant_id=public.context_tenant()
 AND public.is_active_member(p.tenant_id)
 AND EXISTS(SELECT 1 FROM public.users u WHERE u.id=public.context_user() AND NOT u.must_change_password)
 AND (public.permission_scope(permission_code)='entire_clinic'
 OR (public.permission_scope(permission_code)='assigned_branches' AND EXISTS(
 SELECT 1 FROM public.patient_branches pb WHERE pb.tenant_id=p.tenant_id AND pb.patient_id=p.id AND public.branch_assigned(pb.branch_id)))))
$$;
CREATE FUNCTION governance.patient_branch_visible(pid uuid,bid uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT governance.patient_allowed(pid,'patients.view') AND
 (public.permission_scope('patients.view')='entire_clinic' OR public.branch_assigned(bid))
$$;
CREATE POLICY patients_read ON patients FOR SELECT TO blusser_app USING(governance.patient_allowed(id,'patients.view'));
CREATE POLICY patient_branches_read ON patient_branches FOR SELECT TO blusser_app
 USING(tenant_id=public.context_tenant() AND governance.patient_branch_visible(patient_id,branch_id));
GRANT SELECT ON patients,patient_branches TO blusser_app;

CREATE FUNCTION governance.patient_projection(pid uuid) RETURNS jsonb
LANGUAGE sql SET search_path=pg_catalog AS $$
 SELECT jsonb_build_object('id',p.id,'patient_number',p.patient_number,'name',p.name,'phone',p.phone,
 'date_of_birth',p.date_of_birth,'gender',p.gender,'archived_at',to_char(p.archived_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
 'branch_ids',coalesce((SELECT jsonb_agg(pb.branch_id ORDER BY pb.branch_id) FROM public.patient_branches pb
 WHERE pb.tenant_id=p.tenant_id AND pb.patient_id=p.id AND
 (public.permission_scope('patients.view')='entire_clinic' OR public.branch_assigned(pb.branch_id))),'[]'::jsonb))
 FROM public.patients p WHERE p.id=pid AND p.tenant_id=public.context_tenant()
$$;

CREATE FUNCTION governance.patient_command(action_code text,pid uuid,payload jsonb,request_key uuid) RETURNS jsonb
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
 WHEN 'update' THEN ARRAY['name','phone','date_of_birth','gender']
 WHEN 'link_branch' THEN ARRAY['branch_id'] ELSE ARRAY['expected_version','reason_code'] END))
 THEN RAISE EXCEPTION 'Invalid fields' USING ERRCODE='22023'; END IF;
 IF EXISTS(SELECT 1 FROM jsonb_each(payload) e WHERE
 (e.key IN ('name','date_of_birth','branch_id','reason_code') AND jsonb_typeof(e.value)<>'string')
 OR (e.key IN ('phone','gender') AND jsonb_typeof(e.value) NOT IN ('string','null'))
 OR (e.key='expected_version' AND (jsonb_typeof(e.value)<>'number' OR e.value::text !~ '^[1-9][0-9]*$')))
 THEN RAISE EXCEPTION 'Invalid field type' USING ERRCODE='22023'; END IF;
 IF payload?'date_of_birth' AND (payload->>'date_of_birth')::date>current_date THEN RAISE EXCEPTION 'Invalid birth date' USING ERRCODE='22023'; END IF;
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
   IF payload='{}'::jsonb THEN RAISE EXCEPTION 'Empty changes' USING ERRCODE='22023'; END IF;
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
GRANT DELETE ON patient_idempotency TO blusser_security;

CREATE FUNCTION governance.patient_export_audit() RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN
 PERFORM governance.lock_users(ARRAY[public.context_user()]); PERFORM governance.actor();
 PERFORM id FROM public.tenants WHERE id=public.context_tenant() AND status='active' FOR UPDATE;
 IF NOT public.is_active_member(public.context_tenant()) OR public.permission_scope('patients.export') IS DISTINCT FROM 'entire_clinic'
 OR public.permission_scope('patients.view') IS DISTINCT FROM 'entire_clinic' THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 PERFORM governance.event('patient.export','patient',NULL,public.context_tenant());
END $$;
