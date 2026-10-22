CREATE FUNCTION governance.employee_membership_version() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN
 IF NEW.role_id IS DISTINCT FROM OLD.role_id OR NEW.active IS DISTINCT FROM OLD.active THEN
  NEW.employee_version:=OLD.employee_version+1;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER employee_membership_version BEFORE UPDATE OF role_id,active ON tenant_memberships FOR EACH ROW EXECUTE FUNCTION governance.employee_membership_version();
CREATE FUNCTION governance.employee_branch_version() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN
 IF TG_OP='UPDATE' AND (OLD.tenant_id,OLD.membership_id,OLD.branch_id) IS NOT DISTINCT FROM (NEW.tenant_id,NEW.membership_id,NEW.branch_id) THEN RETURN NEW; END IF;
 IF TG_OP<>'INSERT' THEN
  UPDATE public.tenant_memberships SET employee_version=employee_version+1 WHERE tenant_id=OLD.tenant_id AND id=OLD.membership_id;
 END IF;
 IF TG_OP<>'DELETE' THEN
  IF TG_OP='INSERT' OR (OLD.tenant_id,OLD.membership_id) IS DISTINCT FROM (NEW.tenant_id,NEW.membership_id) THEN
   UPDATE public.tenant_memberships SET employee_version=employee_version+1 WHERE tenant_id=NEW.tenant_id AND id=NEW.membership_id;
  END IF;
 END IF;
 RETURN NULL;
END $$;
CREATE TRIGGER employee_branch_version AFTER INSERT OR UPDATE OR DELETE ON membership_branches FOR EACH ROW EXECUTE FUNCTION governance.employee_branch_version();

CREATE OR REPLACE FUNCTION governance.staff_command(target uuid,body jsonb,intent uuid,expected bigint) RETURNS jsonb
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
 -- One editor command publishes one version, including all its internal branch/member writes.
 -- A newly created membership has no externally observable earlier version.
 IF mid IS NOT NULL THEN
  UPDATE public.tenant_memberships SET employee_version=CASE WHEN target IS NULL THEN 1 ELSE p.version+1 END WHERE tenant_id=tid AND id=mid;
 END IF;
 INSERT INTO public.employee_profiles(id,tenant_id,membership_id,profile,active,version)
 VALUES(pid,tid,mid,(body->'profile')||jsonb_build_object('branch',body->>'branch_id'),status,CASE WHEN target IS NULL THEN 1 ELSE p.version+1 END)
 ON CONFLICT(id) DO UPDATE SET membership_id=excluded.membership_id,profile=excluded.profile,active=excluded.active,version=excluded.version,updated_at=now();
 PERFORM governance.event('employee.profile_saved','employee',pid,tid);
 result:=jsonb_build_object('id',pid,'version',CASE WHEN target IS NULL THEN 1 ELSE p.version+1 END);
 INSERT INTO public.access_editor_intents VALUES(tid,public.context_user(),intent,'staff.save',digest,result);RETURN result;
END $$;
CREATE OR REPLACE FUNCTION governance.staff_directory(target uuid,page_offset integer) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN
 PERFORM governance.actor();
 IF NOT public.is_active_member(public.context_tenant()) OR public.permission_scope('employees.view') IS NULL THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501';END IF;
 RETURN (SELECT coalesce(jsonb_agg(to_jsonb(x)),'[]'::jsonb) FROM (
 SELECT coalesce(p.id,m.id) id,m.id membership_id,m.user_id,m.role_id,(SELECT name FROM public.tenant_roles WHERE id=m.role_id AND tenant_id=m.tenant_id) role_name,coalesce(p.active,m.active) active,coalesce(m.active,false) access,u.login,u.name,
 coalesce(p.profile,'{}'::jsonb) profile,coalesce(m.employee_version,p.version,1) version,
 coalesce((SELECT jsonb_agg(b.branch_id) FROM public.membership_branches b WHERE b.tenant_id=m.tenant_id AND b.membership_id=m.id),'[]'::jsonb) branch_ids
 FROM public.employee_profiles p FULL JOIN public.tenant_memberships m ON m.id=p.membership_id AND m.tenant_id=p.tenant_id LEFT JOIN public.users u ON u.id=m.user_id
 WHERE coalesce(p.tenant_id,m.tenant_id)=public.context_tenant() AND (target IS NULL OR coalesce(p.id,m.id)=target)
 AND (public.permission_scope('employees.view')='entire_clinic' OR (m.id IS NOT NULL AND public.can_view_member(m.id)))
 ORDER BY coalesce(p.id,m.id) LIMIT 50 OFFSET greatest(0,page_offset)) x);
END $$;
CREATE OR REPLACE FUNCTION governance.link_profile_resolved() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE p public.employee_profiles;mid uuid;
BEGIN
 IF NEW.status='accepted' AND OLD.status='pending' THEN
  SELECT * INTO p FROM public.employee_profiles WHERE tenant_id=NEW.tenant_id AND pending_link_id=NEW.id FOR UPDATE;
  IF FOUND THEN
   IF NOT p.active OR p.membership_id IS NOT NULL THEN RAISE EXCEPTION 'Profile changed' USING ERRCODE='42501';END IF;
   SELECT id INTO mid FROM public.tenant_memberships WHERE tenant_id=NEW.tenant_id AND user_id=NEW.target_user_id;
   UPDATE public.tenant_memberships SET employee_version=p.version+1 WHERE tenant_id=p.tenant_id AND id=mid;
   UPDATE public.employee_profiles SET membership_id=mid,pending_link_id=NULL,version=version+1,updated_at=now() WHERE id=p.id AND tenant_id=p.tenant_id;
  END IF;
 END IF;RETURN NEW;
END $$;
