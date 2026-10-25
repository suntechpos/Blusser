-- Additive catalog metadata over the existing appointment service identities.
CREATE TABLE public.service_categories (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), tenant_id uuid NOT NULL REFERENCES public.tenants(id),
 name varchar(255) NOT NULL CHECK(length(btrim(name))>0), description text NOT NULL DEFAULT '',
 color text NOT NULL CHECK(color IN ('blue','cyan','green','purple','orange','pink','slate')), active boolean NOT NULL,
 version bigint NOT NULL DEFAULT 1, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), UNIQUE(tenant_id,id)
);
CREATE UNIQUE INDEX service_category_names ON public.service_categories(tenant_id,lower(regexp_replace(btrim(name),'\s+',' ','g')));
ALTER TABLE public.services ADD COLUMN category_id uuid, ADD COLUMN service_type text NOT NULL DEFAULT 'other' CHECK(service_type IN ('consultation','laboratory','diagnostic','procedure','other')),
 ADD COLUMN price numeric(14,2) CHECK(price>=0), ADD COLUMN timed boolean NOT NULL DEFAULT false,
 ADD COLUMN duration_minutes integer CHECK(duration_minutes BETWEEN 1 AND 480), ADD COLUMN turnaround numeric(14,2) CHECK(turnaround>0),
 ADD COLUMN turnaround_unit text NOT NULL DEFAULT 'hour' CHECK(turnaround_unit IN ('hour','day')), ADD COLUMN version bigint NOT NULL DEFAULT 1,
 ADD FOREIGN KEY(tenant_id,category_id) REFERENCES public.service_categories(tenant_id,id),
 ADD CONSTRAINT services_timed_duration CHECK((timed AND duration_minutes IS NOT NULL) OR (NOT timed AND duration_minutes IS NULL));
CREATE TABLE public.service_branches (
 tenant_id uuid NOT NULL, service_id uuid NOT NULL, branch_id uuid NOT NULL,
 PRIMARY KEY(tenant_id,service_id,branch_id), FOREIGN KEY(tenant_id,service_id) REFERENCES public.services(tenant_id,id), FOREIGN KEY(tenant_id,branch_id) REFERENCES public.branches(tenant_id,id)
);
CREATE INDEX service_branches_lookup ON public.service_branches(tenant_id,branch_id,service_id);
CREATE TABLE public.service_catalog_counters(tenant_id uuid PRIMARY KEY REFERENCES public.tenants(id), next_number bigint NOT NULL DEFAULT 0 CHECK(next_number>=0));
ALTER TABLE public.service_categories ENABLE ROW LEVEL SECURITY; ALTER TABLE public.service_categories FORCE ROW LEVEL SECURITY;
ALTER TABLE public.service_branches ENABLE ROW LEVEL SECURITY; ALTER TABLE public.service_branches FORCE ROW LEVEL SECURITY;
ALTER TABLE public.service_catalog_counters ENABLE ROW LEVEL SECURITY; ALTER TABLE public.service_catalog_counters FORCE ROW LEVEL SECURITY;
CREATE POLICY catalog_owner ON public.service_categories TO blusser_owner USING(true) WITH CHECK(true);
CREATE POLICY catalog_security ON public.service_categories TO blusser_security USING(true) WITH CHECK(true);
CREATE POLICY catalog_read ON public.service_categories FOR SELECT TO blusser_app USING(tenant_id=public.context_tenant() AND public.permission_scope('services.view')='entire_clinic');
CREATE POLICY catalog_owner ON public.service_branches TO blusser_owner USING(true) WITH CHECK(true);
CREATE POLICY catalog_security ON public.service_branches TO blusser_security USING(true) WITH CHECK(true);
CREATE POLICY catalog_read ON public.service_branches FOR SELECT TO blusser_app USING(tenant_id=public.context_tenant() AND public.permission_scope('services.view')='entire_clinic');
CREATE POLICY catalog_owner ON public.service_catalog_counters TO blusser_owner USING(true) WITH CHECK(true);
CREATE POLICY catalog_security ON public.service_catalog_counters TO blusser_security USING(true) WITH CHECK(true);
GRANT SELECT ON public.service_categories,public.service_branches TO blusser_app;
GRANT SELECT,INSERT,UPDATE,DELETE ON public.service_categories,public.service_branches,public.service_catalog_counters TO blusser_security;
GRANT UPDATE ON public.services TO blusser_security;
CREATE FUNCTION governance.catalog_service_available(sid uuid,bid uuid) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT EXISTS(SELECT 1 FROM public.services s WHERE (s.tenant_id,s.id)=(public.context_tenant(),sid) AND s.active
 AND (NOT EXISTS(SELECT 1 FROM public.service_branches sb WHERE (sb.tenant_id,sb.service_id)=(s.tenant_id,s.id))
 OR EXISTS(SELECT 1 FROM public.service_branches sb WHERE (sb.tenant_id,sb.service_id,sb.branch_id)=(s.tenant_id,s.id,bid))))
