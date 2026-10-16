CREATE SCHEMA governance AUTHORIZATION blusser_owner;
REVOKE ALL ON SCHEMA governance FROM PUBLIC;
GRANT USAGE, CREATE ON SCHEMA governance TO blusser_security;
GRANT USAGE ON SCHEMA governance TO blusser_app;
ALTER TABLE tenants DROP CONSTRAINT tenants_status_check;
ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('pending','active','suspended'));
ALTER TABLE tenant_roles ADD COLUMN protected boolean NOT NULL DEFAULT false;
ALTER TABLE tenant_memberships ADD CONSTRAINT memberships_identity_unique UNIQUE (tenant_id,id,user_id);
ALTER TABLE membership_link_requests ADD COLUMN purpose text NOT NULL DEFAULT 'employee' CHECK (purpose IN ('employee','ceo'));
ALTER TABLE membership_link_requests ADD COLUMN initiator_version bigint;
CREATE TABLE tenant_ceos (
 tenant_id uuid PRIMARY KEY REFERENCES tenants(id) ON DELETE RESTRICT,
 user_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 membership_id uuid NOT NULL,
 FOREIGN KEY (tenant_id,membership_id,user_id) REFERENCES tenant_memberships(tenant_id,id,user_id) ON DELETE RESTRICT
);
CREATE TABLE ceo_recovery_requests (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL REFERENCES tenant_ceos(tenant_id) ON DELETE RESTRICT,
 target_user_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 initiated_by uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 verified_by uuid REFERENCES users(id) ON DELETE RESTRICT,
 verification_reference varchar(100), credential_version bigint NOT NULL,
 token_digest char(64), token_expires_at timestamptz,
 status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','consumed')),
 expires_at timestamptz NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), consumed_at timestamptz,
 CHECK (initiated_by <> target_user_id AND (verified_by IS NULL OR (verified_by <> initiated_by AND verified_by <> target_user_id))),
 CHECK ((token_digest IS NULL AND token_expires_at IS NULL) OR (token_digest IS NOT NULL AND token_digest ~ '^[a-f0-9]{64}$' AND token_expires_at IS NOT NULL))
);
CREATE INDEX recovery_target_idx ON ceo_recovery_requests(target_user_id, status);
ALTER TABLE tenant_ceos ENABLE ROW LEVEL SECURITY;
ALTER TABLE tenant_ceos FORCE ROW LEVEL SECURITY;
ALTER TABLE ceo_recovery_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE ceo_recovery_requests FORCE ROW LEVEL SECURITY;
CREATE POLICY migration_owner ON tenant_ceos TO blusser_owner USING(true) WITH CHECK(true);
CREATE POLICY migration_owner ON ceo_recovery_requests TO blusser_owner USING(true) WITH CHECK(true);
GRANT SELECT, INSERT, UPDATE ON users TO blusser_security;
GRANT INSERT, UPDATE ON tenants TO blusser_security;
GRANT SELECT, INSERT, UPDATE, DELETE ON tenant_roles, role_permissions, tenant_memberships,
 membership_branches, membership_link_requests, membership_link_request_branches TO blusser_security;
GRANT INSERT ON branches, audit_logs TO blusser_security;
GRANT SELECT, INSERT, UPDATE ON tenant_ceos, ceo_recovery_requests, auth_rate_limits TO blusser_security;
GRANT DELETE, SELECT (user_id) ON sessions TO blusser_security;

CREATE OR REPLACE FUNCTION public.is_active_member(target_tenant uuid) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER
SET search_path=pg_catalog AS $$
 SELECT EXISTS (SELECT 1 FROM public.tenant_memberships m
 JOIN public.users u ON u.id=m.user_id AND u.active AND NOT u.is_platform_admin
 JOIN public.tenants t ON t.id=m.tenant_id AND t.status='active'
 JOIN public.tenant_roles r ON (r.tenant_id,r.id)=(m.tenant_id,m.role_id) AND r.active
 WHERE m.user_id=public.context_user() AND m.tenant_id=target_tenant AND m.active)
$$;

CREATE FUNCTION governance.lock_users(ids uuid[]) RETURNS void LANGUAGE plpgsql SET search_path=pg_catalog AS $$
BEGIN
 PERFORM id FROM public.users WHERE id=ANY(ids) ORDER BY id FOR UPDATE;
