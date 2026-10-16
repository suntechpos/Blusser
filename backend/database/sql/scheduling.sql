GRANT SELECT ON services TO blusser_security;
-- Scheduling writes are serialized by the same user -> tenant protocol as governance.
CREATE FUNCTION governance.valid_iana_timezone(value text) RETURNS boolean
LANGUAGE sql STABLE SET search_path=pg_catalog AS $$
 SELECT value='UTC' OR (value LIKE '%/%' AND EXISTS(SELECT 1 FROM pg_catalog.pg_timezone_names WHERE name=value))
$$;
ALTER TABLE tenants ADD COLUMN timezone text NOT NULL DEFAULT 'Asia/Tashkent' CHECK(governance.valid_iana_timezone(timezone));
ALTER TABLE branches ADD COLUMN timezone text CHECK(timezone IS NULL OR governance.valid_iana_timezone(timezone));
CREATE TABLE specialties (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), tenant_id uuid NOT NULL REFERENCES tenants(id),
 code varchar(64) NOT NULL CHECK(length(btrim(code))>0), name varchar(255) NOT NULL CHECK(length(btrim(name))>0), active boolean NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), UNIQUE(tenant_id,id), UNIQUE(tenant_id,code)
);
CREATE TABLE doctor_profiles (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), tenant_id uuid NOT NULL REFERENCES tenants(id), membership_id uuid NOT NULL, specialty_id uuid NOT NULL, active boolean NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), UNIQUE(tenant_id,id), UNIQUE(tenant_id,membership_id),
 FOREIGN KEY(tenant_id,membership_id) REFERENCES tenant_memberships(tenant_id,id), FOREIGN KEY(tenant_id,specialty_id) REFERENCES specialties(tenant_id,id)
);
CREATE TABLE doctor_services (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 tenant_id uuid NOT NULL REFERENCES tenants(id), doctor_id uuid NOT NULL, service_id uuid NOT NULL,
 -- Stage-1 payload sets duration on schedules. No invented per-service duration default.
 duration_minutes integer CHECK(duration_minutes>0 AND duration_minutes<=480),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,doctor_id,service_id), FOREIGN KEY(tenant_id,doctor_id) REFERENCES doctor_profiles(tenant_id,id), FOREIGN KEY(tenant_id,service_id) REFERENCES services(tenant_id,id)
);
CREATE TABLE doctor_branches (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 tenant_id uuid NOT NULL REFERENCES tenants(id), doctor_id uuid NOT NULL, branch_id uuid NOT NULL,
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,doctor_id,branch_id), FOREIGN KEY(tenant_id,doctor_id) REFERENCES doctor_profiles(tenant_id,id), FOREIGN KEY(tenant_id,branch_id) REFERENCES branches(tenant_id,id)
);
CREATE TABLE rooms (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), tenant_id uuid NOT NULL REFERENCES tenants(id), branch_id uuid NOT NULL,
 number varchar(255) NOT NULL CHECK(length(btrim(number))>0), service_type varchar(255) NOT NULL CHECK(length(btrim(service_type))>0), active boolean NOT NULL,
 availability text NOT NULL CHECK(availability IN ('available','unavailable')),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), UNIQUE(tenant_id,id), UNIQUE(tenant_id,branch_id,id), UNIQUE(tenant_id,branch_id,number),
 FOREIGN KEY(tenant_id,branch_id) REFERENCES branches(tenant_id,id)
);
CREATE TABLE schedule_rules (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), tenant_id uuid NOT NULL REFERENCES tenants(id), doctor_id uuid NOT NULL, branch_id uuid NOT NULL, room_id uuid NOT NULL,
 weekday integer NOT NULL CHECK(weekday BETWEEN 1 AND 7), local_start time NOT NULL, local_end time NOT NULL CHECK(local_end>local_start),
 slot_minutes integer NOT NULL CHECK(slot_minutes BETWEEN 1 AND 480), effective_from date NOT NULL, effective_to date CHECK(effective_to>=effective_from),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), UNIQUE(tenant_id,id),
 CHECK(extract(second FROM local_start)=0 AND extract(second FROM local_end)=0),
 FOREIGN KEY(tenant_id,doctor_id,branch_id) REFERENCES doctor_branches(tenant_id,doctor_id,branch_id), FOREIGN KEY(tenant_id,branch_id,room_id) REFERENCES rooms(tenant_id,branch_id,id)
);
CREATE TABLE schedule_breaks (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), tenant_id uuid NOT NULL REFERENCES tenants(id), schedule_rule_id uuid NOT NULL,
 local_start time NOT NULL, local_end time NOT NULL CHECK(local_end>local_start),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,schedule_rule_id) REFERENCES schedule_rules(tenant_id,id)
);
CREATE TABLE schedule_exceptions (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), tenant_id uuid NOT NULL REFERENCES tenants(id), doctor_id uuid NOT NULL, branch_id uuid NOT NULL, local_date date NOT NULL,
 kind text NOT NULL CHECK(kind IN ('closed','replace')), room_id uuid, local_start time, local_end time, slot_minutes integer,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), UNIQUE(tenant_id,id), UNIQUE(tenant_id,doctor_id,branch_id,local_date),
 CHECK((kind='closed' AND room_id IS NULL AND local_start IS NULL AND local_end IS NULL AND slot_minutes IS NULL) OR
 (kind='replace' AND room_id IS NOT NULL AND local_start IS NOT NULL AND local_end IS NOT NULL AND local_end>local_start AND slot_minutes IS NOT NULL AND slot_minutes BETWEEN 1 AND 480)),
 FOREIGN KEY(tenant_id,doctor_id,branch_id) REFERENCES doctor_branches(tenant_id,doctor_id,branch_id), FOREIGN KEY(tenant_id,branch_id,room_id) REFERENCES rooms(tenant_id,branch_id,id)
);
CREATE TABLE schedule_exception_breaks (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), tenant_id uuid NOT NULL REFERENCES tenants(id), schedule_exception_id uuid NOT NULL,
 local_start time NOT NULL, local_end time NOT NULL CHECK(local_end>local_start),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,schedule_exception_id) REFERENCES schedule_exceptions(tenant_id,id)
);
CREATE TABLE scheduling_idempotency (
 tenant_id uuid NOT NULL REFERENCES tenants(id), actor_id uuid NOT NULL, operation text NOT NULL, key uuid NOT NULL,
 request_digest char(64) NOT NULL, resource_id uuid NOT NULL, specialty_id uuid, doctor_id uuid, room_id uuid, schedule_rule_id uuid, schedule_exception_id uuid, expires_at timestamptz NOT NULL DEFAULT now()+interval '24 hours',
 PRIMARY KEY(tenant_id,actor_id,operation,key), FOREIGN KEY(tenant_id,actor_id) REFERENCES tenant_memberships(tenant_id,id),
 CHECK(num_nonnulls(specialty_id,doctor_id,room_id,schedule_rule_id,schedule_exception_id)=1 AND resource_id=coalesce(specialty_id,doctor_id,room_id,schedule_rule_id,schedule_exception_id)),
 FOREIGN KEY(tenant_id,specialty_id) REFERENCES specialties(tenant_id,id), FOREIGN KEY(tenant_id,doctor_id) REFERENCES doctor_profiles(tenant_id,id),
 FOREIGN KEY(tenant_id,room_id) REFERENCES rooms(tenant_id,id), FOREIGN KEY(tenant_id,schedule_rule_id) REFERENCES schedule_rules(tenant_id,id),
 FOREIGN KEY(tenant_id,schedule_exception_id) REFERENCES schedule_exceptions(tenant_id,id)
);
CREATE INDEX scheduling_rules_lookup ON schedule_rules(tenant_id,doctor_id,branch_id,weekday,effective_from);
CREATE INDEX scheduling_rules_room ON schedule_rules(tenant_id,room_id,weekday);
CREATE INDEX scheduling_exceptions_lookup ON schedule_exceptions(tenant_id,doctor_id,local_date);