$$;
CREATE FUNCTION governance.catalog_projection(kind text,rid uuid) RETURNS jsonb LANGUAGE sql STABLE SET search_path=pg_catalog AS $$
 SELECT CASE kind WHEN 'services' THEN (SELECT jsonb_build_object('id',s.id,'code',s.code,'name',s.name,'category_id',s.category_id,'service_type',s.service_type,
 'price',s.price::text,'timed',s.timed,'duration_minutes',s.duration_minutes,'turnaround',s.turnaround::text,'turnaround_unit',s.turnaround_unit,'description',coalesce(s.description,''),'active',s.active,'version',s.version,
 'branch_ids',coalesce((SELECT jsonb_agg(sb.branch_id ORDER BY sb.branch_id) FROM public.service_branches sb WHERE (sb.tenant_id,sb.service_id)=(s.tenant_id,s.id)),'[]'::jsonb))
 FROM public.services s WHERE (s.tenant_id,s.id)=(public.context_tenant(),rid))
 WHEN 'categories' THEN (SELECT jsonb_build_object('id',c.id,'name',c.name,'description',c.description,'color',c.color,'active',c.active,'version',c.version,
 'service_count',(SELECT count(*) FROM public.services s WHERE (s.tenant_id,s.category_id)=(c.tenant_id,c.id))) FROM public.service_categories c WHERE (c.tenant_id,c.id)=(public.context_tenant(),rid)) END
$$;
CREATE FUNCTION governance.catalog_lookups() RETURNS jsonb LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN
 IF NOT governance.scheduling_identity() OR public.permission_scope('services.view') IS DISTINCT FROM 'entire_clinic' THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 RETURN jsonb_build_object('categories',coalesce((SELECT jsonb_agg(governance.catalog_projection('categories',c.id) ORDER BY c.created_at,c.id) FROM public.service_categories c WHERE c.tenant_id=public.context_tenant()),'[]'::jsonb),
 'branches',coalesce((SELECT jsonb_agg(jsonb_build_object('id',b.id,'name',b.name,'active',b.active) ORDER BY b.created_at,b.id) FROM public.branches b WHERE b.tenant_id=public.context_tenant()),'[]'::jsonb));