END $$;
CREATE FUNCTION governance.actor(full_access boolean DEFAULT true) RETURNS public.users LANGUAGE plpgsql SET search_path=pg_catalog AS $$
DECLARE u public.users;
BEGIN
 SELECT * INTO u FROM public.users WHERE id=public.context_user();
 IF u.id IS NULL OR NOT u.active OR u.credential_version IS DISTINCT FROM nullif(current_setting('app.credential_version',true),'')::bigint
 OR (u.must_change_password AND (full_access OR u.temporary_password_expires_at<=now())) THEN
  RAISE EXCEPTION 'Identity not authorized' USING ERRCODE='42501';
 END IF;
 RETURN u;
END $$;
CREATE FUNCTION governance.platform() RETURNS void LANGUAGE plpgsql SET search_path=pg_catalog AS $$
DECLARE u public.users;
BEGIN
 u:=governance.actor();
 IF NOT u.is_platform_admin THEN RAISE EXCEPTION 'Platform access denied' USING ERRCODE='42501'; END IF;
END $$;
CREATE FUNCTION governance.tenant(permission_code text) RETURNS void LANGUAGE plpgsql SET search_path=pg_catalog AS $$
DECLARE u public.users;
BEGIN
 u:=governance.actor();
 PERFORM id FROM public.tenants WHERE id=public.context_tenant() AND status='active' FOR UPDATE;
 IF NOT FOUND OR u.is_platform_admin OR NOT EXISTS(SELECT 1 FROM public.tenant_ceos WHERE tenant_id=public.context_tenant())
 OR public.permission_scope(permission_code) IS DISTINCT FROM 'entire_clinic' THEN
  RAISE EXCEPTION 'Tenant operation denied' USING ERRCODE='42501';
 END IF;
END $$;
CREATE FUNCTION governance.event(action_code text, resource_type text, resource_id uuid, tid uuid) RETURNS void LANGUAGE sql SET search_path=pg_catalog AS $$
 INSERT INTO public.audit_logs(id,tenant_id,actor_user_id,action,resource_type,resource_id,request_id)
 VALUES(gen_random_uuid(),tid,public.context_user(),action_code,resource_type,resource_id,gen_random_uuid())
$$;
CREATE FUNCTION governance.scope_for(actor_id uuid,tid uuid,permission_code text) RETURNS text LANGUAGE sql STABLE SET search_path=pg_catalog AS $$
 SELECT rp.scope FROM public.tenant_memberships m
 JOIN public.users u ON u.id=m.user_id AND u.active AND NOT u.is_platform_admin AND NOT u.must_change_password
 JOIN public.tenant_roles r ON (r.tenant_id,r.id)=(m.tenant_id,m.role_id) AND r.active
 JOIN public.role_permissions rp ON (rp.tenant_id,rp.role_id)=(m.tenant_id,m.role_id)
 JOIN public.permissions p ON p.id=rp.permission_id AND p.code=permission_code
 JOIN public.tenants t ON t.id=m.tenant_id AND t.status='active'
 WHERE m.user_id=actor_id AND m.tenant_id=tid AND m.active
$$;
CREATE FUNCTION governance.check_grants(grants jsonb, actor_id uuid, tid uuid) RETURNS void LANGUAGE plpgsql SET search_path=pg_catalog AS $$
DECLARE g jsonb; own_scope text;
BEGIN
 IF grants IS NULL OR jsonb_typeof(grants)<>'array' THEN RAISE EXCEPTION 'Invalid grants' USING ERRCODE='22023'; END IF;
 FOR g IN SELECT value FROM jsonb_array_elements(grants) LOOP
  own_scope:=governance.scope_for(actor_id,tid,g->>'code');
  IF NOT EXISTS(SELECT 1 FROM public.permissions WHERE code=g->>'code' AND (g->>'scope')=ANY(allowed_scopes))
   OR own_scope IS NULL OR (own_scope<>'entire_clinic' AND own_scope IS DISTINCT FROM g->>'scope') THEN
   RAISE EXCEPTION 'Delegation exceeds authority' USING ERRCODE='42501';
  END IF;
 END LOOP;
END $$;
CREATE FUNCTION governance.check_role(role uuid,actor_id uuid,tid uuid) RETURNS void LANGUAGE plpgsql SET search_path=pg_catalog AS $$
DECLARE grants jsonb;
BEGIN
 IF NOT EXISTS(SELECT 1 FROM public.tenant_roles WHERE id=role AND tenant_id=tid AND active AND NOT protected) THEN
  RAISE EXCEPTION 'Role unavailable' USING ERRCODE='42501';
 END IF;
 SELECT coalesce(jsonb_agg(jsonb_build_object('code',p.code,'scope',rp.scope)),'[]'::jsonb) INTO grants
 FROM public.role_permissions rp JOIN public.permissions p ON p.id=rp.permission_id WHERE rp.role_id=role AND rp.tenant_id=tid;
 PERFORM governance.check_grants(grants,actor_id,tid);
