REVOKE ALL ON ALL TABLES IN SCHEMA public FROM blusser_app;
REVOKE ALL ON ALL FUNCTIONS IN SCHEMA public FROM PUBLIC;
GRANT SELECT ON users, tenants, branches, tenant_roles, tenant_memberships,
    membership_branches, permissions, role_permissions TO blusser_security;

CREATE FUNCTION public.context_user() RETURNS uuid LANGUAGE sql STABLE
SET search_path = pg_catalog AS $$ SELECT nullif(current_setting('app.user_id', true), '')::uuid $$;
CREATE FUNCTION public.context_tenant() RETURNS uuid LANGUAGE sql STABLE
SET search_path = pg_catalog AS $$ SELECT nullif(current_setting('app.tenant_id', true), '')::uuid $$;

CREATE FUNCTION public.is_platform_admin() RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = pg_catalog AS $$
    SELECT EXISTS (SELECT 1 FROM public.users WHERE id = public.context_user() AND active AND is_platform_admin)
$$;
CREATE FUNCTION public.is_active_member(target_tenant uuid) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = pg_catalog AS $$
    SELECT EXISTS (
        SELECT 1 FROM public.tenant_memberships m
        JOIN public.users u ON u.id = m.user_id AND u.active
        JOIN public.tenants t ON t.id = m.tenant_id AND t.status = 'active'
        JOIN public.tenant_roles r ON (r.tenant_id, r.id) = (m.tenant_id, m.role_id) AND r.active
        WHERE m.user_id = public.context_user() AND m.tenant_id = target_tenant AND m.active
    )
$$;
CREATE FUNCTION public.permission_scope(permission_code text) RETURNS text LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = pg_catalog AS $$
    SELECT rp.scope FROM public.tenant_memberships m
    JOIN public.tenant_roles r ON (r.tenant_id, r.id) = (m.tenant_id, m.role_id) AND r.active
    JOIN public.role_permissions rp ON (rp.tenant_id, rp.role_id) = (m.tenant_id, m.role_id)
    JOIN public.permissions p ON p.id = rp.permission_id AND p.code = permission_code
    WHERE m.user_id = public.context_user() AND m.tenant_id = public.context_tenant()
      AND m.active AND public.is_active_member(m.tenant_id)
$$;
CREATE FUNCTION public.branch_assigned(target_branch uuid) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = pg_catalog AS $$
    SELECT EXISTS (
        SELECT 1 FROM public.membership_branches mb
        JOIN public.tenant_memberships m ON (m.tenant_id, m.id) = (mb.tenant_id, mb.membership_id)
        JOIN public.branches b ON (b.tenant_id, b.id) = (mb.tenant_id, mb.branch_id) AND b.active
        WHERE m.user_id = public.context_user() AND m.tenant_id = public.context_tenant()
          AND mb.branch_id = target_branch AND m.active AND public.is_active_member(m.tenant_id)
    )
