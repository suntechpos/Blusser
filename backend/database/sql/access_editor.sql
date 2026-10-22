ALTER TABLE tenant_roles ADD COLUMN note text NOT NULL DEFAULT '', ADD COLUMN version bigint NOT NULL DEFAULT 1 CHECK(version>0);
CREATE UNIQUE INDEX role_name_normalized ON tenant_roles(tenant_id,lower(regexp_replace(btrim(name),'\s+',' ','g')));
ALTER TABLE tenant_roles ADD CONSTRAINT role_name_nonempty CHECK(length(btrim(name))>0);
CREATE TABLE employee_profiles(
 id uuid PRIMARY KEY,tenant_id uuid NOT NULL REFERENCES tenants(id),membership_id uuid,pending_link_id uuid,profile jsonb NOT NULL,
 active boolean NOT NULL DEFAULT true,version bigint NOT NULL DEFAULT 1 CHECK(version>0),
 created_at timestamptz NOT NULL DEFAULT now(),updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),UNIQUE(tenant_id,membership_id),FOREIGN KEY(tenant_id,membership_id) REFERENCES tenant_memberships(tenant_id,id),
 FOREIGN KEY(tenant_id,pending_link_id) REFERENCES membership_link_requests(tenant_id,id)
);
CREATE TABLE access_editor_intents(
 tenant_id uuid NOT NULL REFERENCES tenants(id),actor_id uuid NOT NULL REFERENCES users(id),key uuid NOT NULL,operation text NOT NULL,payload_hash text NOT NULL,result jsonb NOT NULL,
 PRIMARY KEY(tenant_id,actor_id,key)
);
ALTER TABLE employee_profiles ENABLE ROW LEVEL SECURITY;ALTER TABLE employee_profiles FORCE ROW LEVEL SECURITY;
ALTER TABLE access_editor_intents ENABLE ROW LEVEL SECURITY;ALTER TABLE access_editor_intents FORCE ROW LEVEL SECURITY;
CREATE POLICY migration_owner ON employee_profiles TO blusser_owner USING(true) WITH CHECK(true);
CREATE POLICY migration_owner ON access_editor_intents TO blusser_owner USING(true) WITH CHECK(true);
GRANT SELECT ON employee_profiles TO blusser_app;
GRANT SELECT,INSERT,UPDATE,DELETE ON employee_profiles,access_editor_intents TO blusser_security;
CREATE POLICY employee_profile_read ON employee_profiles FOR SELECT TO blusser_app USING(tenant_id=public.context_tenant() AND public.is_active_member(tenant_id) AND (public.permission_scope('employees.view')='entire_clinic' OR (membership_id IS NOT NULL AND public.can_view_member(membership_id))));
CREATE FUNCTION governance.access_role_command(operation text,target uuid,body jsonb,intent uuid,expected bigint) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE tid uuid:=public.context_tenant();rid uuid;result jsonb;old public.tenant_roles;g jsonb;v text;parent text;parent_scope text;memo public.access_editor_intents;digest text:=md5(body::text||coalesce(target::text,'')||coalesce(expected::text,''));
BEGIN
 PERFORM governance.lock_users(ARRAY[public.context_user()]);
 PERFORM governance.tenant(CASE operation WHEN 'create' THEN 'roles.create' WHEN 'update' THEN 'roles.update' WHEN 'delete' THEN 'roles.delete' ELSE '' END);
 PERFORM id FROM public.tenants WHERE id=tid FOR UPDATE;
 IF target IS NOT NULL THEN
  PERFORM governance.check_role(target,public.context_user(),tid);
  IF EXISTS(SELECT 1 FROM public.tenant_memberships WHERE tenant_id=tid AND role_id=target AND user_id=public.context_user()) THEN RAISE EXCEPTION 'Own role protected' USING ERRCODE='42501'; END IF;
 END IF;
 SELECT * INTO memo FROM public.access_editor_intents WHERE tenant_id=tid AND actor_id=public.context_user() AND key=intent;
 IF FOUND THEN
  IF memo.operation<>('role.'||operation) OR memo.payload_hash<>digest THEN RAISE EXCEPTION 'Replay conflict' USING ERRCODE='23505'; END IF;
  RETURN memo.result;
 END IF;
 IF target IS NOT NULL THEN
  SELECT * INTO old FROM public.tenant_roles WHERE tenant_id=tid AND id=target FOR UPDATE;
  IF expected IS NULL THEN RAISE EXCEPTION 'Version required' USING ERRCODE='P0003';END IF;
  IF old.version<>expected THEN RAISE EXCEPTION 'Stale version' USING ERRCODE='40001';END IF;
 END IF;
 IF operation='delete' THEN
  PERFORM governance.role_delete(target);result:=jsonb_build_object('id',target);
 ELSE
  IF btrim(body->>'name')='' OR jsonb_array_length(body->'grants')=0 THEN RAISE EXCEPTION 'Name/grants required' USING ERRCODE='22023';END IF;
  FOR g IN SELECT value FROM jsonb_array_elements(body->'grants') LOOP
   v:=split_part(g->>'code','.',1)||'.view';
   IF g->>'code'<>v AND EXISTS(SELECT 1 FROM public.permissions WHERE code=v) THEN
    SELECT x->>'scope' INTO parent_scope FROM jsonb_array_elements(body->'grants') x WHERE x->>'code'=v;
    IF parent_scope IS NULL OR (parent_scope<>'entire_clinic' AND parent_scope<>g->>'scope') THEN RAISE EXCEPTION 'Page view required' USING ERRCODE='22023';END IF;
   END IF;
   parent:=CASE split_part(g->>'code','.',1) WHEN 'categories' THEN 'services.view' WHEN 'reception_actions' THEN 'reception.view' WHEN 'employee_schedule' THEN 'employees.view' WHEN 'departments' THEN 'rooms.view' END;
   IF parent IS NOT NULL AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(body->'grants') x WHERE x->>'code'=parent AND (x->>'scope'='entire_clinic' OR x->>'scope'=g->>'scope')) THEN RAISE EXCEPTION 'Parent view required' USING ERRCODE='22023';END IF;
  END LOOP;
  rid:=governance.role_save(target,body->>'code',regexp_replace(btrim(body->>'name'),'\s+',' ','g'),body->'grants');
  PERFORM governance.assert_branch_delegation('role_save',rid,NULL);
  UPDATE public.tenant_roles SET note=coalesce(body->>'note',''),version=version+CASE WHEN target IS NULL THEN 0 ELSE 1 END WHERE tenant_id=tid AND id=rid RETURNING jsonb_build_object('id',id,'version',version) INTO result;
 END IF;
 INSERT INTO public.access_editor_intents VALUES(tid,public.context_user(),intent,'role.'||operation,digest,result);
 RETURN result;