END $$;
CREATE FUNCTION governance.set_branches(member uuid,ids uuid[]) RETURNS void LANGUAGE plpgsql SET search_path=pg_catalog AS $$
DECLARE bid uuid;
BEGIN
 DELETE FROM public.membership_branches WHERE tenant_id=public.context_tenant() AND membership_id=member;
 FOR bid IN SELECT DISTINCT unnest(ids) LOOP
  IF NOT EXISTS(SELECT 1 FROM public.branches WHERE id=bid AND tenant_id=public.context_tenant() AND active) THEN
   RAISE EXCEPTION 'Branch unavailable' USING ERRCODE='42501';
  END IF;
  INSERT INTO public.membership_branches(id,tenant_id,membership_id,branch_id) VALUES(gen_random_uuid(),public.context_tenant(),member,bid);
 END LOOP;
END $$;

CREATE FUNCTION governance.identity_lookup(login_value text) RETURNS jsonb LANGUAGE sql SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT jsonb_build_object('id',id,'login',login,'name',name,'password_hash',password_hash,'active',active,
  'is_platform_admin',is_platform_admin,'must_change_password',must_change_password,
  'temporary_password_expires_at',temporary_password_expires_at,'credential_version',credential_version)
 FROM public.users WHERE login=login_value
$$;
CREATE FUNCTION governance.rate_reserve(key_value text,ip_bucket boolean) RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE r public.auth_rate_limits; ceiling integer:=CASE WHEN ip_bucket THEN 30 ELSE 5 END;
BEGIN
 INSERT INTO public.auth_rate_limits AS b(key_digest,window_started_at,attempts,expires_at)
 VALUES(key_value,now(),1,now()+interval '15 minutes')
 ON CONFLICT(key_digest) DO UPDATE SET
 attempts=CASE WHEN b.expires_at<=now() THEN 1 ELSE b.attempts+1 END,
 window_started_at=CASE WHEN b.expires_at<=now() THEN now() ELSE b.window_started_at END,
 blocked_until=CASE WHEN b.expires_at<=now() THEN NULL ELSE b.blocked_until END,
 expires_at=CASE WHEN b.expires_at<=now() THEN now()+interval '15 minutes' ELSE b.expires_at END
 RETURNING * INTO r;
 RETURN r.attempts<=ceiling AND (r.blocked_until IS NULL OR r.blocked_until<=now());
END $$;
CREATE FUNCTION governance.rate_finish(key_value text,success boolean) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN
 UPDATE public.auth_rate_limits SET attempts=CASE WHEN success THEN greatest(0,attempts-1) ELSE attempts END,
 blocked_until=CASE WHEN NOT success AND attempts>=5 THEN now()+interval '15 minutes' ELSE blocked_until END
 WHERE key_digest=key_value;
END $$;
CREATE FUNCTION governance.password_change(new_hash text) RETURNS bigint LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE u public.users; version bigint;
BEGIN
 PERFORM governance.lock_users(ARRAY[public.context_user()]);
 u:=governance.actor(false);
 UPDATE public.users SET password_hash=new_hash,must_change_password=false,temporary_password_expires_at=NULL,
 password_changed_at=now(),credential_version=credential_version+1,updated_at=now() WHERE id=u.id RETURNING credential_version INTO version;
 DELETE FROM public.sessions WHERE user_id=u.id;
 PERFORM governance.event('password.changed','user',u.id,NULL);
 RETURN version;
