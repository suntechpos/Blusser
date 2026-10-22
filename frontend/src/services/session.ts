import { reactive } from "vue";
import axios from "axios";
import {
  api,
  getCurrentUser,
  clinicRequest,
  clearIntents,
  type AuthUser,
} from "./api";
import { contextGate } from "./context";
export interface Clinic {
  id: string;
  name: string;
  role: string;
}
export interface Branch {
  id: string;
  name: string;
  active: boolean;
  timezone?: string;
}
export const session = reactive({
  user: null as AuthUser | null,
  clinics: [] as Clinic[],
  branches: [] as Branch[],
  branch: "",
  epoch: 0,
  switching: false,
  checking: true,
  error: "",
  grants: null as Record<string, string> | null,
});
export function clearClinic() {
  contextGate.reset();
  clearIntents();
  session.branches = [];
  session.branch = "";
  session.grants = null;
  session.epoch++;
}
export function expire() {
  clearClinic();
  session.user = null;
  session.clinics = [];
  session.switching = false;
}
api.interceptors.response.use(
  (r) => r,
  (e) => {
    if (
      !e.config?.signal?.aborted &&
      [401, 419].includes(e.response?.status) &&
      !e.config?.url?.endsWith("/auth/login")
    )
      expire();
    return Promise.reject(e);
  },
);
export const can = (code: string) =>
  ["own", "assigned_branches", "entire_clinic"].includes(
    session.grants?.[code] ?? "",
  );
export async function refreshSession() {
  const token = contextGate.capture();
  const user = await getCurrentUser(token.signal);
  if (!token.current()) return;
  session.user = user;
  if (session.user.must_change_password || session.user.is_platform_admin)
    return;
  const clinics = await api.get<{ data: Clinic[] }>("/api/v1/auth/clinics", {
    signal: token.signal,
  });
  if (!token.current()) return;
  session.clinics = clinics.data.data;
  if (
    session.user.active_tenant_id &&
    !session.clinics.some((c) => c.id === session.user!.active_tenant_id)
  ) {
    clearClinic();
    session.user.active_tenant_id = null;
  }
  if (!session.user.active_tenant_id && session.clinics.length === 1)
    await selectClinic(session.clinics[0]!.id);
  else if (session.user.active_tenant_id) await loadContext();
}
interface ClinicAccess {
  grants: Record<string, string>;
  branches: Branch[];
}
async function readContext(): Promise<ClinicAccess> {
  const r = await clinicRequest<{
    grants: Record<string, string>;
    branches: Branch[];
    timezone: string;
  }>("context");
  let branches = r.data.branches;
  if (r.data.grants["branches.view"] !== undefined) {
    const all: Branch[] = [];
    for (let page = 1; ; page++) {
      const result = await clinicRequest<{ data: Branch[] }>("branches", {
        params: { page },
      });
      all.push(...result.data.data);
      if (result.data.data.length < 50) break;
    }
    branches = all
      .filter((b) => b.active)
      .map((b) => ({ ...b, timezone: b.timezone ?? r.data.timezone }));
  }
  return { grants: r.data.grants, branches };
}
async function loadContext() {
  const token = contextGate.capture();
  const access = await readContext();
  if (!token.current()) return;
  session.grants = access.grants;
  session.branches = access.branches;
}
function accessSignature(access: ClinicAccess) {
  return JSON.stringify([
    Object.entries(access.grants).sort(([a], [b]) => a.localeCompare(b)),
    [...access.branches]
      .sort((a, b) => a.id.localeCompare(b.id))
      .map((b) => [b.id, b.name, b.active, b.timezone]),
  ]);
}
/** Recheck authorization on focus without destroying an unchanged patient draft. */
export async function revalidateSession() {
  let token = contextGate.capture();
  try {
    const user = await getCurrentUser(token.signal);
    if (!token.current()) return;
    const previous = session.user;
    if (
      !previous ||
      user.id !== previous.id ||
      user.active_tenant_id !== previous.active_tenant_id ||
      user.must_change_password !== previous.must_change_password ||
      user.is_platform_admin !== previous.is_platform_admin
    ) {
      clearClinic();
      token = contextGate.capture();
      session.checking = true;
      try {
        await refreshSession();
      } finally {
        session.checking = false;
      }
      return;
    }
    if (
      user.must_change_password ||
      user.is_platform_admin ||
      !user.active_tenant_id
    )
      return;
    const clinics = await api.get<{ data: Clinic[] }>("/api/v1/auth/clinics", {
      signal: token.signal,
    });
    if (!token.current()) return;
    const access = await readContext();
    if (!token.current()) return;
    session.user = user;
    session.clinics = clinics.data.data;
    if (
      !session.grants ||
      accessSignature(access) !==
        accessSignature({ grants: session.grants, branches: session.branches })
    ) {
      const branch = session.branch;
      clearClinic();
      session.grants = access.grants;
      session.branches = access.branches;
      session.branch = access.branches.some((b) => b.id === branch)
        ? branch
        : "";
    }
    session.error = "";
  } catch (e) {
    if (axios.isCancel(e) || !token.current()) return;
    clearClinic();
    throw e;
  }
}
export async function selectClinic(id: string) {
  if (session.switching) return;
  clearClinic();
  const token = contextGate.capture();
  session.switching = true;
  channel?.postMessage("changing");
  try {
    await api.post(
      "/api/v1/auth/active-clinic",
      { tenant_id: id },
      { signal: token.signal },
    );
    if (!token.current()) return;
    const user = await getCurrentUser(token.signal);
    if (!token.current()) return;
    session.user = user;
    await loadContext();
  } catch (e) {
    if (axios.isCancel(e) || !token.current()) return;
    clearClinic();
    session.switching = false;
    if (session.user) session.user.active_tenant_id = null;
    channel?.postMessage("changed");
    throw e;
  } finally {
    if (token.current()) {
      session.switching = false;
      channel?.postMessage("changed");
    }
  }
}
export function selectBranch(id: string) {
  const branches = session.branches;
  const grants = session.grants;
  clearClinic();
  session.branches = branches;
  session.grants = grants;
  session.branch = id;
}

// Cross-tab invalidation contains no IDs, credentials, patient data or cached claims.
const channel =
  typeof window !== "undefined" && typeof BroadcastChannel !== "undefined"
    ? new BroadcastChannel("blusser-context")
    : null;
if (channel)
  channel.onmessage = async (event) => {
    if (event.data === "changing") {
      clearClinic();
      session.switching = true;
      return;
    }
    if (event.data === "expired") {
      expire();
      return;
    }
    if (event.data === "changed") {
      clearClinic();
      session.checking = true;
      session.switching = false;
      try {
        await refreshSession();
      } catch (e) {
        session.error = "Klinika sessiyasini qayta tekshiring.";
      } finally {
        session.checking = false;
      }
    }
  };
export function notifyLogout() {
  channel?.postMessage("expired");
}
