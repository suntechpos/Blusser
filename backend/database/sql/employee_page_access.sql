CREATE OR REPLACE FUNCTION governance.scope_for(actor_id uuid,tid uuid,permission_code text) RETURNS text LANGUAGE sql STABLE SET search_path=pg_catalog AS $$
 SELECT CASE WHEN ep.page_grants IS NULL THEN rp.scope
  WHEN page.scope IS NULL THEN NULL
  WHEN permission_code LIKE '%.view' THEN page.scope
  WHEN rp.scope=page.scope OR page.scope='entire_clinic' THEN rp.scope
  WHEN rp.scope='entire_clinic' THEN page.scope
  ELSE NULL END
 FROM public.tenant_memberships m
 JOIN public.users u ON u.id=m.user_id AND u.active AND NOT u.is_platform_admin AND NOT u.must_change_password
 JOIN public.tenant_roles r ON (r.tenant_id,r.id)=(m.tenant_id,m.role_id) AND r.active
 JOIN public.tenants t ON t.id=m.tenant_id AND t.status='active'
 JOIN public.permissions permission ON permission.code=permission_code
 LEFT JOIN public.role_permissions rp ON (rp.tenant_id,rp.role_id,rp.permission_id)=(m.tenant_id,m.role_id,permission.id)
 LEFT JOIN public.employee_profiles ep ON ep.tenant_id=m.tenant_id AND ep.membership_id=m.id
 LEFT JOIN LATERAL (SELECT x->>'scope' scope FROM jsonb_array_elements(coalesce(ep.page_grants,'[]')) x WHERE x->>'code'=split_part(permission_code,'.',1)||'.view') page ON true
 WHERE m.user_id=actor_id AND m.tenant_id=tid AND m.active