CREATE FUNCTION governance.scheduling_identity() RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT public.is_active_member(public.context_tenant()) AND EXISTS(SELECT 1 FROM public.users u WHERE u.id=public.context_user()
 AND u.active AND NOT u.is_platform_admin AND NOT u.must_change_password
 AND u.credential_version=nullif(current_setting('app.credential_version',true),'')::bigint)
$$;
CREATE FUNCTION governance.doctor_branch_live(did uuid,bid uuid) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT EXISTS(SELECT 1 FROM public.doctor_profiles d
 JOIN public.specialties s ON (s.tenant_id,s.id)=(d.tenant_id,d.specialty_id) AND s.active
 JOIN public.tenant_memberships m ON (m.tenant_id,m.id)=(d.tenant_id,d.membership_id) AND m.active
 JOIN public.users u ON u.id=m.user_id AND u.active AND NOT u.is_platform_admin AND NOT u.must_change_password
 JOIN public.tenant_roles r ON (r.tenant_id,r.id)=(m.tenant_id,m.role_id) AND r.active
 JOIN public.doctor_branches db ON (db.tenant_id,db.doctor_id)=(d.tenant_id,d.id) AND db.branch_id=bid
 JOIN public.membership_branches mb ON (mb.tenant_id,mb.membership_id,mb.branch_id)=(d.tenant_id,m.id,bid)
 JOIN public.branches b ON (b.tenant_id,b.id)=(d.tenant_id,bid) AND b.active
 WHERE d.tenant_id=public.context_tenant() AND d.id=did AND d.active)
$$;
CREATE FUNCTION governance.scheduling_branch(permission_code text,bid uuid) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT governance.scheduling_identity() AND permission_code IN ('doctors.view','rooms.view','schedules.view') AND
 (public.permission_scope(permission_code)='entire_clinic' OR
 (public.permission_scope(permission_code)='assigned_branches' AND public.branch_assigned(bid)))
$$;
CREATE FUNCTION governance.scheduling_visible(kind text,rid uuid) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT governance.scheduling_identity() AND CASE kind
 WHEN 'doctors' THEN EXISTS(SELECT 1 FROM public.doctor_profiles d WHERE d.tenant_id=public.context_tenant() AND d.id=rid AND
 (public.permission_scope('doctors.view')='entire_clinic' OR EXISTS(SELECT 1 FROM public.doctor_branches db WHERE (db.tenant_id,db.doctor_id)=(d.tenant_id,d.id)
 AND governance.doctor_branch_live(d.id,db.branch_id) AND governance.scheduling_branch('doctors.view',db.branch_id))))
 WHEN 'rooms' THEN EXISTS(SELECT 1 FROM public.rooms r WHERE r.tenant_id=public.context_tenant() AND r.id=rid AND governance.scheduling_branch('rooms.view',r.branch_id))
 WHEN 'schedules' THEN EXISTS(SELECT 1 FROM public.schedule_rules r WHERE r.tenant_id=public.context_tenant() AND r.id=rid AND governance.scheduling_branch('schedules.view',r.branch_id)
 AND (public.permission_scope('schedules.view')='entire_clinic' OR governance.doctor_branch_live(r.doctor_id,r.branch_id)))
 WHEN 'schedule-exceptions' THEN EXISTS(SELECT 1 FROM public.schedule_exceptions r WHERE r.tenant_id=public.context_tenant() AND r.id=rid AND governance.scheduling_branch('schedules.view',r.branch_id)
 AND (public.permission_scope('schedules.view')='entire_clinic' OR governance.doctor_branch_live(r.doctor_id,r.branch_id)))
 WHEN 'specialties' THEN EXISTS(SELECT 1 FROM public.specialties s WHERE s.tenant_id=public.context_tenant() AND s.id=rid AND
 (public.permission_scope('doctors.view')='entire_clinic' OR EXISTS(SELECT 1 FROM public.doctor_profiles d WHERE d.tenant_id=s.tenant_id AND d.specialty_id=s.id AND governance.scheduling_visible('doctors',d.id))))
 ELSE false END