END $$;
CREATE FUNCTION governance.provision(name_value text,slug_value text,login_value text,new_hash text) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE tid uuid:=gen_random_uuid(); rid uuid:=gen_random_uuid(); uid uuid; mid uuid:=gen_random_uuid(); u public.users; existing boolean;
BEGIN
 PERFORM governance.lock_users(ARRAY[public.context_user(),(SELECT id FROM public.users WHERE login=login_value)]);
 PERFORM governance.platform();
 SELECT * INTO u FROM public.users WHERE login=login_value;
 existing:=u.id IS NOT NULL;
 IF existing AND (u.is_platform_admin OR NOT u.active OR u.id=public.context_user()) THEN
  RAISE EXCEPTION 'Identity cannot be linked' USING ERRCODE='42501';
 END IF;
 uid:=coalesce(u.id,gen_random_uuid());
 INSERT INTO public.tenants(id,name,slug,status) VALUES(tid,name_value,slug_value,CASE WHEN existing THEN 'pending' ELSE 'active' END);
 INSERT INTO public.tenant_roles(id,tenant_id,code,name,protected) VALUES(rid,tid,'ceo','CEO',true);
 INSERT INTO public.role_permissions(tenant_id,role_id,permission_id,scope) SELECT tid,rid,id,'entire_clinic' FROM public.permissions;
 IF existing THEN
  INSERT INTO public.membership_link_requests(id,tenant_id,target_user_id,initiated_by_user_id,initiator_version,role_id,purpose,expires_at)
  VALUES(gen_random_uuid(),tid,uid,public.context_user(),(governance.actor()).credential_version,rid,'ceo',now()+interval '24 hours');
 ELSE
  IF new_hash IS NULL THEN RAISE EXCEPTION 'Temporary password required' USING ERRCODE='22023'; END IF;
  INSERT INTO public.users(id,login,name,password_hash,temporary_password_expires_at) VALUES(uid,login_value,'CEO',new_hash,now()+interval '24 hours');
  INSERT INTO public.tenant_memberships(id,tenant_id,user_id,role_id) VALUES(mid,tid,uid,rid);
  INSERT INTO public.tenant_ceos(tenant_id,user_id,membership_id) VALUES(tid,uid,mid);
  PERFORM governance.event('account.created','user',uid,NULL);
 END IF;
 PERFORM governance.event('clinic.provisioned','tenant',tid,tid);
 RETURN jsonb_build_object('tenant_id',tid,'pending_consent',existing);
END $$;
CREATE FUNCTION governance.role_save(role uuid,code_value text,name_value text,grants jsonb) RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE rid uuid:=coalesce(role,gen_random_uuid()); g jsonb;
BEGIN
 PERFORM governance.lock_users(ARRAY[public.context_user()]);
 PERFORM governance.tenant(CASE WHEN role IS NULL THEN 'roles.create' ELSE 'roles.update' END);
 IF role IS NOT NULL AND (NOT EXISTS(SELECT 1 FROM public.tenant_roles WHERE id=role AND tenant_id=public.context_tenant() AND NOT protected)
  OR EXISTS(SELECT 1 FROM public.tenant_memberships WHERE role_id=role AND tenant_id=public.context_tenant() AND user_id=public.context_user())) THEN
  RAISE EXCEPTION 'Own or protected role cannot be changed' USING ERRCODE='42501';
 END IF;
 IF role IS NOT NULL THEN PERFORM governance.check_role(role,public.context_user(),public.context_tenant()); END IF;
 PERFORM governance.tenant('roles.manage_permissions');
 PERFORM governance.check_grants(grants,public.context_user(),public.context_tenant());
 IF role IS NULL THEN INSERT INTO public.tenant_roles(id,tenant_id,code,name) VALUES(rid,public.context_tenant(),code_value,name_value);
 ELSE UPDATE public.tenant_roles SET code=code_value,name=name_value,updated_at=now() WHERE id=rid AND tenant_id=public.context_tenant(); END IF;
 DELETE FROM public.role_permissions WHERE role_id=rid AND tenant_id=public.context_tenant();
 FOR g IN SELECT value FROM jsonb_array_elements(grants) LOOP
  INSERT INTO public.role_permissions(tenant_id,role_id,permission_id,scope)
  SELECT public.context_tenant(),rid,id,g->>'scope' FROM public.permissions WHERE code=g->>'code';
 END LOOP;
 PERFORM governance.event('role.saved','role',rid,public.context_tenant());
 RETURN rid;
END $$;
CREATE FUNCTION governance.role_delete(role uuid) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN
 PERFORM governance.lock_users(ARRAY[public.context_user()]); PERFORM governance.tenant('roles.delete');
 IF NOT EXISTS(SELECT 1 FROM public.tenant_roles WHERE id=role AND tenant_id=public.context_tenant() AND NOT protected)
 OR EXISTS(SELECT 1 FROM public.tenant_memberships WHERE role_id=role AND tenant_id=public.context_tenant()) THEN
  RAISE EXCEPTION 'Protected or used role cannot be deleted' USING ERRCODE='42501';
 END IF;
 PERFORM governance.check_role(role,public.context_user(),public.context_tenant());
 DELETE FROM public.role_permissions WHERE role_id=role AND tenant_id=public.context_tenant();
 DELETE FROM public.tenant_roles WHERE id=role AND tenant_id=public.context_tenant();
 PERFORM governance.event('role.deleted','role',role,public.context_tenant());