$$;
CREATE OR REPLACE FUNCTION public.permission_scope(permission_code text) RETURNS text LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT governance.scope_for(public.context_user(),public.context_tenant(),permission_code)
$$;
CREATE OR REPLACE FUNCTION governance.staff_command(target uuid,body jsonb,intent uuid,expected bigint) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE tid uuid:=public.context_tenant();p public.employee_profiles;m public.tenant_memberships;uid uuid;mid uuid;rid uuid;ids uuid[];result jsonb;memo public.access_editor_intents;digest text:=md5((body-'password_hash'-'cashier_pin_hash')::text||coalesce(target::text,'')||coalesce(expected::text,''));enabled boolean:=(body->>'access')::boolean;status boolean:=(body->>'active')::boolean;pid uuid:=coalesce(target,gen_random_uuid());pages jsonb;g jsonb;actor_scope text;bid uuid;
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
   p.id:=target;p.tenant_id:=tid;p.membership_id:=m.id;p.version:=m.employee_version;
  END IF;
  IF p.membership_id IS NOT NULL THEN
   SELECT * INTO m FROM public.tenant_memberships WHERE tenant_id=tid AND id=p.membership_id;
   p.version:=m.employee_version;
  END IF;
  IF expected IS NULL THEN RAISE EXCEPTION 'Version required' USING ERRCODE='P0003';END IF;
  IF p.version<>expected THEN RAISE EXCEPTION 'Stale version' USING ERRCODE='40001';END IF;
  IF p.membership_id IS NOT NULL THEN
   SELECT * INTO m FROM public.tenant_memberships WHERE tenant_id=tid AND id=p.membership_id;
   PERFORM governance.lock_users(ARRAY[m.user_id]);
   IF m.user_id=public.context_user() OR EXISTS(SELECT 1 FROM public.tenant_ceos WHERE tenant_id=tid AND membership_id=m.id) THEN RAISE EXCEPTION 'Own or CEO member protected' USING ERRCODE='42501';END IF;
  ELSIF public.permission_scope('employees.view') IS DISTINCT FROM 'entire_clinic' THEN RAISE EXCEPTION 'Profile scope denied' USING ERRCODE='42501';END IF;
 END IF;
 -- Page changes are explicit; ordinary profile edits preserve existing grants.
 pages:=CASE WHEN body ? 'page_grants' THEN body->'page_grants' ELSE p.page_grants END;
 IF body ? 'page_grants' AND pages IS DISTINCT FROM p.page_grants THEN
  PERFORM governance.tenant('employees.assign_role');
  IF jsonb_typeof(pages)<>'array' OR jsonb_array_length(pages)>100 THEN RAISE EXCEPTION 'Invalid page grants' USING ERRCODE='22023';END IF;
  IF (SELECT count(*) FROM jsonb_array_elements(pages))<>(SELECT count(DISTINCT x->>'code') FROM jsonb_array_elements(pages) x) THEN RAISE EXCEPTION 'Duplicate page' USING ERRCODE='22023';END IF;
  FOR g IN SELECT value FROM jsonb_array_elements(pages) LOOP
   IF g->>'code' NOT LIKE '%.view' THEN RAISE EXCEPTION 'Only page views allowed' USING ERRCODE='22023';END IF;
   IF g->>'code' IN ('categories.view','employee_schedule.view','departments.view') AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(pages) x WHERE x->>'code'=CASE g->>'code' WHEN 'categories.view' THEN 'services.view' WHEN 'employee_schedule.view' THEN 'employees.view' ELSE 'rooms.view' END AND (x->>'scope'='entire_clinic' OR x->>'scope'=g->>'scope')) THEN RAISE EXCEPTION 'Parent page required' USING ERRCODE='22023';END IF;
  END LOOP;
  PERFORM governance.check_grants(pages,public.context_user(),tid);
 END IF;
 IF pages IS DISTINCT FROM p.page_grants AND EXISTS(SELECT 1 FROM jsonb_array_elements(coalesce(pages,'[]')) x WHERE x->>'code'='cashier.view') AND NOT EXISTS(SELECT 1 FROM public.employee_cashier_secrets WHERE tenant_id=tid AND employee_id=pid) AND NOT(body ? 'cashier_pin_hash') THEN RAISE EXCEPTION 'Cashier PIN required' USING ERRCODE='22023';END IF;
 IF body ? 'cashier_pin_hash' THEN
  PERFORM governance.tenant('employees.assign_role');
  IF NOT EXISTS(SELECT 1 FROM jsonb_array_elements(coalesce(pages,'[]')) x WHERE x->>'code'='cashier.view') OR body->>'cashier_pin_hash' NOT LIKE '$argon2id$%' THEN RAISE EXCEPTION 'Invalid cashier PIN' USING ERRCODE='22023';END IF;
 END IF;
 IF enabled AND NOT status THEN RAISE EXCEPTION 'Inactive cannot access' USING ERRCODE='22023';END IF;
 IF body ? 'branch_ids' THEN
  SELECT coalesce(array_agg(value::uuid),'{}'::uuid[]) INTO ids FROM jsonb_array_elements_text(body->'branch_ids');
 ELSIF p.membership_id IS NOT NULL THEN
  SELECT coalesce(array_agg(branch_id),'{}'::uuid[]) INTO ids FROM public.membership_branches WHERE tenant_id=tid AND membership_id=p.membership_id;
 ELSE ids:='{}'::uuid[];
 END IF;
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
  PERFORM governance.assert_branch_delegation('employee_assign',mid,NULL);
 END IF;
 -- Assigned-branch page grants obey the same branch delegation ceiling.
 IF body ? 'page_grants' AND pages IS DISTINCT FROM p.page_grants THEN
  FOR g IN SELECT value FROM jsonb_array_elements(pages) LOOP
   IF g->>'scope'='assigned_branches' AND governance.scope_for(public.context_user(),tid,g->>'code')='assigned_branches' THEN
    FOREACH bid IN ARRAY ids LOOP
     IF NOT public.branch_assigned(bid) THEN RAISE EXCEPTION 'Page branch delegation denied' USING ERRCODE='42501';END IF;
    END LOOP;
   END IF;
  END LOOP;
 END IF;
 -- One editor command publishes one version, including all its internal branch/member writes.
 -- A newly created membership has no externally observable earlier version.
 IF mid IS NOT NULL THEN
  UPDATE public.tenant_memberships SET employee_version=CASE WHEN target IS NULL THEN 1 ELSE p.version+1 END WHERE tenant_id=tid AND id=mid;
 END IF;
 INSERT INTO public.employee_profiles(id,tenant_id,membership_id,profile,active,version,page_grants)
 VALUES(pid,tid,mid,(body->'profile')||jsonb_build_object('branch',body->>'branch_id'),status,CASE WHEN target IS NULL THEN 1 ELSE p.version+1 END,pages)
 ON CONFLICT(id) DO UPDATE SET membership_id=excluded.membership_id,profile=excluded.profile,page_grants=excluded.page_grants,active=excluded.active,version=excluded.version,updated_at=now();
 IF body ? 'cashier_pin_hash' THEN
  INSERT INTO public.employee_cashier_secrets(tenant_id,employee_id,pin_hash) VALUES(tid,pid,body->>'cashier_pin_hash') ON CONFLICT(tenant_id,employee_id) DO UPDATE SET pin_hash=excluded.pin_hash;
 END IF;
 IF mid IS NOT NULL AND pages IS DISTINCT FROM p.page_grants THEN
  DELETE FROM public.sessions WHERE user_id=(SELECT user_id FROM public.tenant_memberships WHERE tenant_id=tid AND id=mid);
 END IF;
 PERFORM governance.event('employee.profile_saved' ,'employee',pid,tid);
 result:=jsonb_build_object('id',pid,'version',CASE WHEN target IS NULL THEN 1 ELSE p.version+1 END);
 INSERT INTO public.access_editor_intents VALUES(tid,public.context_user(),intent,'staff.save',digest,result);RETURN result;