$$;
CREATE FUNCTION governance.scheduling_doctor_branch_visible(did uuid,bid uuid) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT governance.scheduling_visible('doctors',did) AND EXISTS(SELECT 1 FROM public.doctor_branches db
 WHERE (db.tenant_id,db.doctor_id,db.branch_id)=(public.context_tenant(),did,bid)) AND
 (public.permission_scope('doctors.view')='entire_clinic' OR governance.scheduling_branch('doctors.view',bid) AND governance.doctor_branch_live(did,bid))
$$;
-- Private validation helpers are callable only inside the audited command boundary.
CREATE FUNCTION governance.scheduling_breaks_valid(breaks jsonb,lo time,hi time) RETURNS boolean LANGUAGE sql IMMUTABLE SET search_path=pg_catalog AS $$
 SELECT jsonb_typeof(breaks)='array' AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(breaks) x
 WHERE (x->>'local_start')::time<lo OR (x->>'local_end')::time>hi OR (x->>'local_start')::time>=(x->>'local_end')::time)
 AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(breaks) WITH ORDINALITY a(x,i), jsonb_array_elements(breaks) WITH ORDINALITY b(x,i)
 WHERE a.i<b.i AND (a.x->>'local_start')::time<(b.x->>'local_end')::time AND (b.x->>'local_start')::time<(a.x->>'local_end')::time)
$$;
CREATE FUNCTION governance.scheduling_interval_conflict(v jsonb,excluded uuid,exception_date date DEFAULT NULL) RETURNS boolean
LANGUAGE plpgsql STABLE SET search_path=pg_catalog AS $$
DECLARE r record; first_day date; last_day date; zone text; other_zone text; wd int; tid uuid:=public.context_tenant();
BEGIN
 SELECT coalesce(b.timezone,t.timezone) INTO zone FROM public.branches b JOIN public.tenants t ON t.id=b.tenant_id WHERE (b.tenant_id,b.id)=(tid,(v->>'branch_id')::uuid);
 wd:=(v->>'weekday')::int;
 FOR r IN SELECT sr.* FROM public.schedule_rules sr WHERE sr.tenant_id=tid AND sr.id IS DISTINCT FROM excluded
 AND (sr.doctor_id=(v->>'doctor_id')::uuid OR sr.room_id=(v->>'room_id')::uuid) LOOP
  SELECT coalesce(b.timezone,t.timezone) INTO other_zone FROM public.branches b JOIN public.tenants t ON t.id=b.tenant_id WHERE (b.tenant_id,b.id)=(tid,r.branch_id);
  first_day:=greatest((v->>'effective_from')::date,r.effective_from-2);
  last_day:=least(coalesce((v->>'effective_to')::date,'9999-12-30'::date),coalesce(r.effective_to+2,'9999-12-30'::date));
  IF exception_date IS NOT NULL THEN first_day:=exception_date; last_day:=exception_date; END IF;
  IF first_day>last_day THEN CONTINUE; END IF;
  -- Fast rejection for disjoint wall-time windows within a shared timezone.
  IF zone=other_zone AND (wd<>r.weekday OR (v->>'local_start')::time>=r.local_end OR (v->>'local_end')::time<=r.local_start) THEN CONTINUE; END IF;
  first_day:=first_day+((wd-extract(isodow FROM first_day)::int+7)%7);
  IF first_day>last_day THEN CONTINUE; END IF;
  IF EXISTS(
   SELECT 1 FROM generate_series(0,(last_day-first_day)/7) n
   CROSS JOIN LATERAL (SELECT first_day+n*7 AS day) a
   CROSS JOIN LATERAL (SELECT (a.day+(v->>'local_start')::time) AT TIME ZONE zone AS lo,(a.day+(v->>'local_end')::time) AT TIME ZONE zone AS hi) x
   CROSS JOIN generate_series(-2,2) shift
   CROSS JOIN LATERAL (SELECT (x.lo AT TIME ZONE other_zone)::date+shift AS day) b
   WHERE extract(isodow FROM b.day)=r.weekday AND b.day>=r.effective_from AND (r.effective_to IS NULL OR b.day<=r.effective_to)
   AND x.lo<(b.day+r.local_end) AT TIME ZONE other_zone AND x.hi>(b.day+r.local_start) AT TIME ZONE other_zone
   AND NOT EXISTS(SELECT 1 FROM public.schedule_exceptions e WHERE (e.tenant_id,e.doctor_id,e.branch_id,e.local_date)=(tid,r.doctor_id,r.branch_id,b.day))
   AND (exception_date IS NOT NULL AND (r.doctor_id,r.branch_id) IS DISTINCT FROM ((v->>'doctor_id')::uuid,(v->>'branch_id')::uuid)
    OR exception_date IS NULL AND NOT EXISTS(SELECT 1 FROM public.schedule_exceptions e WHERE (e.tenant_id,e.doctor_id,e.branch_id,e.local_date)=(tid,(v->>'doctor_id')::uuid,(v->>'branch_id')::uuid,a.day)))
  ) THEN RETURN true; END IF;
 END LOOP;
 -- Replacement exceptions are actual dated windows and also occupy doctor/room time.
 FOR r IN SELECT e.*,coalesce(b.timezone,t.timezone) AS zone FROM public.schedule_exceptions e
 JOIN public.branches b ON (b.tenant_id,b.id)=(e.tenant_id,e.branch_id) JOIN public.tenants t ON t.id=e.tenant_id
 WHERE e.tenant_id=tid AND e.kind='replace' AND e.id IS DISTINCT FROM excluded
 AND (e.doctor_id=(v->>'doctor_id')::uuid OR e.room_id=(v->>'room_id')::uuid) LOOP
  IF exception_date IS NOT NULL AND (r.doctor_id,r.branch_id,r.local_date)=((v->>'doctor_id')::uuid,(v->>'branch_id')::uuid,exception_date) THEN CONTINUE; END IF;
  IF EXISTS(SELECT 1 FROM generate_series(-2,2) shift
   CROSS JOIN LATERAL (SELECT (((r.local_date+r.local_start) AT TIME ZONE r.zone) AT TIME ZONE zone)::date+shift AS day) d
   WHERE extract(isodow FROM d.day)=wd AND d.day>=(v->>'effective_from')::date AND (v->>'effective_to' IS NULL OR d.day<=(v->>'effective_to')::date)
   AND (exception_date IS NULL OR d.day=exception_date)
   AND (d.day+(v->>'local_start')::time) AT TIME ZONE zone<(r.local_date+r.local_end) AT TIME ZONE r.zone
   AND (d.day+(v->>'local_end')::time) AT TIME ZONE zone>(r.local_date+r.local_start) AT TIME ZONE r.zone
   AND (exception_date IS NOT NULL OR NOT EXISTS(SELECT 1 FROM public.schedule_exceptions e WHERE (e.tenant_id,e.doctor_id,e.branch_id,e.local_date)=(tid,(v->>'doctor_id')::uuid,(v->>'branch_id')::uuid,d.day)))
  ) THEN RETURN true; END IF;
 END LOOP;
 RETURN false;
