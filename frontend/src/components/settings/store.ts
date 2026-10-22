import { ref } from "vue";
import { session } from "../../services/session";
import {
  loadRoles,
  commitRoles,
  storagePrefix,
  createRolesState,
  type RolesState,
} from "../../mocks/settingsRoles";
const stores = new Map<string, ReturnType<typeof makeStore>>();
function makeStore(key: string) {
  const state = ref(createRolesState());
  const error = ref("");
  try {
    state.value = loadRoles(localStorage, key);
  } catch (e) {
    error.value = `Lokal ma’lumot o‘qilmadi: ${(e as Error).message}`;
  }
  function transact(fn: (s: RolesState) => void) {
    if (error.value) throw Error(error.value);
    state.value = commitRoles(state.value, localStorage, key, fn);
  }
  return { state, error, transact };
}
export function useRolesStore() {
  const key = storagePrefix + (session.user?.active_tenant_id ?? "standalone");
  if (!stores.has(key)) stores.set(key, makeStore(key));
  return stores.get(key)!;
}