END $$;
CREATE OR REPLACE FUNCTION governance.staff_directory(target uuid,page_offset integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN
 PERFORM governance.actor();
 IF NOT public.is_active_member(public.context_tenant()) OR public.permission_scope('employees.view') IS NULL THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501';END IF;
 RETURN (SELECT coalesce(jsonb_agg(to_jsonb(x)),'[]'::jsonb) FROM (
 SELECT coalesce(p.id,m.id) id,m.id membership_id,m.user_id,m.role_id,(SELECT name FROM public.tenant_roles WHERE id=m.role_id AND tenant_id=m.tenant_id) role_name,coalesce(p.active,m.active) active,coalesce(m.active,false) access,u.login,u.name,
 coalesce(p.profile,'{}'::jsonb) profile,p.page_grants,EXISTS(SELECT 1 FROM public.employee_cashier_secrets secret WHERE secret.tenant_id=p.tenant_id AND secret.employee_id=p.id) cashier_pin_set,coalesce(m.employee_version,p.version,1) version,
 coalesce((SELECT jsonb_agg(b.branch_id) FROM public.membership_branches b WHERE b.tenant_id=m.tenant_id AND b.membership_id=m.id),'[]'::jsonb) branch_ids
 FROM public.employee_profiles p FULL JOIN public.tenant_memberships m ON m.id=p.membership_id AND m.tenant_id=p.tenant_id LEFT JOIN public.users u ON u.id=m.user_id
 WHERE coalesce(p.tenant_id,m.tenant_id)=public.context_tenant() AND (target IS NULL OR coalesce(p.id,m.id)=target)
 AND (public.permission_scope('employees.view')='entire_clinic' OR (m.id IS NOT NULL AND public.can_view_member(m.id)))
 ORDER BY coalesce(p.id,m.id) LIMIT 50 OFFSET greatest(0,page_offset)) x);
END $$;

CREATE OR REPLACE FUNCTION governance.assert_branch_delegation(operation_value text, result_id uuid, request_id uuid) RETURNS void
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
 IF operation_value IN ('employee_create','employee_assign','employee_reset','link_accept') THEN
  PERFORM governance.check_grants(coalesce((SELECT page_grants FROM public.employee_profiles WHERE tenant_id=tid AND membership_id=result_id),'[]'::jsonb),aid,tid);
 END IF;
 IF rid IS NULL THEN RAISE EXCEPTION 'Role unavailable' USING ERRCODE='42501'; END IF;
 FOR g IN SELECT p.code FROM public.role_permissions rp JOIN public.permissions p ON p.id=rp.permission_id
 WHERE rp.tenant_id=tid AND rp.role_id=rid AND rp.scope='assigned_branches'
 UNION SELECT x->>'code' FROM public.employee_profiles ep CROSS JOIN LATERAL jsonb_array_elements(coalesce(ep.page_grants,'[]')) x WHERE ep.tenant_id=tid AND ep.membership_id=result_id AND operation_value IN ('employee_create','employee_assign','employee_reset','link_accept') AND x->>'scope'='assigned_branches' LOOP
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