END $$;
CREATE FUNCTION governance.scheduling_projection(kind text,rid uuid) RETURNS jsonb LANGUAGE sql STABLE SET search_path=pg_catalog AS $$
 SELECT CASE kind
 WHEN 'specialties' THEN (SELECT jsonb_build_object('id',s.id,'name',s.name) FROM public.specialties s WHERE s.tenant_id=public.context_tenant() AND s.id=rid)
 WHEN 'doctors' THEN (SELECT jsonb_build_object('id',d.id,'membership_id',d.membership_id,'specialty_id',d.specialty_id,'active',d.active,
 'branch_ids',coalesce((SELECT jsonb_agg(db.branch_id ORDER BY db.branch_id) FROM public.doctor_branches db WHERE (db.tenant_id,db.doctor_id)=(d.tenant_id,d.id)
 AND governance.scheduling_doctor_branch_visible(d.id,db.branch_id)),'[]'::jsonb),
 'service_ids',coalesce((SELECT jsonb_agg(ds.service_id ORDER BY ds.service_id) FROM public.doctor_services ds WHERE (ds.tenant_id,ds.doctor_id)=(d.tenant_id,d.id)),'[]'::jsonb)) FROM public.doctor_profiles d WHERE d.tenant_id=public.context_tenant() AND d.id=rid)
 WHEN 'rooms' THEN (SELECT jsonb_build_object('id',r.id,'branch_id',r.branch_id,'number',r.number,'service_type',r.service_type,'active',r.active,'availability',r.availability) FROM public.rooms r WHERE r.tenant_id=public.context_tenant() AND r.id=rid)
 WHEN 'schedules' THEN (SELECT jsonb_build_object('id',r.id,'doctor_id',r.doctor_id,'branch_id',r.branch_id,'room_id',r.room_id,'weekday',r.weekday,
 'local_start',to_char(r.local_start,'HH24:MI'),'local_end',to_char(r.local_end,'HH24:MI'),'slot_minutes',r.slot_minutes,'effective_from',r.effective_from,'effective_to',r.effective_to,
 'breaks',coalesce((SELECT jsonb_agg(jsonb_build_object('local_start',to_char(b.local_start,'HH24:MI'),'local_end',to_char(b.local_end,'HH24:MI')) ORDER BY b.local_start) FROM public.schedule_breaks b WHERE (b.tenant_id,b.schedule_rule_id)=(r.tenant_id,r.id)),'[]'::jsonb)) FROM public.schedule_rules r WHERE r.tenant_id=public.context_tenant() AND r.id=rid)
 WHEN 'schedule-exceptions' THEN (SELECT jsonb_build_object('id',e.id,'doctor_id',e.doctor_id,'branch_id',e.branch_id,'local_date',e.local_date,'kind',e.kind,
 'replacement',CASE WHEN e.kind='replace' THEN jsonb_build_object('doctor_id',e.doctor_id,'branch_id',e.branch_id,'room_id',e.room_id,'weekday',extract(isodow FROM e.local_date)::int,
 'local_start',to_char(e.local_start,'HH24:MI'),'local_end',to_char(e.local_end,'HH24:MI'),'slot_minutes',e.slot_minutes,'effective_from',e.local_date,'effective_to',e.local_date,
 'breaks',coalesce((SELECT jsonb_agg(jsonb_build_object('local_start',to_char(b.local_start,'HH24:MI'),'local_end',to_char(b.local_end,'HH24:MI')) ORDER BY b.local_start) FROM public.schedule_exception_breaks b WHERE (b.tenant_id,b.schedule_exception_id)=(e.tenant_id,e.id)),'[]'::jsonb)) ELSE NULL END)
 FROM public.schedule_exceptions e WHERE e.tenant_id=public.context_tenant() AND e.id=rid) END