END $$;

CREATE FUNCTION governance.staff_command(target uuid,body jsonb,intent uuid,expected bigint) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE tid uuid:=public.context_tenant();p public.employee_profiles;m public.tenant_memberships;uid uuid;mid uuid;rid uuid;ids uuid[];result jsonb;memo public.access_editor_intents;digest text:=md5((body-'password_hash')::text||coalesce(target::text,'')||coalesce(expected::text,''));enabled boolean:=(body->>'access')::boolean;status boolean:=(body->>'active')::boolean;pid uuid:=coalesce(target,gen_random_uuid());
BEGIN
 PERFORM governance.lock_users(ARRAY[public.context_user(),(SELECT existing_member.user_id FROM public.tenant_memberships existing_member LEFT JOIN public.employee_profiles existing_profile ON existing_profile.tenant_id=existing_member.tenant_id AND existing_profile.membership_id=existing_member.id WHERE existing_member.tenant_id=tid AND (existing_member.id=target OR existing_profile.id=target))]);
 PERFORM governance.tenant(CASE WHEN target IS NULL THEN 'employees.create' ELSE 'employees.update' END);
 PERFORM id FROM public.tenants WHERE id=tid FOR UPDATE;
 SELECT * INTO memo FROM public.access_editor_intents WHERE tenant_id=tid AND actor_id=public.context_user() AND key=intent;
 IF FOUND THEN IF memo.operation<>'staff.save' OR memo.payload_hash<>digest THEN RAISE EXCEPTION 'Replay conflict' USING ERRCODE='23505';END IF;RETURN memo.result;END IF;
 IF target IS NOT NULL THEN
  SELECT * INTO p FROM public.employee_profiles WHERE tenant_id=tid AND id=target FOR UPDATE;
  IF NOT FOUND THEN
   SELECT * INTO m FROM public.tenant_memberships WHERE tenant_id=tid AND id=target;
   IF m.id IS NULL OR NOT public.can_view_member(m.id) THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='42501';END IF;
   p.id:=target;p.tenant_id:=tid;p.membership_id:=m.id;p.version:=1;
  END IF;
  IF expected IS NULL THEN RAISE EXCEPTION 'Version required' USING ERRCODE='P0003';END IF;
  IF p.version<>expected THEN RAISE EXCEPTION 'Stale version' USING ERRCODE='40001';END IF;
  IF p.membership_id IS NOT NULL THEN
   SELECT * INTO m FROM public.tenant_memberships WHERE tenant_id=tid AND id=p.membership_id;
   PERFORM governance.lock_users(ARRAY[m.user_id]);
   IF m.user_id=public.context_user() OR EXISTS(SELECT 1 FROM public.tenant_ceos WHERE tenant_id=tid AND membership_id=m.id) THEN RAISE EXCEPTION 'Own or CEO member protected' USING ERRCODE='42501';END IF;
  ELSIF public.permission_scope('employees.view') IS DISTINCT FROM 'entire_clinic' THEN RAISE EXCEPTION 'Profile scope denied' USING ERRCODE='42501';END IF;
 END IF;
 IF enabled AND NOT status THEN RAISE EXCEPTION 'Inactive cannot access' USING ERRCODE='22023';END IF;
 SELECT array_agg(value::uuid) INTO ids FROM jsonb_array_elements_text(body->'branch_ids');
 IF NOT EXISTS(SELECT 1 FROM public.branches WHERE tenant_id=tid AND id=(body->>'branch_id')::uuid AND active) THEN RAISE EXCEPTION 'Branch unavailable' USING ERRCODE='42501';END IF;
 IF public.permission_scope(CASE WHEN target IS NULL THEN 'employees.create' ELSE 'employees.update' END) IS DISTINCT FROM 'entire_clinic' THEN RAISE EXCEPTION 'Full employee editor requires entire clinic' USING ERRCODE='42501';END IF;
 IF enabled AND p.membership_id IS NULL AND p.pending_link_id IS NOT NULL AND EXISTS(SELECT 1 FROM public.membership_link_requests WHERE membership_link_requests.id=p.pending_link_id AND membership_link_requests.status='pending' AND membership_link_requests.expires_at>now()) THEN RAISE EXCEPTION 'Invitation pending' USING ERRCODE='23505';END IF;
 mid:=p.membership_id;
 IF enabled THEN
  rid:=(body->>'role_id')::uuid;
  IF mid IS NULL THEN
   IF body->>'password_hash' IS NULL THEN RAISE EXCEPTION 'New password required' USING ERRCODE='22023';END IF;
   mid:=governance.employee_create(body->>'login',body->>'name',body->>'password_hash',rid,ids);
   PERFORM governance.assert_branch_delegation('employee_create',mid,NULL);
  ELSE
   IF EXISTS(SELECT 1 FROM public.users WHERE id=m.user_id AND login<>body->>'login') THEN RAISE EXCEPTION 'Login immutable; use identity linking' USING ERRCODE='22023';END IF;
   PERFORM governance.employee_assign(mid,CASE WHEN rid IS DISTINCT FROM m.role_id THEN rid ELSE NULL END,CASE WHEN (SELECT coalesce(array_agg(branch_id ORDER BY branch_id),'{}'::uuid[]) FROM public.membership_branches WHERE tenant_id=tid AND membership_id=mid) IS DISTINCT FROM (SELECT coalesce(array_agg(x ORDER BY x),'{}'::uuid[]) FROM unnest(ids) x) THEN ids ELSE NULL END,CASE WHEN NOT m.active THEN true ELSE NULL END);PERFORM governance.assert_branch_delegation('employee_assign',mid,NULL);
  END IF;
 ELSIF mid IS NOT NULL THEN
  PERFORM governance.employee_assign(mid,NULL,NULL,false);
 END IF;
 INSERT INTO public.employee_profiles(id,tenant_id,membership_id,profile,active,version)
 VALUES(pid,tid,mid,(body->'profile')||jsonb_build_object('branch',body->>'branch_id'),status,CASE WHEN target IS NULL THEN 1 ELSE p.version+1 END)
 ON CONFLICT(id) DO UPDATE SET membership_id=excluded.membership_id,profile=excluded.profile,active=excluded.active,version=excluded.version,updated_at=now();
 PERFORM governance.event('employee.profile_saved','employee',pid,tid);
 result:=jsonb_build_object('id',pid,'version',CASE WHEN target IS NULL THEN 1 ELSE p.version+1 END);
 INSERT INTO public.access_editor_intents VALUES(tid,public.context_user(),intent,'staff.save',digest,result);RETURN result;