END $$;
CREATE FUNCTION governance.branch_create(name_value text,code_value text) RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE bid uuid:=gen_random_uuid();
BEGIN
 PERFORM governance.lock_users(ARRAY[public.context_user()]); PERFORM governance.tenant('branches.create');
 INSERT INTO public.branches(id,tenant_id,name,code) VALUES(bid,public.context_tenant(),name_value,code_value);
 PERFORM governance.event('branch.created','branch',bid,public.context_tenant()); RETURN bid;
END $$;
CREATE FUNCTION governance.employee_create(login_value text,name_value text,new_hash text,role uuid,branches uuid[]) RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE uid uuid:=gen_random_uuid(); mid uuid:=gen_random_uuid();
BEGIN
 PERFORM governance.lock_users(ARRAY[public.context_user()]); PERFORM governance.tenant('employees.create');
 PERFORM governance.tenant('employees.assign_role');
 IF cardinality(branches)>0 THEN PERFORM governance.tenant('employees.assign_branches'); END IF;
 PERFORM governance.check_role(role,public.context_user(),public.context_tenant());
 INSERT INTO public.users(id,login,name,password_hash,temporary_password_expires_at) VALUES(uid,login_value,name_value,new_hash,now()+interval '24 hours');
 INSERT INTO public.tenant_memberships(id,tenant_id,user_id,role_id) VALUES(mid,public.context_tenant(),uid,role);
 PERFORM governance.set_branches(mid,branches);
 PERFORM governance.event('account.created','user',uid,NULL);
 PERFORM governance.event('employee.created','membership',mid,public.context_tenant()); RETURN mid;
END $$;
CREATE FUNCTION governance.employee_assign(member uuid,role uuid,branches uuid[],active_value boolean) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE m public.tenant_memberships;
BEGIN
 SELECT * INTO m FROM public.tenant_memberships WHERE id=member AND tenant_id=public.context_tenant();
 PERFORM governance.lock_users(ARRAY[public.context_user(),m.user_id]);
 PERFORM governance.tenant('employees.update');
 SELECT * INTO m FROM public.tenant_memberships WHERE id=member AND tenant_id=public.context_tenant() FOR UPDATE;
 IF m.id IS NULL OR m.user_id=public.context_user() OR EXISTS(SELECT 1 FROM public.tenant_ceos WHERE tenant_id=m.tenant_id AND membership_id=m.id) THEN
  RAISE EXCEPTION 'Own or CEO membership protected' USING ERRCODE='42501';
 END IF;
 PERFORM governance.check_role(m.role_id,public.context_user(),m.tenant_id);
 IF role IS NOT NULL THEN
  PERFORM governance.tenant('employees.assign_role'); PERFORM governance.check_role(role,public.context_user(),m.tenant_id);
  UPDATE public.tenant_memberships SET role_id=role,updated_at=now() WHERE id=member AND tenant_id=m.tenant_id;
 END IF;
 IF branches IS NOT NULL THEN PERFORM governance.tenant('employees.assign_branches'); PERFORM governance.set_branches(member,branches); END IF;
 IF active_value IS NOT NULL THEN
  IF NOT active_value THEN PERFORM governance.tenant('employees.delete'); END IF;
  UPDATE public.tenant_memberships SET active=active_value,updated_at=now() WHERE id=member AND tenant_id=m.tenant_id;
 END IF;
 PERFORM governance.event('employee.updated','membership',member,public.context_tenant());