$$;
CREATE TABLE scheduling_changes (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), tenant_id uuid NOT NULL REFERENCES tenants(id), actor_id uuid NOT NULL,
 schedule_rule_id uuid, schedule_exception_id uuid, previous jsonb NOT NULL, created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id), CHECK(num_nonnulls(schedule_rule_id,schedule_exception_id)=1),
 FOREIGN KEY(tenant_id,actor_id) REFERENCES tenant_memberships(tenant_id,id), FOREIGN KEY(tenant_id,schedule_rule_id) REFERENCES schedule_rules(tenant_id,id),
 FOREIGN KEY(tenant_id,schedule_exception_id) REFERENCES schedule_exceptions(tenant_id,id)
);
CREATE FUNCTION governance.scheduling_validate_payload(kind text,p jsonb) RETURNS void LANGUAGE plpgsql SET search_path=pg_catalog AS $$
DECLARE fields text[]; required_fields text[]; item jsonb; k text;
BEGIN
 fields:=CASE kind WHEN 'specialties' THEN ARRAY['code','name','active'] WHEN 'doctors' THEN ARRAY['membership_id','specialty_id','branch_ids','service_ids','active']
 WHEN 'rooms' THEN ARRAY['branch_id','number','service_type','active','availability'] WHEN 'schedules' THEN ARRAY['doctor_id','branch_id','room_id','weekday','local_start','local_end','slot_minutes','effective_from','effective_to','breaks']
 WHEN 'schedule-exceptions' THEN ARRAY['doctor_id','branch_id','local_date','kind','replacement'] ELSE NULL END;
 required_fields:=CASE WHEN kind='schedules' THEN array_remove(fields,'effective_to') ELSE fields END;
 IF fields IS NULL OR jsonb_typeof(p) IS DISTINCT FROM 'object' OR NOT(p?&required_fields) OR EXISTS(SELECT 1 FROM jsonb_object_keys(p) x WHERE NOT x=ANY(fields))
 THEN RAISE EXCEPTION 'Invalid fields' USING ERRCODE='22023'; END IF;
 FOR k,item IN SELECT key,value FROM jsonb_each(p) LOOP
  IF k='active' THEN
   IF jsonb_typeof(item)<>'boolean' THEN RAISE EXCEPTION 'Invalid boolean' USING ERRCODE='22023'; END IF;
  ELSIF k IN ('weekday','slot_minutes') THEN
   IF jsonb_typeof(item)<>'number' OR item::text !~ '^[0-9]+$' OR (k='weekday' AND (item::text)::int NOT BETWEEN 1 AND 7) OR (k='slot_minutes' AND (item::text)::int NOT BETWEEN 1 AND 480)
   THEN RAISE EXCEPTION 'Invalid integer' USING ERRCODE='22023'; END IF;
  ELSIF k IN ('branch_ids','service_ids') THEN
   IF jsonb_typeof(item)<>'array' OR jsonb_array_length(item) NOT BETWEEN 1 AND 100 OR EXISTS(SELECT 1 FROM jsonb_array_elements(item) x WHERE jsonb_typeof(x)<>'string' OR (x#>>'{}') !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$')
   OR (SELECT count(DISTINCT (x#>>'{}')::uuid) FROM jsonb_array_elements(item) x)<>jsonb_array_length(item) THEN RAISE EXCEPTION 'Invalid assignments' USING ERRCODE='22023'; END IF;
  ELSIF k='breaks' THEN
   IF jsonb_typeof(item)<>'array' OR jsonb_array_length(item)>100 OR EXISTS(SELECT 1 FROM jsonb_array_elements(item) x WHERE jsonb_typeof(x)<>'object' OR NOT(x?&ARRAY['local_start','local_end']) OR x-ARRAY['local_start','local_end']<>'{}'::jsonb
   OR jsonb_typeof(x->'local_start')<>'string' OR jsonb_typeof(x->'local_end')<>'string' OR x->>'local_start' !~ '^([01][0-9]|2[0-3]):[0-5][0-9]$' OR x->>'local_end' !~ '^([01][0-9]|2[0-3]):[0-5][0-9]$') THEN RAISE EXCEPTION 'Invalid breaks' USING ERRCODE='22023'; END IF;
  ELSIF k='replacement' THEN NULL;
  ELSIF k='effective_to' AND item='null'::jsonb THEN NULL;
  ELSE
   IF jsonb_typeof(item)<>'string' OR length(btrim(p->>k))=0 OR length(p->>k)>255 THEN RAISE EXCEPTION 'Invalid string' USING ERRCODE='22023'; END IF;
   IF k IN ('membership_id','specialty_id','doctor_id','branch_id','room_id') AND p->>k !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' THEN RAISE EXCEPTION 'Invalid UUID' USING ERRCODE='22023'; END IF;
   IF k IN ('local_start','local_end') AND p->>k !~ '^([01][0-9]|2[0-3]):[0-5][0-9]$' THEN RAISE EXCEPTION 'Invalid time' USING ERRCODE='22023'; END IF;
   IF k IN ('effective_from','effective_to','local_date') AND (p->>k !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' OR (p->>k)::date NOT BETWEEN '0001-01-02'::date AND '9999-12-29'::date) THEN RAISE EXCEPTION 'Invalid date' USING ERRCODE='22023'; END IF;
  END IF;
 END LOOP;
 IF kind='specialties' AND length(p->>'code')>64 THEN RAISE EXCEPTION 'Invalid code' USING ERRCODE='22023'; END IF;
 IF kind='rooms' AND p->>'availability' NOT IN ('available','unavailable') THEN RAISE EXCEPTION 'Invalid availability' USING ERRCODE='22023'; END IF;
 IF kind='schedules' AND ((p->>'local_end')::time<=(p->>'local_start')::time OR (p->>'effective_to')::date<(p->>'effective_from')::date
 OR NOT governance.scheduling_breaks_valid(p->'breaks',(p->>'local_start')::time,(p->>'local_end')::time)) THEN RAISE EXCEPTION 'Invalid schedule interval' USING ERRCODE='22023'; END IF;
 IF kind='schedule-exceptions' THEN
  IF p->>'kind' NOT IN ('closed','replace') OR (p->>'kind'='closed' AND p->'replacement'<>'null'::jsonb) OR (p->>'kind'='replace' AND jsonb_typeof(p->'replacement')<>'object') THEN RAISE EXCEPTION 'Invalid exception' USING ERRCODE='22023'; END IF;
  IF p->>'kind'='replace' THEN
   PERFORM governance.scheduling_validate_payload('schedules',p->'replacement');
   IF p->'replacement'->>'doctor_id'<>p->>'doctor_id' OR p->'replacement'->>'branch_id'<>p->>'branch_id'
    OR (p->'replacement'->>'weekday')::int<>extract(isodow FROM (p->>'local_date')::date)
    OR (p->'replacement'->>'effective_from')::date>(p->>'local_date')::date
    OR (p->'replacement'->>'effective_to')::date<(p->>'local_date')::date THEN RAISE EXCEPTION 'Mismatched replacement' USING ERRCODE='22023'; END IF;
  END IF;
 END IF;
END $$;
CREATE FUNCTION governance.scheduling_command(kind text,action_code text,rid uuid,payload jsonb,request_key uuid) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE tid uuid:=public.context_tenant(); aid uuid; permission_code text; section text; result_id uuid; saved public.scheduling_idempotency;
 digest_value text; val jsonb; bid uuid; did uuid; target_user uuid; previous jsonb; item jsonb;
BEGIN
 IF kind NOT IN ('specialties','doctors','rooms','schedules','schedule-exceptions') OR action_code NOT IN ('create','update') OR request_key IS NULL
 OR (action_code='create' AND rid IS NOT NULL) OR (action_code='update' AND rid IS NULL) THEN RAISE EXCEPTION 'Invalid command' USING ERRCODE='22023'; END IF;
 -- Resolve only tenant-local target identity, then lock all users in UUID order before tenant.
 IF kind='doctors' THEN
  SELECT m.user_id INTO target_user FROM public.tenant_memberships m WHERE m.tenant_id=tid AND m.id=(payload->>'membership_id')::uuid;
 ELSIF kind IN ('schedules','schedule-exceptions') THEN
  SELECT m.user_id INTO target_user FROM public.doctor_profiles d JOIN public.tenant_memberships m ON (m.tenant_id,m.id)=(d.tenant_id,d.membership_id) WHERE d.tenant_id=tid AND d.id=(payload->>'doctor_id')::uuid;
 END IF;
 PERFORM governance.lock_users(ARRAY[public.context_user(),target_user]); PERFORM governance.actor();
 PERFORM id FROM public.tenants WHERE id=tid AND status='active' FOR UPDATE;
 IF NOT FOUND OR NOT governance.scheduling_identity() THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 section:=CASE WHEN kind IN ('specialties','doctors') THEN 'doctors' WHEN kind='rooms' THEN 'rooms' ELSE 'schedules' END;
 permission_code:=section||'.'||action_code;
 IF public.permission_scope(permission_code) IS DISTINCT FROM 'entire_clinic' OR public.permission_scope(section||'.view') IS DISTINCT FROM 'entire_clinic' THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 SELECT id INTO aid FROM public.tenant_memberships WHERE tenant_id=tid AND user_id=public.context_user() AND active;
 IF action_code='update' AND NOT governance.scheduling_visible(kind,rid) THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
 PERFORM governance.scheduling_validate_payload(kind,payload);
 digest_value:=encode(sha256(convert_to(jsonb_build_object('id',rid,'payload',payload)::text,'UTF8')),'hex');
 SELECT * INTO saved FROM public.scheduling_idempotency WHERE tenant_id=tid AND actor_id=aid AND operation=kind||'.'||action_code AND key=request_key;
 IF FOUND AND saved.expires_at>now() THEN
  IF saved.request_digest<>digest_value THEN RAISE EXCEPTION 'Conflict' USING ERRCODE='23505'; END IF;
  IF NOT governance.scheduling_visible(kind,saved.resource_id) THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
  RETURN governance.scheduling_projection(kind,saved.resource_id);
 END IF;
 result_id:=coalesce(rid,gen_random_uuid());
 previous:=CASE WHEN action_code='update' THEN governance.scheduling_projection(kind,rid) ELSE NULL END;
 IF kind='specialties' THEN
  IF action_code='create' THEN INSERT INTO public.specialties(id,tenant_id,code,name,active) VALUES(result_id,tid,payload->>'code',payload->>'name',(payload->>'active')::boolean);
  ELSE UPDATE public.specialties SET code=payload->>'code',name=payload->>'name',active=(payload->>'active')::boolean,updated_at=now() WHERE (tenant_id,id)=(tid,rid); END IF;
 ELSIF kind='doctors' THEN
  IF NOT EXISTS(SELECT 1 FROM public.tenant_memberships m JOIN public.users u ON u.id=m.user_id AND u.active AND NOT u.is_platform_admin AND NOT u.must_change_password
   JOIN public.tenant_roles r ON (r.tenant_id,r.id)=(m.tenant_id,m.role_id) AND r.active WHERE m.tenant_id=tid AND m.id=(payload->>'membership_id')::uuid AND m.active)
  OR NOT EXISTS(SELECT 1 FROM public.specialties WHERE (tenant_id,id)=(tid,(payload->>'specialty_id')::uuid) AND active)
  OR EXISTS(SELECT 1 FROM jsonb_array_elements_text(payload->'service_ids') s WHERE NOT EXISTS(SELECT 1 FROM public.services WHERE (tenant_id,id)=(tid,s::uuid) AND active))
  OR EXISTS(SELECT 1 FROM jsonb_array_elements_text(payload->'branch_ids') b WHERE NOT EXISTS(SELECT 1 FROM public.membership_branches mb JOIN public.branches br ON (br.tenant_id,br.id)=(mb.tenant_id,mb.branch_id) AND br.active
   WHERE (mb.tenant_id,mb.membership_id,mb.branch_id)=(tid,(payload->>'membership_id')::uuid,b::uuid))) THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
  IF action_code='update' AND previous->>'membership_id'<>payload->>'membership_id' THEN RAISE EXCEPTION 'Immutable membership' USING ERRCODE='23505'; END IF;
  IF action_code='create' THEN INSERT INTO public.doctor_profiles(id,tenant_id,membership_id,specialty_id,active) VALUES(result_id,tid,(payload->>'membership_id')::uuid,(payload->>'specialty_id')::uuid,(payload->>'active')::boolean);
  ELSE UPDATE public.doctor_profiles SET specialty_id=(payload->>'specialty_id')::uuid,active=(payload->>'active')::boolean,updated_at=now() WHERE (tenant_id,id)=(tid,rid); END IF;
  DELETE FROM public.doctor_branches WHERE tenant_id=tid AND doctor_id=result_id AND NOT branch_id IN (SELECT value::uuid FROM jsonb_array_elements_text(payload->'branch_ids'));
  INSERT INTO public.doctor_branches(tenant_id,doctor_id,branch_id) SELECT tid,result_id,value::uuid FROM jsonb_array_elements_text(payload->'branch_ids') ON CONFLICT DO NOTHING;
  DELETE FROM public.doctor_services WHERE tenant_id=tid AND doctor_id=result_id AND NOT service_id IN (SELECT value::uuid FROM jsonb_array_elements_text(payload->'service_ids'));
  INSERT INTO public.doctor_services(tenant_id,doctor_id,service_id) SELECT tid,result_id,value::uuid FROM jsonb_array_elements_text(payload->'service_ids') ON CONFLICT DO NOTHING;
 ELSIF kind='rooms' THEN
  bid:=(payload->>'branch_id')::uuid;
  IF NOT EXISTS(SELECT 1 FROM public.branches WHERE (tenant_id,id)=(tid,bid) AND active) THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
  IF action_code='update' THEN
   IF previous->>'branch_id'<>payload->>'branch_id' THEN RAISE EXCEPTION 'Immutable branch' USING ERRCODE='23505'; END IF;
   IF ((previous->'active',previous->'availability',previous->'service_type') IS DISTINCT FROM (payload->'active',payload->'availability',payload->'service_type')) AND
    (EXISTS(SELECT 1 FROM public.schedule_rules WHERE tenant_id=tid AND room_id=rid AND (effective_to IS NULL OR effective_to>=current_date)) OR
    EXISTS(SELECT 1 FROM public.schedule_exceptions WHERE tenant_id=tid AND room_id=rid AND local_date>=current_date)) THEN RAISE EXCEPTION 'Schedule conflict' USING ERRCODE='23505'; END IF;
   UPDATE public.rooms SET number=payload->>'number',service_type=payload->>'service_type',active=(payload->>'active')::boolean,availability=payload->>'availability',updated_at=now() WHERE (tenant_id,id)=(tid,rid);
  ELSE INSERT INTO public.rooms(id,tenant_id,branch_id,number,service_type,active,availability) VALUES(result_id,tid,bid,payload->>'number',payload->>'service_type',(payload->>'active')::boolean,payload->>'availability'); END IF;
 ELSE
  bid:=(payload->>'branch_id')::uuid; did:=(payload->>'doctor_id')::uuid;
  IF NOT governance.doctor_branch_live(did,bid) THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
  val:=CASE WHEN kind='schedules' THEN payload ELSE payload->'replacement' END;
  IF kind='schedules' OR payload->>'kind'='replace' THEN
   PERFORM id FROM public.rooms WHERE (tenant_id,id)=(tid,(val->>'room_id')::uuid) AND branch_id=bid AND active AND availability='available' FOR UPDATE;
   IF NOT FOUND THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
   IF governance.scheduling_interval_conflict(val,rid,CASE WHEN kind='schedule-exceptions' THEN (payload->>'local_date')::date ELSE NULL END) THEN RAISE EXCEPTION 'Schedule conflict' USING ERRCODE='23505'; END IF;
  END IF;
  IF kind='schedules' THEN
   IF action_code='update' AND (previous->>'doctor_id'<>payload->>'doctor_id' OR previous->>'branch_id'<>payload->>'branch_id') THEN RAISE EXCEPTION 'Immutable schedule identity' USING ERRCODE='23505'; END IF;
   IF action_code='create' THEN INSERT INTO public.schedule_rules(id,tenant_id,doctor_id,branch_id,room_id,weekday,local_start,local_end,slot_minutes,effective_from,effective_to)
    VALUES(result_id,tid,did,bid,(val->>'room_id')::uuid,(val->>'weekday')::int,(val->>'local_start')::time,(val->>'local_end')::time,(val->>'slot_minutes')::int,(val->>'effective_from')::date,(val->>'effective_to')::date);
   ELSE
    INSERT INTO public.scheduling_changes(tenant_id,actor_id,schedule_rule_id,previous) VALUES(tid,aid,rid,previous);
    UPDATE public.schedule_rules SET room_id=(val->>'room_id')::uuid,weekday=(val->>'weekday')::int,local_start=(val->>'local_start')::time,local_end=(val->>'local_end')::time,slot_minutes=(val->>'slot_minutes')::int,effective_from=(val->>'effective_from')::date,effective_to=(val->>'effective_to')::date,updated_at=now() WHERE (tenant_id,id)=(tid,rid);
    DELETE FROM public.schedule_breaks WHERE tenant_id=tid AND schedule_rule_id=rid;
   END IF;
   INSERT INTO public.schedule_breaks(tenant_id,schedule_rule_id,local_start,local_end) SELECT tid,result_id,(x->>'local_start')::time,(x->>'local_end')::time FROM jsonb_array_elements(val->'breaks') x;
  ELSE
   IF action_code='update' AND (previous->>'doctor_id',previous->>'branch_id',previous->>'local_date') IS DISTINCT FROM (payload->>'doctor_id',payload->>'branch_id',payload->>'local_date') THEN RAISE EXCEPTION 'Immutable exception identity' USING ERRCODE='23505'; END IF;
   IF action_code='create' THEN INSERT INTO public.schedule_exceptions(id,tenant_id,doctor_id,branch_id,local_date,kind,room_id,local_start,local_end,slot_minutes)
    VALUES(result_id,tid,did,bid,(payload->>'local_date')::date,payload->>'kind',(val->>'room_id')::uuid,(val->>'local_start')::time,(val->>'local_end')::time,(val->>'slot_minutes')::int);
   ELSE
    INSERT INTO public.scheduling_changes(tenant_id,actor_id,schedule_exception_id,previous) VALUES(tid,aid,rid,previous);
    UPDATE public.schedule_exceptions SET kind=payload->>'kind',room_id=(val->>'room_id')::uuid,local_start=(val->>'local_start')::time,local_end=(val->>'local_end')::time,slot_minutes=(val->>'slot_minutes')::int,updated_at=now() WHERE (tenant_id,id)=(tid,rid);
    DELETE FROM public.schedule_exception_breaks WHERE tenant_id=tid AND schedule_exception_id=rid;
   END IF;
   IF payload->>'kind'='replace' THEN INSERT INTO public.schedule_exception_breaks(tenant_id,schedule_exception_id,local_start,local_end) SELECT tid,result_id,(x->>'local_start')::time,(x->>'local_end')::time FROM jsonb_array_elements(val->'breaks') x; END IF;
  END IF;
 END IF;
 DELETE FROM public.scheduling_idempotency WHERE tenant_id=tid AND actor_id=aid AND operation=kind||'.'||action_code AND key=request_key;
 INSERT INTO public.scheduling_idempotency(tenant_id,actor_id,operation,key,request_digest,resource_id,specialty_id,doctor_id,room_id,schedule_rule_id,schedule_exception_id)
 VALUES(tid,aid,kind||'.'||action_code,request_key,digest_value,result_id,
 CASE WHEN kind='specialties' THEN result_id END,CASE WHEN kind='doctors' THEN result_id END,CASE WHEN kind='rooms' THEN result_id END,
 CASE WHEN kind='schedules' THEN result_id END,CASE WHEN kind='schedule-exceptions' THEN result_id END);
 PERFORM governance.event(section||'.'||action_code,kind,result_id,tid);
 RETURN governance.scheduling_projection(kind,result_id);
END $$;
CREATE FUNCTION governance.scheduling_availability_context(did uuid,bid uuid,sid uuid) RETURNS jsonb
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT jsonb_build_object('timezone',coalesce(b.timezone,t.timezone),'duration_minutes',ds.duration_minutes)
 FROM public.doctor_profiles d JOIN public.doctor_services ds ON (ds.tenant_id,ds.doctor_id,ds.service_id)=(d.tenant_id,d.id,sid)
 JOIN public.services s ON (s.tenant_id,s.id)=(d.tenant_id,sid) AND s.active
 JOIN public.branches b ON (b.tenant_id,b.id)=(d.tenant_id,bid) AND b.active JOIN public.tenants t ON t.id=d.tenant_id
 WHERE d.tenant_id=public.context_tenant() AND d.id=did AND governance.doctor_branch_live(did,bid)
 AND governance.scheduling_branch('doctors.view',bid) AND governance.scheduling_branch('rooms.view',bid) AND governance.scheduling_branch('schedules.view',bid)
$$;