END $$;
CREATE FUNCTION governance.membership_session_revoke() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN
 IF OLD.active AND NOT NEW.active THEN
  UPDATE public.users SET credential_version=credential_version+1 WHERE id=NEW.user_id;
  DELETE FROM public.sessions WHERE user_id=NEW.user_id;
 END IF;RETURN NEW;
END $$;
CREATE TRIGGER membership_session_revoke AFTER UPDATE OF active ON tenant_memberships FOR EACH ROW EXECUTE FUNCTION governance.membership_session_revoke();
CREATE FUNCTION governance.role_assignments(rid uuid) RETURNS bigint LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN PERFORM governance.tenant('roles.view');RETURN (SELECT count(*) FROM public.tenant_memberships WHERE tenant_id=public.context_tenant() AND role_id=rid);END $$;
CREATE FUNCTION governance.staff_directory(target uuid,page_offset integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN
 PERFORM governance.actor();
 IF NOT public.is_active_member(public.context_tenant()) OR public.permission_scope('employees.view') IS NULL THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501';END IF;
 RETURN (SELECT coalesce(jsonb_agg(to_jsonb(x)),'[]'::jsonb) FROM (
 SELECT coalesce(p.id,m.id) id,m.id membership_id,m.user_id,m.role_id,(SELECT name FROM public.tenant_roles WHERE id=m.role_id AND tenant_id=m.tenant_id) role_name,coalesce(p.active,m.active) active,coalesce(m.active,false) access,u.login,u.name,
 coalesce(p.profile,'{}'::jsonb) profile,coalesce(p.version,1) version,
 coalesce((SELECT jsonb_agg(b.branch_id) FROM public.membership_branches b WHERE b.tenant_id=m.tenant_id AND b.membership_id=m.id),'[]'::jsonb) branch_ids
 FROM public.employee_profiles p FULL JOIN public.tenant_memberships m ON m.id=p.membership_id AND m.tenant_id=p.tenant_id LEFT JOIN public.users u ON u.id=m.user_id
 WHERE coalesce(p.tenant_id,m.tenant_id)=public.context_tenant() AND (target IS NULL OR coalesce(p.id,m.id)=target)
 AND (public.permission_scope('employees.view')='entire_clinic' OR (m.id IS NOT NULL AND public.can_view_member(m.id)))
 ORDER BY coalesce(p.id,m.id) LIMIT 50 OFFSET greatest(0,page_offset)) x);
END $$;
CREATE FUNCTION governance.self_membership() RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN
 PERFORM governance.actor();IF NOT public.is_active_member(public.context_tenant()) THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501';END IF;
 RETURN (SELECT jsonb_build_object('membership_id',m.id,'employee_id',coalesce(p.id,m.id),'role',jsonb_build_object('id',r.id,'code',r.code,'name',r.name,'protected',r.protected)) FROM public.tenant_memberships m JOIN public.tenant_roles r ON (r.tenant_id,r.id)=(m.tenant_id,m.role_id) LEFT JOIN public.employee_profiles p ON p.tenant_id=m.tenant_id AND p.membership_id=m.id WHERE m.tenant_id=public.context_tenant() AND m.user_id=public.context_user() AND m.active);
END $$;
CREATE FUNCTION governance.staff_link(target uuid,login_value text,rid uuid,bids uuid[],intent uuid,expected bigint) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE p public.employee_profiles;lid uuid;tid uuid:=public.context_tenant();memo public.access_editor_intents;digest text:=md5(target::text||login_value||rid::text||coalesce(bids::text,'')||coalesce(expected::text,''));result jsonb;
BEGIN
 PERFORM governance.lock_users(ARRAY[public.context_user(),(SELECT id FROM public.users WHERE login=login_value)]);
 PERFORM governance.tenant('employees.create');PERFORM governance.tenant('employees.assign_role');PERFORM governance.tenant('employees.lookup_identity');PERFORM governance.tenant('employees.update');
 PERFORM governance.check_role(rid,public.context_user(),tid);
 SELECT * INTO memo FROM public.access_editor_intents WHERE tenant_id=tid AND actor_id=public.context_user() AND key=intent;
 IF FOUND THEN IF memo.operation<>'staff.link' OR memo.payload_hash<>digest THEN RAISE EXCEPTION 'Replay conflict' USING ERRCODE='23505';END IF;RETURN memo.result;END IF;
 SELECT * INTO p FROM public.employee_profiles WHERE tenant_id=tid AND id=target FOR UPDATE;
 IF NOT FOUND OR NOT p.active OR p.membership_id IS NOT NULL OR public.permission_scope('employees.view') IS DISTINCT FROM 'entire_clinic' THEN RAISE EXCEPTION 'Profile unavailable' USING ERRCODE='42501';END IF;
 IF expected IS NULL THEN RAISE EXCEPTION 'Version required' USING ERRCODE='P0003';END IF;
 IF p.version<>expected THEN RAISE EXCEPTION 'Stale version' USING ERRCODE='40001';END IF;
 IF p.pending_link_id IS NOT NULL AND EXISTS(SELECT 1 FROM public.membership_link_requests WHERE membership_link_requests.id=p.pending_link_id AND membership_link_requests.status='pending' AND membership_link_requests.expires_at>now()) THEN RAISE EXCEPTION 'Invitation pending' USING ERRCODE='23505';END IF;
 lid:=governance.link_request(login_value,rid,bids);PERFORM governance.assert_branch_delegation('link_request',lid,NULL);
 UPDATE public.employee_profiles SET pending_link_id=lid,version=version+1,updated_at=now() WHERE tenant_id=tid AND id=target;
 result:=jsonb_build_object('id',lid,'employee_id',target,'version',p.version+1);
 INSERT INTO public.access_editor_intents VALUES(tid,public.context_user(),intent,'staff.link',digest,result);RETURN result;
END $$;
CREATE FUNCTION governance.link_profile_resolved() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE p public.employee_profiles;mid uuid;
BEGIN
 IF NEW.status='accepted' AND OLD.status='pending' THEN
  SELECT * INTO p FROM public.employee_profiles WHERE tenant_id=NEW.tenant_id AND pending_link_id=NEW.id FOR UPDATE;
  IF FOUND THEN
   IF NOT p.active OR p.membership_id IS NOT NULL THEN RAISE EXCEPTION 'Profile changed' USING ERRCODE='42501';END IF;
   SELECT id INTO mid FROM public.tenant_memberships WHERE tenant_id=NEW.tenant_id AND user_id=NEW.target_user_id;
   UPDATE public.employee_profiles SET membership_id=mid,pending_link_id=NULL,version=version+1,updated_at=now() WHERE id=p.id AND tenant_id=p.tenant_id;
  END IF;
 END IF;RETURN NEW;
END $$;
CREATE TRIGGER link_profile_resolved AFTER UPDATE OF status ON membership_link_requests FOR EACH ROW EXECUTE FUNCTION governance.link_profile_resolved();
