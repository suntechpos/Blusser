import { ref, watch, onUnmounted } from "vue";
import axios from "axios";
import { clinicRequest, errorText } from "../services/api";
import { session } from "../services/session";
export function useClinicList<T>(
  path: string,
  filters: () => Record<string, unknown> = () => ({}),
  enabled: () => boolean = () => true,
) {
  const rows = ref<T[]>([]),
    total = ref(0),
    page = ref(1),
    loading = ref(false),
    error = ref("");
  let serial = 0,
    alive = true;
  async function load() {
    const request = ++serial;
    rows.value = [];
    total.value = 0;
    error.value = "";
    loading.value = true;
    if (!enabled()) {
      loading.value = false;
      error.value = "Bu bo‘limni ko‘rish uchun ruxsat yo‘q.";
      return;
    }
    try {
      const r = await clinicRequest<{ data: T[]; meta?: { total: number } }>(
        path,
        {
          params: {
            page: page.value,
            per_page: 20,
            ...(session.branch ? { branch_id: session.branch } : {}),
            ...filters(),
          },
        },
      );
      if (alive && request === serial) {
        rows.value = r.data.data as typeof rows.value;
        total.value = r.data.meta?.total ?? r.data.data.length;
      }
    } catch (e) {
      if (alive && request === serial && !axios.isCancel(e))
        error.value = errorText(e);
    } finally {
      if (alive && request === serial) loading.value = false;
    }
  }
  const stop = watch(
    () => [session.epoch, page.value, enabled(), filters()],
    () => {
      void load();
    },
    { deep: true, immediate: true },
  );
  onUnmounted(() => {
    alive = false;
    serial++;
    stop();
  });
  return { rows, total, page, loading, error, load };
}
