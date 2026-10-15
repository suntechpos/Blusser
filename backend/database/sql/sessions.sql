-- Cookie session persistence never trusts a client-supplied user/tenant id.
-- Authenticated rows are registered explicitly; late writers only UPDATE.
GRANT SELECT, INSERT, UPDATE ON sessions TO blusser_security;
REVOKE INSERT, UPDATE ON sessions FROM blusser_app;
GRANT SELECT ON audit_logs TO blusser_security;

CREATE FUNCTION governance.session_identity(uid uuid, version bigint) RETURNS jsonb
LANGUAGE sql SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT jsonb_build_object('id',id,'login',login,'name',name,'active',active,
 'credential_version',credential_version,'is_platform_admin',is_platform_admin,
 'must_change_password',must_change_password)
 FROM public.users WHERE id=uid AND credential_version=version AND active
 AND (NOT must_change_password OR temporary_password_expires_at>now())
$$;
CREATE FUNCTION governance.session_start(sid text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE u public.users;
BEGIN
 PERFORM governance.lock_users(ARRAY[public.context_user()]); u:=governance.actor(false);
 INSERT INTO public.sessions(id,user_id,credential_version,auth_stage,payload,last_activity)
 VALUES(sid,u.id,u.credential_version,CASE WHEN u.must_change_password THEN 'password_change_required' ELSE 'full' END,'',extract(epoch FROM now())::integer);
 PERFORM governance.event('auth.session_started','user',u.id,NULL);
END $$;
CREATE FUNCTION governance.session_write(sid text, uid uuid, version bigint, stage text, data_value text, activity integer) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE u public.users;
BEGIN
 IF uid IS NULL THEN
  INSERT INTO public.sessions(id,payload,last_activity) VALUES(sid,data_value,activity)
  ON CONFLICT(id) DO UPDATE SET payload=excluded.payload,last_activity=excluded.last_activity
  WHERE public.sessions.user_id IS NULL;
  RETURN FOUND;
 END IF;
 PERFORM governance.lock_users(ARRAY[uid]);
 SELECT * INTO u FROM public.users WHERE id=uid;
 IF u.id IS NULL OR NOT u.active OR u.credential_version IS DISTINCT FROM version
 OR (u.must_change_password AND u.temporary_password_expires_at<=now())
 OR stage IS DISTINCT FROM (CASE WHEN u.must_change_password THEN 'password_change_required' ELSE 'full' END) THEN RETURN false; END IF;
 UPDATE public.sessions SET payload=data_value,last_activity=activity
 WHERE id=sid AND user_id=uid AND credential_version=version AND auth_stage=stage;
 RETURN FOUND;
END $$;
CREATE FUNCTION governance.session_logout(sid text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE u public.users;
BEGIN
 PERFORM governance.lock_users(ARRAY[public.context_user()]); u:=governance.actor(false);
 DELETE FROM public.sessions WHERE id=sid AND user_id=u.id;
 PERFORM governance.event('auth.logout','user',u.id,NULL);
END $$;
CREATE FUNCTION governance.my_clinics() RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE u public.users;
BEGIN
 u:=governance.actor();
 IF u.is_platform_admin THEN RAISE EXCEPTION 'Tenant identity required' USING ERRCODE='42501'; END IF;
 RETURN (SELECT coalesce(jsonb_agg(jsonb_build_object('id',t.id,'name',t.name,'role',r.name)),'[]'::jsonb)
 FROM public.tenant_memberships m JOIN public.tenants t ON t.id=m.tenant_id AND t.status='active'
 JOIN public.tenant_roles r ON (r.tenant_id,r.id)=(m.tenant_id,m.role_id) AND r.active
 WHERE m.user_id=u.id AND m.active);
END $$;
CREATE FUNCTION governance.select_clinic(tid uuid) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE u public.users;
BEGIN
 PERFORM governance.lock_users(ARRAY[public.context_user()]); u:=governance.actor();
 PERFORM id FROM public.tenants WHERE id=tid FOR UPDATE;
 IF u.is_platform_admin OR NOT public.is_active_member(tid) THEN RAISE EXCEPTION 'Clinic unavailable' USING ERRCODE='42501'; END IF;
 PERFORM governance.event('clinic.selected','tenant',tid,tid);
END $$;
CREATE FUNCTION governance.employee_directory(member uuid, page_offset integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN
 PERFORM governance.actor();
 IF NOT public.is_active_member(public.context_tenant()) OR public.permission_scope('employees.view') IS NULL THEN RAISE EXCEPTION 'Access denied' USING ERRCODE='42501'; END IF;
 RETURN (SELECT coalesce(jsonb_agg(row_to_json(x)),'[]'::jsonb) FROM (
 SELECT m.id,m.user_id,m.role_id,m.active,u.login,u.name,
 (SELECT coalesce(jsonb_agg(mb.branch_id),'[]'::jsonb) FROM public.membership_branches mb WHERE mb.tenant_id=m.tenant_id AND mb.membership_id=m.id) AS branch_ids
 FROM public.tenant_memberships m JOIN public.users u ON u.id=m.user_id
 WHERE m.tenant_id=public.context_tenant() AND (member IS NULL OR m.id=member) AND public.can_view_member(m.id)
 ORDER BY m.id LIMIT 50 OFFSET greatest(0,page_offset)) x);
END $$;
CREATE FUNCTION governance.platform_clinics(page_offset integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN
 PERFORM governance.platform();
 RETURN (SELECT coalesce(jsonb_agg(row_to_json(x)),'[]'::jsonb) FROM (
 SELECT id,name,slug,status FROM public.tenants ORDER BY id LIMIT 50 OFFSET greatest(0,page_offset)) x);
END $$;
CREATE FUNCTION governance.platform_audit(page_offset integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN
 PERFORM governance.platform();
 RETURN (SELECT coalesce(jsonb_agg(row_to_json(x)),'[]'::jsonb) FROM (
 SELECT id,tenant_id,action,resource_type,resource_id,created_at FROM public.audit_logs
 WHERE action IN ('clinic.provisioned','ceo.recovery_requested','ceo.recovery_approved','ceo.recovery_completed')
 ORDER BY created_at DESC,id LIMIT 50 OFFSET greatest(0,page_offset)) x);
END $$;
CREATE FUNCTION governance.link_reject(request uuid) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE q public.membership_link_requests;
BEGIN
 PERFORM governance.lock_users(ARRAY[public.context_user()]); PERFORM governance.actor();
 SELECT * INTO q FROM public.membership_link_requests WHERE id=request AND target_user_id=public.context_user();
 IF q.id IS NULL THEN RAISE EXCEPTION 'Link unavailable' USING ERRCODE='42501'; END IF;
 PERFORM id FROM public.tenants WHERE id=q.tenant_id FOR UPDATE;
 UPDATE public.membership_link_requests SET status='rejected',resolved_at=now() WHERE id=request AND status='pending' AND expires_at>now();
 IF NOT FOUND THEN RAISE EXCEPTION 'Link unavailable' USING ERRCODE='42501'; END IF;
 PERFORM governance.event('membership.link_rejected','link',request,q.tenant_id);
END $$;
CREATE FUNCTION governance.operation_denied(operation_value text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE uid uuid; tid uuid;
BEGIN
 SELECT id INTO uid FROM public.users WHERE id=public.context_user() AND active
 AND credential_version=nullif(current_setting('app.credential_version',true),'')::bigint;
 IF uid IS NULL THEN RETURN; END IF;
 IF public.is_active_member(public.context_tenant()) THEN tid:=public.context_tenant(); END IF;
 INSERT INTO public.audit_logs(id,tenant_id,actor_user_id,action,resource_type,metadata,request_id)
 VALUES(gen_random_uuid(),tid,uid,'operation.denied','governance',jsonb_build_object('operation',operation_value),gen_random_uuid());
END $$;
-- Run in the SAME transaction after governance mutation, while its user/tenant
-- locks remain held. Equal scope names do not imply equal visible branch sets.
CREATE FUNCTION governance.assert_branch_delegation(operation_value text, result_id uuid, request_id uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE aid uuid:=public.context_user(); tid uuid:=public.context_tenant(); rid uuid; mids uuid[]; bids uuid[]; g record; bid uuid; q public.membership_link_requests;
BEGIN
 PERFORM governance.actor();
 IF operation_value='role_save' THEN
  rid:=result_id;
  SELECT coalesce(array_agg(m.id),'{}'::uuid[]) INTO mids FROM public.tenant_memberships m WHERE m.tenant_id=tid AND m.role_id=rid;
  SELECT coalesce(array_agg(mb.branch_id),'{}'::uuid[]) INTO bids FROM public.membership_branches mb WHERE mb.tenant_id=tid AND mb.membership_id=ANY(mids);
 ELSIF operation_value='link_request' THEN
  SELECT * INTO q FROM public.membership_link_requests WHERE id=result_id AND tenant_id=tid AND initiated_by_user_id=aid;
  rid:=q.role_id;
  SELECT coalesce(array_agg(branch_id),'{}'::uuid[]) INTO bids FROM public.membership_link_request_branches WHERE tenant_id=tid AND membership_link_request_branches.request_id=q.id;
 ELSIF operation_value IN ('employee_create','employee_assign','employee_reset','link_accept') THEN
  SELECT m.role_id INTO rid FROM public.tenant_memberships m WHERE m.id=result_id AND m.tenant_id=tid;
  SELECT coalesce(array_agg(branch_id),'{}'::uuid[]) INTO bids FROM public.membership_branches WHERE tenant_id=tid AND membership_id=result_id;
  IF operation_value='link_accept' THEN
   SELECT * INTO q FROM public.membership_link_requests WHERE id=request_id AND tenant_id=tid AND target_user_id=aid AND status='accepted';
   IF q.id IS NULL THEN RAISE EXCEPTION 'Link unavailable' USING ERRCODE='42501'; END IF;
   IF q.purpose='ceo' THEN RETURN; END IF;
   aid:=q.initiated_by_user_id;
  END IF;
 ELSE RAISE EXCEPTION 'Invalid operation' USING ERRCODE='22023'; END IF;
 IF rid IS NULL THEN RAISE EXCEPTION 'Role unavailable' USING ERRCODE='42501'; END IF;
 FOR g IN SELECT p.code FROM public.role_permissions rp JOIN public.permissions p ON p.id=rp.permission_id
 WHERE rp.tenant_id=tid AND rp.role_id=rid AND rp.scope='assigned_branches' LOOP
  IF governance.scope_for(aid,tid,g.code)='assigned_branches' THEN
   FOREACH bid IN ARRAY bids LOOP
    IF NOT EXISTS(SELECT 1 FROM public.tenant_memberships m JOIN public.membership_branches mb ON (mb.tenant_id,mb.membership_id)=(m.tenant_id,m.id)
      WHERE m.tenant_id=tid AND m.user_id=aid AND m.active AND mb.branch_id=bid) THEN
     RAISE EXCEPTION 'Branch delegation exceeds authority' USING ERRCODE='42501';
    END IF;
   END LOOP;
  END IF;
 END LOOP;
END $$;
