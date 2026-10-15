CREATE TABLE users (
    id uuid PRIMARY KEY,
    login varchar(64) NOT NULL UNIQUE CHECK (login ~ '^[a-z0-9._-]{3,64}$'),
    name varchar(255) NOT NULL,
    email varchar(255),
    password_hash varchar(255) NOT NULL CHECK (password_hash LIKE '$argon2id$%'),
    must_change_password boolean NOT NULL DEFAULT true,
    temporary_password_expires_at timestamptz,
    password_changed_at timestamptz,
    credential_version bigint NOT NULL DEFAULT 1 CHECK (credential_version > 0),
    is_platform_admin boolean NOT NULL DEFAULT false,
    active boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
    CHECK ((must_change_password AND temporary_password_expires_at IS NOT NULL)
        OR (NOT must_change_password AND temporary_password_expires_at IS NULL))
);
CREATE TABLE tenants (
    id uuid PRIMARY KEY, name varchar(255) NOT NULL, slug varchar(100) NOT NULL UNIQUE,
    status varchar(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended')),
    created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE branches (
    id uuid PRIMARY KEY, tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    name varchar(255) NOT NULL, code varchar(64) NOT NULL, active boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, id), UNIQUE (tenant_id, code)
);
CREATE INDEX branches_active_idx ON branches(tenant_id, active);
CREATE TABLE tenant_roles (
    id uuid PRIMARY KEY, tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    code varchar(64) NOT NULL, name varchar(255) NOT NULL, active boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, id), UNIQUE (tenant_id, code)
);
CREATE INDEX tenant_roles_active_idx ON tenant_roles(tenant_id, active);
CREATE TABLE permissions (
    id uuid PRIMARY KEY, code varchar(100) NOT NULL UNIQUE,
    allowed_scopes text[] NOT NULL CHECK (cardinality(allowed_scopes) > 0 AND
        allowed_scopes <@ ARRAY['own','assigned_branches','entire_clinic']::text[])
);
CREATE TABLE role_permissions (
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    role_id uuid NOT NULL, permission_id uuid NOT NULL REFERENCES permissions(id) ON DELETE RESTRICT,
    scope varchar(30) NOT NULL CHECK (scope IN ('own','assigned_branches','entire_clinic')),
    PRIMARY KEY (tenant_id, role_id, permission_id),
    FOREIGN KEY (tenant_id, role_id) REFERENCES tenant_roles(tenant_id, id) ON DELETE RESTRICT
);
CREATE TABLE tenant_memberships (
    id uuid PRIMARY KEY, tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    user_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT, role_id uuid NOT NULL,
    active boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, user_id), UNIQUE (tenant_id, id),
    FOREIGN KEY (tenant_id, role_id) REFERENCES tenant_roles(tenant_id, id) ON DELETE RESTRICT
);
CREATE INDEX memberships_user_idx ON tenant_memberships(user_id, active, tenant_id);
CREATE INDEX memberships_role_idx ON tenant_memberships(tenant_id, role_id);
CREATE TABLE membership_branches (
    id uuid PRIMARY KEY, tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    membership_id uuid NOT NULL, branch_id uuid NOT NULL,
    UNIQUE (tenant_id, membership_id, branch_id),
    FOREIGN KEY (tenant_id, membership_id) REFERENCES tenant_memberships(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, branch_id) REFERENCES branches(tenant_id, id) ON DELETE RESTRICT
);
CREATE INDEX membership_branches_branch_idx ON membership_branches(tenant_id, branch_id, membership_id);
CREATE TABLE services (
    id uuid PRIMARY KEY, tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    name varchar(255) NOT NULL, code varchar(64) NOT NULL, description text,
    active boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, id), UNIQUE (tenant_id, code)
);
CREATE INDEX services_active_idx ON services(tenant_id, active);
CREATE TABLE membership_link_requests (
    id uuid PRIMARY KEY, tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    target_user_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    initiated_by_user_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT, role_id uuid NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','accepted','rejected','expired')),
    expires_at timestamptz NOT NULL, resolved_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, id),
    FOREIGN KEY (tenant_id, role_id) REFERENCES tenant_roles(tenant_id, id) ON DELETE RESTRICT,
    CHECK (target_user_id <> initiated_by_user_id)
);
CREATE UNIQUE INDEX pending_link_unique ON membership_link_requests(tenant_id, target_user_id) WHERE status = 'pending';
CREATE TABLE membership_link_request_branches (
    tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, request_id uuid NOT NULL, branch_id uuid NOT NULL,
    PRIMARY KEY (tenant_id, request_id, branch_id),
    FOREIGN KEY (tenant_id, request_id) REFERENCES membership_link_requests(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, branch_id) REFERENCES branches(tenant_id, id) ON DELETE RESTRICT
);
CREATE TABLE audit_logs (
    id uuid PRIMARY KEY, tenant_id uuid REFERENCES tenants(id) ON DELETE RESTRICT,
    actor_user_id uuid REFERENCES users(id) ON DELETE RESTRICT, branch_id uuid,
    action varchar(100) NOT NULL, resource_type varchar(100) NOT NULL, resource_id uuid,
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(metadata) = 'object'),
    request_id uuid NOT NULL, created_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (tenant_id, branch_id) REFERENCES branches(tenant_id, id) ON DELETE RESTRICT,
    CHECK (branch_id IS NULL OR tenant_id IS NOT NULL)
);
CREATE INDEX audit_logs_tenant_time_idx ON audit_logs(tenant_id, created_at, id);
CREATE TABLE sessions (
    id varchar(255) PRIMARY KEY, user_id uuid REFERENCES users(id) ON DELETE RESTRICT,
    credential_version bigint CHECK (credential_version > 0),
    auth_stage varchar(30) NOT NULL DEFAULT 'anonymous' CHECK (auth_stage IN ('anonymous','password_change_required','full')),
    last_reauthenticated_at timestamptz, ip_address varchar(45), user_agent text,
    payload text NOT NULL, last_activity integer NOT NULL,
    CHECK ((user_id IS NULL AND credential_version IS NULL AND auth_stage = 'anonymous')
        OR (user_id IS NOT NULL AND credential_version IS NOT NULL AND auth_stage <> 'anonymous'))
);
CREATE INDEX sessions_user_idx ON sessions(user_id);
CREATE INDEX sessions_activity_idx ON sessions(last_activity);
CREATE TABLE auth_rate_limits (
    key_digest char(64) PRIMARY KEY CHECK (key_digest ~ '^[a-f0-9]{64}$'),
    window_started_at timestamptz NOT NULL, attempts integer NOT NULL CHECK (attempts >= 0),
    blocked_until timestamptz, expires_at timestamptz NOT NULL
);
CREATE INDEX auth_rate_limits_expiry_idx ON auth_rate_limits(expires_at);