END $$;
CREATE FUNCTION governance.catalog_command(kind text,operation text,rid uuid,payload jsonb,expected bigint,intent uuid) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE tid uuid:=public.context_tenant(); previous jsonb; result jsonb; saved public.access_editor_intents; digest text; result_id uuid:=coalesce(rid,gen_random_uuid()); code_value text; seq bigint; p record; fields text[]; item jsonb; version_value bigint; permission_code text;
BEGIN
 IF kind NOT IN ('services','categories') OR operation NOT IN ('create','update','status') OR intent IS NULL OR jsonb_typeof(payload) IS DISTINCT FROM 'object'
 OR (operation='create' AND rid IS NOT NULL) OR (operation<>'create' AND (rid IS NULL OR expected IS NULL)) THEN RAISE EXCEPTION 'Invalid command' USING ERRCODE='22023'; END IF;
 PERFORM governance.lock_users(ARRAY[public.context_user()]); PERFORM governance.actor();
 PERFORM id FROM public.tenants WHERE id=tid AND status='active' FOR UPDATE;
 IF NOT FOUND OR NOT governance.scheduling_identity() OR public.permission_scope('services.view') IS DISTINCT FROM 'entire_clinic' THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 permission_code:=kind||'.'||CASE WHEN operation='status' AND kind='services' THEN 'delete' ELSE operation END;
 IF public.permission_scope(permission_code) IS DISTINCT FROM 'entire_clinic' OR (kind='categories' AND public.permission_scope('categories.view') IS DISTINCT FROM 'entire_clinic') THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 IF operation<>'create' THEN
  previous:=governance.catalog_projection(kind,rid); IF previous IS NULL THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
 END IF;
 digest:=encode(sha256(convert_to(jsonb_build_object('kind',kind,'op',operation,'id',rid,'payload',payload,'expected',expected)::text,'UTF8')),'hex');
 SELECT * INTO saved FROM public.access_editor_intents WHERE tenant_id=tid AND actor_id=public.context_user() AND key=intent;
 IF FOUND THEN
  IF saved.operation<>'catalog.'||kind||'.'||operation OR saved.payload_hash<>digest THEN RAISE EXCEPTION 'Intent conflict' USING ERRCODE='23505'; END IF;
  IF coalesce((saved.result->>'_catalog_requires_status')::boolean,false) AND public.permission_scope(kind||'.'||CASE WHEN kind='services' THEN 'delete' ELSE 'status' END) IS DISTINCT FROM 'entire_clinic' THEN RAISE EXCEPTION 'Status denied' USING ERRCODE='42501'; END IF;
  RETURN saved.result-'_catalog_requires_status';
 END IF;
 IF operation<>'create' AND (previous->>'version')::bigint<>expected THEN RAISE EXCEPTION 'Stale version' USING ERRCODE='40001'; END IF;
 fields:=CASE WHEN operation='status' THEN ARRAY['active'] WHEN kind='categories' THEN ARRAY['name','description','color','active'] ELSE ARRAY['name','category_id','service_type','branch_ids','price','timed','duration_minutes','turnaround','turnaround_unit','description','active'] END;
 IF (SELECT array_agg(key ORDER BY key) FROM jsonb_object_keys(payload) key) IS DISTINCT FROM (SELECT array_agg(x ORDER BY x) FROM unnest(fields) x) THEN RAISE EXCEPTION 'Invalid fields' USING ERRCODE='22023'; END IF;
 FOR p IN SELECT key,value FROM jsonb_each(payload) LOOP
  IF p.key='active' OR p.key='timed' THEN
   IF jsonb_typeof(p.value) IS DISTINCT FROM 'boolean' THEN RAISE EXCEPTION 'Invalid boolean' USING ERRCODE='22023'; END IF;
  ELSIF p.key='branch_ids' THEN
   IF jsonb_typeof(p.value) IS DISTINCT FROM 'array' OR jsonb_array_length(p.value) NOT BETWEEN 1 AND 100 THEN RAISE EXCEPTION 'Invalid branches' USING ERRCODE='22023'; END IF;
   IF (SELECT count(*) FROM jsonb_array_elements(p.value))<>(SELECT count(DISTINCT value) FROM jsonb_array_elements(p.value)) THEN RAISE EXCEPTION 'Duplicate branches' USING ERRCODE='22023'; END IF;
   FOR item IN SELECT value FROM jsonb_array_elements(p.value) LOOP
    IF jsonb_typeof(item) IS DISTINCT FROM 'string' OR NOT EXISTS(SELECT 1 FROM public.branches b WHERE (b.tenant_id,b.id)=(tid,(item#>>'{}')::uuid) AND b.active) THEN RAISE EXCEPTION 'Unavailable branch' USING ERRCODE='P0002'; END IF;
   END LOOP;
  ELSIF p.key='duration_minutes' THEN
   IF p.value<>'null'::jsonb AND (jsonb_typeof(p.value) IS DISTINCT FROM 'number' OR (p.value#>>'{}') !~ '^[0-9]+$' OR (p.value#>>'{}')::int NOT BETWEEN 1 AND 480) THEN RAISE EXCEPTION 'Invalid duration' USING ERRCODE='22023'; END IF;
  ELSIF p.key='category_id' THEN
   IF jsonb_typeof(p.value) IS DISTINCT FROM 'string' OR NOT EXISTS(SELECT 1 FROM public.service_categories c WHERE (c.tenant_id,c.id)=(tid,(p.value#>>'{}')::uuid) AND (c.active OR previous->>'category_id'=(p.value#>>'{}'))) THEN RAISE EXCEPTION 'Unavailable category' USING ERRCODE='P0002'; END IF;
  ELSIF p.key IN ('price','turnaround') THEN
   IF NOT (p.key='turnaround' AND p.value='null'::jsonb) AND (jsonb_typeof(p.value) IS DISTINCT FROM 'string' OR (p.value#>>'{}') !~ '^(0|[1-9][0-9]{0,11})(\.[0-9]{1,2})?$' OR (p.key='turnaround' AND (p.value#>>'{}')::numeric<=0)) THEN RAISE EXCEPTION 'Invalid decimal' USING ERRCODE='22023'; END IF;
  ELSE
   IF jsonb_typeof(p.value) IS DISTINCT FROM 'string' THEN RAISE EXCEPTION 'Invalid string' USING ERRCODE='22023'; END IF;
   IF p.key='name' AND (length(btrim(p.value#>>'{}'))=0 OR length(p.value#>>'{}')>255) OR p.key='description' AND length(p.value#>>'{}')>5000
   OR p.key='color' AND NOT (p.value#>>'{}')=ANY(ARRAY['blue','cyan','green','purple','orange','pink','slate'])
   OR p.key='service_type' AND NOT (p.value#>>'{}')=ANY(ARRAY['consultation','laboratory','diagnostic','procedure','other'])
   OR p.key='turnaround_unit' AND NOT (p.value#>>'{}')=ANY(ARRAY['hour','day']) THEN RAISE EXCEPTION 'Invalid value' USING ERRCODE='22023'; END IF;
  END IF;
 END LOOP;
 IF operation='update' AND previous->'active' IS DISTINCT FROM payload->'active' AND public.permission_scope(kind||'.'||CASE WHEN kind='services' THEN 'delete' ELSE 'status' END) IS DISTINCT FROM 'entire_clinic' THEN RAISE EXCEPTION 'Status denied' USING ERRCODE='42501'; END IF;
 IF kind='services' AND operation<>'status' AND ((payload->>'timed')::boolean IS DISTINCT FROM (payload->'duration_minutes'<>'null'::jsonb)) THEN RAISE EXCEPTION 'Duration required only for timed services' USING ERRCODE='22023'; END IF;
 IF kind='services' AND operation<>'status' AND ((payload->>'service_type'='consultation' AND NOT (payload->>'timed')::boolean) OR (payload->>'service_type'='laboratory' AND payload->'turnaround'='null'::jsonb)) THEN RAISE EXCEPTION 'Consultation duration or laboratory result time required' USING ERRCODE='22023'; END IF;
 IF kind='services' AND operation='update' AND previous->'duration_minutes' IS DISTINCT FROM payload->'duration_minutes' AND (EXISTS(SELECT 1 FROM public.appointments a WHERE (a.tenant_id,a.service_id)=(tid,rid)) OR EXISTS(SELECT 1 FROM public.visits v WHERE (v.tenant_id,v.service_id)=(tid,rid))) THEN RAISE EXCEPTION 'Booked service duration is immutable' USING ERRCODE='23505'; END IF;
 IF operation='status' THEN
  IF kind='services' THEN UPDATE public.services SET active=(payload->>'active')::boolean,version=version+1,updated_at=now() WHERE (tenant_id,id)=(tid,rid);
  ELSE UPDATE public.service_categories SET active=(payload->>'active')::boolean,version=version+1,updated_at=now() WHERE (tenant_id,id)=(tid,rid); END IF;
 ELSIF kind='categories' THEN
  IF operation='create' THEN INSERT INTO public.service_categories(id,tenant_id,name,description,color,active) VALUES(result_id,tid,btrim(payload->>'name'),payload->>'description',payload->>'color',(payload->>'active')::boolean);
  ELSE UPDATE public.service_categories SET name=btrim(payload->>'name'),description=payload->>'description',color=payload->>'color',active=(payload->>'active')::boolean,version=version+1,updated_at=now() WHERE (tenant_id,id)=(tid,rid); END IF;
 ELSE
  IF operation='create' THEN
   INSERT INTO public.service_catalog_counters(tenant_id) VALUES(tid) ON CONFLICT DO NOTHING;
   LOOP
    UPDATE public.service_catalog_counters SET next_number=next_number+1 WHERE tenant_id=tid RETURNING next_number INTO seq;
    code_value:='XZ-'||lpad(seq::text,greatest(3,length(seq::text)),'0');
    EXIT WHEN NOT EXISTS(SELECT 1 FROM public.services WHERE tenant_id=tid AND code=code_value);
   END LOOP;
   INSERT INTO public.services(id,tenant_id,code,name,category_id,service_type,price,timed,duration_minutes,turnaround,turnaround_unit,description,active)
   VALUES(result_id,tid,code_value,btrim(payload->>'name'),(payload->>'category_id')::uuid,payload->>'service_type',(payload->>'price')::numeric,(payload->>'timed')::boolean,(payload->>'duration_minutes')::int,(payload->>'turnaround')::numeric,payload->>'turnaround_unit',payload->>'description',(payload->>'active')::boolean);
  ELSE UPDATE public.services SET name=btrim(payload->>'name'),category_id=(payload->>'category_id')::uuid,service_type=payload->>'service_type',price=(payload->>'price')::numeric,timed=(payload->>'timed')::boolean,duration_minutes=(payload->>'duration_minutes')::int,turnaround=(payload->>'turnaround')::numeric,turnaround_unit=payload->>'turnaround_unit',description=payload->>'description',active=(payload->>'active')::boolean,version=version+1,updated_at=now() WHERE (tenant_id,id)=(tid,rid);
  END IF;
  DELETE FROM public.service_branches WHERE tenant_id=tid AND service_id=result_id;
  INSERT INTO public.service_branches(tenant_id,service_id,branch_id) SELECT tid,result_id,value::uuid FROM jsonb_array_elements_text(payload->'branch_ids');
 END IF;
 result:=governance.catalog_projection(kind,result_id);
 PERFORM governance.event('catalog.'||kind||'.'||operation,kind,result_id,tid);
 INSERT INTO public.access_editor_intents VALUES(tid,public.context_user(),intent,'catalog.'||kind||'.'||operation,digest,result||jsonb_build_object('_catalog_requires_status',operation='update' AND previous->'active' IS DISTINCT FROM payload->'active'));
 RETURN result;
END $$;
CREATE FUNCTION governance.catalog_export_audit() RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN
 PERFORM governance.lock_users(ARRAY[public.context_user()]); PERFORM governance.actor();
 PERFORM id FROM public.tenants WHERE id=public.context_tenant() AND status='active' FOR UPDATE;
 IF NOT FOUND OR NOT governance.scheduling_identity() OR public.permission_scope('services.view') IS DISTINCT FROM 'entire_clinic' OR public.permission_scope('categories.view') IS DISTINCT FROM 'entire_clinic' OR public.permission_scope('categories.export') IS DISTINCT FROM 'entire_clinic' THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 PERFORM governance.event('catalog.export','services',NULL,public.context_tenant());
END $$;
ALTER FUNCTION governance.catalog_service_available(uuid,uuid) OWNER TO blusser_security;
ALTER FUNCTION governance.catalog_lookups() OWNER TO blusser_security;
ALTER FUNCTION governance.catalog_command(text,text,uuid,jsonb,bigint,uuid) OWNER TO blusser_security;
ALTER FUNCTION governance.catalog_export_audit() OWNER TO blusser_security;
REVOKE ALL ON FUNCTION governance.catalog_service_available(uuid,uuid),governance.catalog_projection(text,uuid),governance.catalog_lookups(),governance.catalog_command(text,text,uuid,jsonb,bigint,uuid),governance.catalog_export_audit() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION governance.catalog_projection(text,uuid),governance.catalog_lookups(),governance.catalog_command(text,text,uuid,jsonb,bigint,uuid),governance.catalog_export_audit() TO blusser_app;
GRANT EXECUTE ON FUNCTION governance.catalog_projection(text,uuid),governance.catalog_lookups() TO blusser_security;