END $$;
CREATE FUNCTION governance.employee_reset(member uuid,new_hash text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE m public.tenant_memberships;
BEGIN
 SELECT * INTO m FROM public.tenant_memberships WHERE id=member AND tenant_id=public.context_tenant();
 PERFORM governance.lock_users(ARRAY[public.context_user(),m.user_id]); PERFORM governance.tenant('employees.reset_password');
 SELECT * INTO m FROM public.tenant_memberships WHERE id=member AND tenant_id=public.context_tenant() FOR UPDATE;
 IF nullif(current_setting('app.reauthenticated',true),'') IS DISTINCT FROM 'true' OR m.id IS NULL OR NOT m.active
 OR m.user_id=public.context_user() OR EXISTS(SELECT 1 FROM public.tenant_ceos WHERE user_id=m.user_id)
 OR EXISTS(SELECT 1 FROM public.users WHERE id=m.user_id AND is_platform_admin)
 OR EXISTS(SELECT 1 FROM public.tenant_memberships WHERE user_id=m.user_id AND tenant_id<>m.tenant_id) THEN
  RAISE EXCEPTION 'Reset not permitted' USING ERRCODE='42501';
 END IF;
 PERFORM governance.check_role(m.role_id,public.context_user(),m.tenant_id);
 UPDATE public.users SET password_hash=new_hash,must_change_password=true,temporary_password_expires_at=now()+interval '24 hours',
 credential_version=credential_version+1,updated_at=now() WHERE id=m.user_id;
 DELETE FROM public.sessions WHERE user_id=m.user_id;
 PERFORM governance.event('password.reset','user',m.user_id,NULL);
 PERFORM governance.event('employee.password_reset','membership',member,m.tenant_id);
END $$;
CREATE FUNCTION governance.link_request(login_value text,role uuid,branches uuid[]) RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE target public.users; rid uuid:=gen_random_uuid(); bid uuid;
BEGIN
 SELECT * INTO target FROM public.users WHERE login=login_value;
 PERFORM governance.lock_users(ARRAY[public.context_user(),target.id]);
 PERFORM governance.tenant('employees.create'); PERFORM governance.tenant('employees.assign_role'); PERFORM governance.tenant('employees.lookup_identity');
 IF cardinality(branches)>0 THEN PERFORM governance.tenant('employees.assign_branches'); END IF;
 PERFORM governance.check_role(role,public.context_user(),public.context_tenant());
 IF target.id IS NULL OR target.is_platform_admin OR NOT target.active OR target.id=public.context_user() THEN
  RAISE EXCEPTION 'Identity cannot be linked' USING ERRCODE='42501'; END IF;
 UPDATE public.membership_link_requests SET status='expired' WHERE tenant_id=public.context_tenant() AND target_user_id=target.id AND status='pending' AND expires_at<=now();
 INSERT INTO public.membership_link_requests(id,tenant_id,target_user_id,initiated_by_user_id,initiator_version,role_id,expires_at)
 VALUES(rid,public.context_tenant(),target.id,public.context_user(),(governance.actor()).credential_version,role,now()+interval '24 hours');
 FOR bid IN SELECT DISTINCT unnest(branches) LOOP
  IF NOT EXISTS(SELECT 1 FROM public.branches WHERE id=bid AND tenant_id=public.context_tenant() AND active) THEN
   RAISE EXCEPTION 'Branch unavailable' USING ERRCODE='42501'; END IF;
  INSERT INTO public.membership_link_request_branches(tenant_id,request_id,branch_id) VALUES(public.context_tenant(),rid,bid);
 END LOOP;
 PERFORM governance.event('membership.link_requested','link',rid,public.context_tenant()); RETURN rid;
END $$;
CREATE FUNCTION governance.pending_links() RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN
 IF (governance.actor()).is_platform_admin THEN RAISE EXCEPTION 'Tenant identity required' USING ERRCODE='42501'; END IF;
 RETURN (SELECT coalesce(jsonb_agg(jsonb_build_object('id',q.id,'clinic',t.name,'role',r.name,'purpose',q.purpose,'expires_at',q.expires_at)),'[]'::jsonb)
 FROM public.membership_link_requests q JOIN public.tenants t ON t.id=q.tenant_id
 JOIN public.tenant_roles r ON (r.tenant_id,r.id)=(q.tenant_id,q.role_id)
 WHERE q.target_user_id=public.context_user() AND q.status='pending' AND q.expires_at>now());
END $$;
CREATE FUNCTION governance.link_accept(request uuid) RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE q public.membership_link_requests; initiator public.users; target public.users; mid uuid:=gen_random_uuid(); bids uuid[];
BEGIN
 SELECT * INTO q FROM public.membership_link_requests WHERE id=request AND target_user_id=public.context_user();
 PERFORM governance.lock_users(ARRAY[public.context_user(),q.initiated_by_user_id]); target:=governance.actor();
 IF target.is_platform_admin OR q.id IS NULL OR q.status<>'pending' OR q.expires_at<=now()
 OR nullif(current_setting('app.reauthenticated',true),'') IS DISTINCT FROM 'true' THEN
  RAISE EXCEPTION 'Link unavailable' USING ERRCODE='42501'; END IF;
 PERFORM id FROM public.tenants WHERE id=q.tenant_id FOR UPDATE;
 SELECT * INTO q FROM public.membership_link_requests WHERE id=request FOR UPDATE;
 IF q.status<>'pending' OR q.expires_at<=now() THEN RAISE EXCEPTION 'Link unavailable' USING ERRCODE='42501'; END IF;
 SELECT * INTO initiator FROM public.users WHERE id=q.initiated_by_user_id;
 IF NOT initiator.active OR initiator.must_change_password OR initiator.credential_version IS DISTINCT FROM q.initiator_version THEN
  RAISE EXCEPTION 'Initiator unavailable' USING ERRCODE='42501'; END IF;
 IF q.purpose='ceo' THEN
  IF NOT initiator.is_platform_admin OR NOT EXISTS(SELECT 1 FROM public.tenants WHERE id=q.tenant_id AND status='pending')
   OR EXISTS(SELECT 1 FROM public.tenant_ceos WHERE tenant_id=q.tenant_id)
   OR NOT EXISTS(SELECT 1 FROM public.tenant_roles WHERE id=q.role_id AND tenant_id=q.tenant_id AND protected AND active) THEN
   RAISE EXCEPTION 'CEO proposal unavailable' USING ERRCODE='42501'; END IF;
 ELSE
  IF governance.scope_for(initiator.id,q.tenant_id,'employees.create') IS DISTINCT FROM 'entire_clinic'
   OR governance.scope_for(initiator.id,q.tenant_id,'employees.assign_role') IS DISTINCT FROM 'entire_clinic' THEN
   RAISE EXCEPTION 'Initiator permission revoked' USING ERRCODE='42501'; END IF;
  PERFORM governance.check_role(q.role_id,initiator.id,q.tenant_id);
 END IF;
 SELECT coalesce(array_agg(branch_id),'{}'::uuid[]) INTO bids FROM public.membership_link_request_branches WHERE request_id=request AND tenant_id=q.tenant_id;
 IF cardinality(bids)>0 AND governance.scope_for(initiator.id,q.tenant_id,'employees.assign_branches') IS DISTINCT FROM 'entire_clinic' THEN
  RAISE EXCEPTION 'Branch permission revoked' USING ERRCODE='42501'; END IF;
 INSERT INTO public.tenant_memberships(id,tenant_id,user_id,role_id) VALUES(mid,q.tenant_id,target.id,q.role_id);
 PERFORM set_config('app.tenant_id',q.tenant_id::text,true); PERFORM governance.set_branches(mid,bids);
 IF q.purpose='ceo' THEN
  INSERT INTO public.tenant_ceos(tenant_id,user_id,membership_id) VALUES(q.tenant_id,target.id,mid);
  UPDATE public.tenants SET status='active',updated_at=now() WHERE id=q.tenant_id;
 END IF;
 UPDATE public.membership_link_requests SET status='accepted',resolved_at=now() WHERE id=request;
 PERFORM governance.event('membership.link_accepted','membership',mid,q.tenant_id); RETURN mid;
END $$;
CREATE FUNCTION governance.recovery_open(tid uuid) RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE target uuid; rid uuid:=gen_random_uuid();
BEGIN
 SELECT user_id INTO target FROM public.tenant_ceos WHERE tenant_id=tid;
 PERFORM governance.lock_users(ARRAY[public.context_user(),target]); PERFORM governance.platform();
 IF nullif(current_setting('app.reauthenticated',true),'') IS DISTINCT FROM 'true' OR target IS NULL OR target=public.context_user() THEN RAISE EXCEPTION 'CEO unavailable' USING ERRCODE='42501'; END IF;
 PERFORM id FROM public.tenants WHERE id=tid FOR UPDATE;
 INSERT INTO public.ceo_recovery_requests(id,tenant_id,target_user_id,initiated_by,credential_version,expires_at)
 SELECT rid,tid,target,public.context_user(),credential_version,now()+interval '24 hours' FROM public.users WHERE id=target AND active AND NOT is_platform_admin;
 IF NOT FOUND THEN RAISE EXCEPTION 'CEO unavailable' USING ERRCODE='42501'; END IF;
 PERFORM governance.event('ceo.recovery_requested','recovery',rid,tid); RETURN rid;
END $$;
CREATE FUNCTION governance.recovery_approve(request uuid,reference_value text,digest_value text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE q public.ceo_recovery_requests;
BEGIN
 SELECT * INTO q FROM public.ceo_recovery_requests WHERE id=request;
 PERFORM governance.lock_users(ARRAY[public.context_user(),q.target_user_id,q.initiated_by]); PERFORM governance.platform();
 IF nullif(current_setting('app.reauthenticated',true),'') IS DISTINCT FROM 'true' OR q.id IS NULL OR q.initiated_by=public.context_user() OR q.target_user_id=public.context_user()
 OR NOT EXISTS(SELECT 1 FROM public.users WHERE id=q.initiated_by AND active AND is_platform_admin AND NOT must_change_password)
 OR reference_value IS NULL OR digest_value IS NULL OR length(reference_value)<3 OR length(reference_value)>100 OR digest_value !~ '^[a-f0-9]{64}$' THEN
  RAISE EXCEPTION 'Independent verification required' USING ERRCODE='42501'; END IF;
 PERFORM id FROM public.tenants WHERE id=q.tenant_id FOR UPDATE;
 SELECT * INTO q FROM public.ceo_recovery_requests WHERE id=request FOR UPDATE;
 IF q.status<>'pending' OR q.expires_at<=now() OR NOT EXISTS(SELECT 1 FROM public.users WHERE id=q.target_user_id AND credential_version=q.credential_version AND active AND NOT is_platform_admin) THEN
  RAISE EXCEPTION 'Recovery unavailable' USING ERRCODE='42501'; END IF;
 UPDATE public.ceo_recovery_requests SET status='approved',verified_by=public.context_user(),verification_reference=reference_value,
 token_digest=digest_value,token_expires_at=now()+interval '15 minutes' WHERE id=request;
 PERFORM governance.event('ceo.recovery_approved','recovery',request,q.tenant_id);
END $$;
CREATE FUNCTION governance.recovery_consume(request uuid,digest_value text,new_hash text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE q public.ceo_recovery_requests;
BEGIN
 SELECT * INTO q FROM public.ceo_recovery_requests WHERE id=request;
 PERFORM governance.lock_users(ARRAY[q.target_user_id,q.initiated_by,q.verified_by]);
 IF q.id IS NULL THEN RAISE EXCEPTION 'Recovery unavailable' USING ERRCODE='42501'; END IF;
 PERFORM id FROM public.tenants WHERE id=q.tenant_id FOR UPDATE;
 SELECT * INTO q FROM public.ceo_recovery_requests WHERE id=request FOR UPDATE;
 IF q.status<>'approved' OR q.expires_at<=now() OR q.token_expires_at<=now() OR q.token_digest IS DISTINCT FROM digest_value
 OR NOT EXISTS(SELECT 1 FROM public.users WHERE id=q.target_user_id AND credential_version=q.credential_version AND active AND NOT is_platform_admin)
 OR NOT EXISTS(SELECT 1 FROM public.users WHERE id=q.initiated_by AND active AND is_platform_admin AND NOT must_change_password)
 OR NOT EXISTS(SELECT 1 FROM public.users WHERE id=q.verified_by AND active AND is_platform_admin AND NOT must_change_password)
 OR NOT EXISTS(SELECT 1 FROM public.tenant_ceos WHERE tenant_id=q.tenant_id AND user_id=q.target_user_id) THEN
  RAISE EXCEPTION 'Recovery unavailable' USING ERRCODE='42501'; END IF;
 UPDATE public.users SET password_hash=new_hash,must_change_password=false,temporary_password_expires_at=NULL,password_changed_at=now(),
 credential_version=credential_version+1,updated_at=now() WHERE id=q.target_user_id;
 DELETE FROM public.sessions WHERE user_id=q.target_user_id;
 UPDATE public.ceo_recovery_requests SET status='consumed',consumed_at=now(),token_digest=NULL,token_expires_at=NULL WHERE id=request;
 PERFORM set_config('app.user_id',q.target_user_id::text,true);
 PERFORM governance.event('ceo.recovery_completed','recovery',request,q.tenant_id);
 PERFORM governance.event('password.recovered','user',q.target_user_id,NULL);
END $$;
CREATE FUNCTION governance.login_exists(login_value text) RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN
 PERFORM governance.lock_users(ARRAY[public.context_user()]); PERFORM governance.tenant('employees.lookup_identity');
 PERFORM governance.event('identity.lookup','identity',NULL,public.context_tenant());
 RETURN EXISTS(SELECT 1 FROM public.users WHERE login=login_value);
END $$;
DROP POLICY audit_read ON public.audit_logs;
CREATE POLICY audit_read ON public.audit_logs FOR SELECT TO blusser_app USING
 ((tenant_id=public.context_tenant() AND public.can_access('audit.view',actor_user_id,branch_id))
 OR (public.is_platform_admin() AND action IN ('clinic.provisioned','ceo.recovery_requested','ceo.recovery_approved','ceo.recovery_completed')));

CREATE FUNCTION governance.auth_failure(digest_value text) RETURNS void LANGUAGE sql SECURITY DEFINER SET search_path=pg_catalog AS $$
 INSERT INTO public.audit_logs(id,action,resource_type,metadata,request_id)
 VALUES(gen_random_uuid(),'auth.failed','identity',jsonb_build_object('key_digest',digest_value),gen_random_uuid())
$$;