$$;
CREATE FUNCTION public.can_access(permission_code text, row_user uuid, row_branch uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog AS $$
    SELECT coalesce(CASE public.permission_scope(permission_code)
        WHEN 'entire_clinic' THEN true
        WHEN 'own' THEN row_user = public.context_user()
        WHEN 'assigned_branches' THEN public.branch_assigned(row_branch)
        ELSE false END, false)
$$;
CREATE FUNCTION public.can_view_member(target_membership uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog AS $$
    SELECT EXISTS (
        SELECT 1 FROM public.tenant_memberships target
        WHERE target.id = target_membership AND target.tenant_id = public.context_tenant()
          AND (public.can_access('employees.view', target.user_id, NULL)
            OR (public.permission_scope('employees.view') = 'assigned_branches' AND EXISTS (
                SELECT 1 FROM public.membership_branches mb
                WHERE mb.tenant_id = target.tenant_id AND mb.membership_id = target.id
                  AND public.branch_assigned(mb.branch_id))))
    )
$$;

CREATE FUNCTION public.validate_permission_scope() RETURNS trigger
LANGUAGE plpgsql SET search_path = pg_catalog AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM public.permissions WHERE id = NEW.permission_id AND NEW.scope = ANY(allowed_scopes)) THEN
        RAISE EXCEPTION 'Unsupported permission scope' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END
$$;
CREATE TRIGGER validate_permission_scope BEFORE INSERT OR UPDATE ON role_permissions
FOR EACH ROW EXECUTE FUNCTION public.validate_permission_scope();

ALTER FUNCTION public.context_user() OWNER TO blusser_security;
ALTER FUNCTION public.context_tenant() OWNER TO blusser_security;
ALTER FUNCTION public.is_platform_admin() OWNER TO blusser_security;
ALTER FUNCTION public.is_active_member(uuid) OWNER TO blusser_security;
ALTER FUNCTION public.permission_scope(text) OWNER TO blusser_security;
ALTER FUNCTION public.branch_assigned(uuid) OWNER TO blusser_security;
ALTER FUNCTION public.can_access(text,uuid,uuid) OWNER TO blusser_security;
ALTER FUNCTION public.can_view_member(uuid) OWNER TO blusser_security;

REVOKE ALL ON ALL FUNCTIONS IN SCHEMA public FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.context_user(), public.context_tenant(), public.is_platform_admin(),
    public.is_active_member(uuid), public.permission_scope(text), public.branch_assigned(uuid),
    public.can_access(text,uuid,uuid), public.can_view_member(uuid) TO blusser_app;

ALTER TABLE users ENABLE ROW LEVEL SECURITY;
ALTER TABLE users FORCE ROW LEVEL SECURITY;
CREATE POLICY users_self ON users FOR SELECT TO blusser_app USING (id = public.context_user());
ALTER TABLE tenants ENABLE ROW LEVEL SECURITY;
ALTER TABLE tenants FORCE ROW LEVEL SECURITY;
CREATE POLICY tenants_visible ON tenants FOR SELECT TO blusser_app
USING (public.is_platform_admin() OR public.is_active_member(id));

CREATE POLICY membership_self ON tenant_memberships FOR SELECT TO blusser_app
USING (user_id = public.context_user() AND active AND public.is_active_member(tenant_id));
CREATE POLICY memberships_tenant ON tenant_memberships FOR SELECT TO blusser_app
USING (tenant_id = public.context_tenant() AND public.can_view_member(id));
CREATE POLICY roles_read ON tenant_roles FOR SELECT TO blusser_app
USING (tenant_id = public.context_tenant() AND public.permission_scope('roles.view') = 'entire_clinic');
CREATE POLICY grants_read ON role_permissions FOR SELECT TO blusser_app
USING (tenant_id = public.context_tenant() AND public.permission_scope('roles.view') = 'entire_clinic');
CREATE POLICY member_branches_read ON membership_branches FOR SELECT TO blusser_app
USING (tenant_id = public.context_tenant() AND public.can_view_member(membership_id));
CREATE POLICY branches_read ON branches FOR SELECT TO blusser_app
USING (tenant_id = public.context_tenant() AND public.can_access('branches.view', NULL, id));
CREATE POLICY services_read ON services FOR SELECT TO blusser_app
USING (tenant_id = public.context_tenant() AND public.permission_scope('services.view') = 'entire_clinic');
CREATE POLICY audit_read ON audit_logs FOR SELECT TO blusser_app
USING ((tenant_id = public.context_tenant() AND public.can_access('audit.view', actor_user_id, branch_id))
    OR (tenant_id IS NULL AND public.is_platform_admin()));

-- No write policies or grants in Foundation. Role, membership, linking and audit
-- mutations remain closed until their full authorized transaction services exist.
-- There is deliberately no SELECT policy for link requests in this sprint.
GRANT SELECT ON tenants, branches, tenant_roles, role_permissions, tenant_memberships,
    membership_branches, services, audit_logs, permissions TO blusser_app;
GRANT SELECT (id, login, name, email, active, must_change_password, credential_version, is_platform_admin)
    ON users TO blusser_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON sessions TO blusser_app;
