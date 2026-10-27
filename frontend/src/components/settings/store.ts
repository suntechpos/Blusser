import { ref } from "vue";
import { session, can, revalidateSession } from "../../services/session";
import { clinicRequest, errorText } from "../../services/api";
import { blankEmployee, type Employee } from "../../mocks/employees";
export interface PermissionSection {
  id: string;
  label: string;
  parent?: string;
  permissions: { code: string; label: string; server: boolean }[];
}
interface RoleDraft {
  id: string;
  name: string;
  note: string;
  permissions: string[];
  protected?: boolean;
}
export interface ServerRole extends RoleDraft {
  code: string;
  version: number;
  employee_count: number;
  grants: { code: string; scope: string }[];
  active: boolean;
}
/** A child permission may be narrower than its parent view. */
export function scopeContains(parent: string | undefined, child: string): boolean {
  const scopes = ["own", "assigned_branches", "entire_clinic"];
  const parentRank = parent ? scopes.indexOf(parent) : -1;
  const childRank = scopes.indexOf(child);
  return childRank >= 0 && parentRank >= childRank;
}
interface ServerPermission {
  code: string;
  label: string;
  section: string;
  section_label: string;
  view: string;
  parent: string | null;
  allowed_scopes: string[];
  ui_only: boolean;
}
interface ServerEmployee {
  id: string;
  membership_id: string | null;
  user_id: string | null;
  role_id: string | null;
  role_name?: string;
  active: boolean;
  access: boolean;
  login: string | null;
  name: string | null;
  profile: Partial<Employee>;
  version: number;
  branch_ids: string[];
  page_grants?: { code: string; scope: string }[] | null;
  cashier_pin_set?: boolean;
}
export type EditorEmployee = Employee & {
  version?: number;
  membershipId?: string | null;
  branchIds?: string[];
};
const stores = new Map<string, ReturnType<typeof makeStore>>();
function makeStore() {
  const state = ref({
    roles: [] as ServerRole[],
    employees: [] as EditorEmployee[],
  });
  const error = ref("");
  const loading = ref(false);
  const catalog = ref<ServerPermission[]>([]);
  const sections = ref<PermissionSection[]>([]);
  async function all<T>(path: string) {
    const rows: T[] = [];
    for (let page = 1; ; page++) {
      const r = await clinicRequest<{ data: T[] }>(path, {
        params: {
          page,
          ...(path === "employees" ? { format: "profile" } : {}),
        },
      });
      rows.push(...r.data.data);
      if (r.data.data.length < 50) break;
    }
    return rows;
  }
  async function loadRoles() {
    if (!can("roles.view")) return;
    const [roles, perms] = await Promise.all([
      all<Omit<ServerRole, "permissions">>("roles"),
      clinicRequest<{ data: ServerPermission[] }>("permissions"),
    ]);
    state.value.roles = roles.map((r) => ({
      ...r,
      permissions: r.grants.map((g) => g.code),
    }));
    catalog.value = perms.data.data;
    const groups: PermissionSection[] = [];
    for (const p of catalog.value) {
      let g = groups.find((g) => g.id === p.section);
      if (!g) {
        g = {
          id: p.section,
          label: p.section_label,
          parent: p.parent ?? undefined,
          permissions: [],
        };
        groups.push(g);
      }
      g.permissions.push({ code: p.code, label: p.label, server: !p.ui_only });
    }
    for (const g of groups)
      g.permissions.sort(
        (a, b) =>
          Number(b.code.endsWith(".view")) - Number(a.code.endsWith(".view")),
      );
    sections.value = groups.sort(
      (a, b) => Number(b.id === "cashier") - Number(a.id === "cashier"),
    );
  }
  async function loadEmployees() {
    const rows = await all<ServerEmployee>("employees");
    state.value.employees = rows.map((r) => ({
      ...blankEmployee(),
      ...Object.fromEntries(
        Object.entries(r.profile).map(([k, v]) => [
          k,
          v ?? blankEmployee()[k as keyof Employee] ?? "",
        ]),
      ),
      id: r.id,
      first: r.profile.first ?? r.name?.split(" ")[0] ?? "",
      last: r.profile.last ?? r.name?.split(" ").slice(1).join(" ") ?? "",
      login: r.login ?? "",
      roleId: r.role_id ?? "",
      role: r.role_name ?? r.role_id ?? "",
      branch: r.profile.branch ?? r.branch_ids[0] ?? "",
      access: r.access && r.active,
      active: r.active,
      photo: "",
      schedule: null,
      exceptions: [],
      version: r.version,
      membershipId: r.membership_id,
      branchIds: r.branch_ids,
      pageGrants: r.page_grants,
      cashierPinSet: r.cashier_pin_set,
    }));
  }
  async function refresh(kind: "roles" | "employees") {
    loading.value = true;
    error.value = "";
    try {
      if (kind === "roles") await loadRoles();
      else {
        await Promise.all([loadEmployees(), loadRoles()]);
      }
    } catch (e) {
      error.value = errorText(e);
    } finally {
      loading.value = false;
    }
  }
  async function saveRole(draft: ServerRole, creating: boolean) {
    if (
      !can(creating ? "roles.create" : "roles.update") ||
      !can("roles.manage_permissions")
    )
      throw Error("Bu amal uchun ruxsat yo‘q.");
    if (creating && !draft.code)
      draft.code = `custom_${crypto.randomUUID().replaceAll("-", "")}`;
    const body = {
      code: draft.code,
      name: draft.name,
      note: draft.note,
      grants: draft.permissions.map((code) => ({
        code,
        scope:
          draft.grants.find((g) => g.code === code)?.scope ??
          allowedScopes(code)[0],
      })),
    };
    const r = await clinicRequest<{ id: string }>(
      creating ? "roles" : `roles/${draft.id}`,
      {
        method: creating ? "POST" : "PUT",
        body,
        etag: creating ? undefined : `"${draft.version}"`,
      },
    );
    await loadRoles();
    await revalidateSession();
    return r.data.id;
  }
  async function removeRole(role: ServerRole) {
    if (!can("roles.delete")) throw Error("Bu amal uchun ruxsat yo‘q.");
    await clinicRequest(`roles/${role.id}`, {
      method: "DELETE",
      etag: `"${role.version}"`,
    });
    await loadRoles();
  }
  function allowedScopes(code: string) {
    const own = session.grants?.[code];
    return (
      catalog.value.find((p) => p.code === code)?.allowed_scopes ?? []
    ).filter((s) => own === "entire_clinic" || s === own);
  }
  async function saveEmployee(
    e: EditorEmployee,
    password?: string,
    pin?: { value: string; confirmation: string },
    onCommitted?: (id: string) => void,
  ) {
    if (!can(e.id ? "employees.update" : "employees.create"))
      throw Error("Bu amal uchun ruxsat yo‘q.");
    const profile = Object.fromEntries(
      [
        "first",
        "last",
        "patronymic",
        "phone",
        "dob",
        "gender",
        "address",
        "position",
        "department",
        "hired",
        "specialty",
        "duration",
        "note",
      ].map((k) => [k, e[k as keyof Employee]]),
    );
    const previous = state.value.employees.find((row) => row.id === e.id);
    const branchIds =
      e.branchIds ?? previous?.branchIds ?? (e.access ? [e.branch] : []);
    const sameBranches = (a: string[], b: string[]) =>
      JSON.stringify([...new Set(a)].sort()) ===
      JSON.stringify([...new Set(b)].sort());
    const branchesChanged =
      !!e.id && !sameBranches(branchIds, previous?.branchIds ?? []);
    if (branchesChanged && !can("employees.assign_branches"))
      throw Error("Filial biriktirish uchun ruxsat yo‘q.");
    const body = {
      profile,
      ...(e.pageGrants != null ? { page_grants: e.pageGrants } : {}),
      ...(pin
        ? { cashier_pin: pin.value, cashier_pin_confirmation: pin.confirmation }
        : {}),
      active: e.active,
      access: e.access,
      login: e.access ? e.login : null,
      role_id: e.access ? e.roleId : null,
      branch_id: e.branch,
      ...(!e.id || (e.access && (!previous?.membershipId || branchesChanged))
        ? { branch_ids: e.access ? branchIds : [] }
        : {}),
      ...(password ? { temporary_password: password } : {}),
    };
    const saved = await clinicRequest<{ id: string }>(
      e.id ? `employees/${e.id}` : "employees",
      {
        method: e.id ? "PATCH" : "POST",
        body,
        etag: e.id ? `"${e.version}"` : undefined,
      },
    );
    onCommitted?.(saved.data.id);
    await loadEmployees();
    await loadRoles();
    await revalidateSession();
    return saved.data.id;
  }
  return {
    state,
    error,
    loading,
    catalog,
    sections,
    refresh,
    saveRole,
    removeRole,
    allowedScopes,
    saveEmployee,
  };
}
export function useRolesStore() {
  const key = `${session.user?.id}:${session.user?.active_tenant_id}:${session.epoch}`;
  if (!stores.has(key)) {
    stores.clear();
    stores.set(key, makeStore());
  }
  return stores.get(key)!;
}
