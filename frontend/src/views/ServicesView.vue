<script setup lang="ts">
import {
  computed,
  nextTick,
  onMounted,
  onBeforeUnmount,
  ref,
  watch,
} from "vue";
import {
  Boxes,
  CheckCircle2,
  Folder,
  FolderPlus,
  Ban,
  Plus,
  Search,
  Download,
  Eye,
  Pencil,
  MoreHorizontal,
  ChevronLeft,
  ChevronRight,
} from "lucide-vue-next";
import WorkspaceDialog from "../components/doctor/WorkspaceDialog.vue";
import CategoryBadge from "../components/services/CategoryBadge.vue";
import CategoryForm from "../components/services/CategoryForm.vue";
import ServiceForm from "../components/services/ServiceForm.vue";
import {
  loadCatalog,
  readService,
  writeCategory,
  writeCategoryStatus,
  writeService,
  writeServiceStatus,
  exportCatalog,
  money,
  serviceTypes,
  type Catalog,
  type CatalogBranch,
  type Category,
  type CategoryDraft,
  type CatalogService,
  type ServiceDraft,
} from "../services/serviceCatalog";
import { downloadBlob, errorText } from "../services/api";
import { session } from "../services/session";
import "../components/services/services.css";
const allowed = (code: string) => session.grants?.[code] === "entire_clinic";
const catalog = ref<Catalog>({ categories: [], services: [] }),
  catalogBranches = ref<CatalogBranch[]>([]);
const stats = ref({ total: 0, active: 0, inactive: 0, categories: 0 });
const total = ref(0),
  query = ref(""),
  categoryId = ref(""),
  branchId = ref(""),
  status = ref(""),
  page = ref(1),
  size = ref(10),
  toast = ref("");
const loading = ref(false),
  saving = ref(false),
  error = ref(""),
  mutationError = ref("");
const modal = ref<
    "category" | "service" | "manage" | "details" | "confirm" | null
  >(null),
  editingCategory = ref<Category>(),
  editingService = ref<CatalogService>(),
  selected = ref<CatalogService>(),
  menu = ref(""),
  backToManage = ref(false);
let opener: HTMLElement | null = null,
  sequence = 0,
  mounted = true;
let searchTimer: ReturnType<typeof setTimeout> | undefined;
const rows = computed(() => catalog.value.services);
const pageCount = computed(() =>
  Math.max(1, Math.ceil(total.value / size.value)),
);
const countCategory = (id: string) =>
  catalog.value.categories.find((c) => c.id === id)?.service_count ?? 0;
const filters = () => ({
  q: query.value || undefined,
  category_id: categoryId.value || undefined,
  branch_id: branchId.value || undefined,
  status: status.value || "all",
});
async function load() {
  const current = ++sequence;
  if (!allowed("services.view")) {
    error.value = "Bu sahifa uchun ruxsat yo‘q.";
    return;
  }
  loading.value = true;
  error.value = "";
  try {
    const response = await loadCatalog({
      ...filters(),
      page: page.value,
      per_page: size.value,
    });
    if (!mounted || current !== sequence) return;
    catalog.value = {
      categories: response.categories,
      services: response.data,
    };
    catalogBranches.value = response.branches;
    stats.value = response.stats;
    total.value = response.meta.total;
    if (page.value > pageCount.value) {
      page.value = pageCount.value;
      return;
    }
  } catch (e) {
    if (mounted && current === sequence) error.value = errorText(e);
  } finally {
    if (mounted && current === sequence) loading.value = false;
  }
}
watch([categoryId, branchId, status, size], () => {
  page.value = 1;
});
watch(query, () => {
  // Invalidate requests immediately, before debounce, so old results never overwrite a new filter.
  sequence++;
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    page.value = 1;
    void load();
  }, 220);
});
watch([categoryId, branchId, status, size, page], () => {
  clearTimeout(searchTimer);
  void load();
});
const branchNames = (ids: string[]) =>
  ids
    .map((id) => catalogBranches.value.find((b) => b.id === id)?.name ?? id)
    .join(", ");
const categoryFor = (s: CatalogService) =>
  catalog.value.categories.find((c) => c.id === s.categoryId);
function begin(next: typeof modal.value) {
  opener = document.activeElement as HTMLElement;
  modal.value = next;
  menu.value = "";
  mutationError.value = "";
}
function categoryForm(category?: Category, manage = false) {
  if (!allowed(category ? "categories.update" : "categories.create")) return;
  editingCategory.value = category;
  backToManage.value = manage;
  mutationError.value = "";
  if (manage) modal.value = "category";
  else begin("category");
}
async function serviceForm(service?: CatalogService, fromDetails = false) {
  if (!allowed(service ? "services.update" : "services.create") || saving.value)
    return;
  mutationError.value = "";
  try {
    editingService.value = service ? await readService(service.id) : undefined;
    if (!mounted) return;
    if (fromDetails) modal.value = "service";
    else begin("service");
  } catch (e) {
    error.value = errorText(e);
  }
}
async function close() {
  if (saving.value) return;
  mutationError.value = "";
  if (modal.value === "category" && backToManage.value) {
    backToManage.value = false;
    modal.value = "manage";
    return;
  }
  modal.value = null;
  await nextTick();
  if (opener?.isConnected) opener.focus();
  else document.querySelector<HTMLElement>(".sv-heading .sv-primary")?.focus();
}
async function categorySaved(draft: CategoryDraft) {
  if (
    saving.value ||
    !allowed(editingCategory.value ? "categories.update" : "categories.create")
  )
    return;
  saving.value = true;
  mutationError.value = "";
  try {
    await writeCategory(draft, editingCategory.value);
    toast.value = "Kategoriya saqlandi.";
    saving.value = false;
    await close();
    await load();
  } catch (e) {
    mutationError.value = errorText(e);
  } finally {
    saving.value = false;
  }
}
async function categoryStatus(category: Category) {
  if (saving.value || !allowed("categories.status")) return;
  saving.value = true;
  mutationError.value = "";
  try {
    await writeCategoryStatus(category, !category.active);
    toast.value = "Kategoriya holati saqlandi.";
    await load();
  } catch (e) {
    mutationError.value = errorText(e);
  } finally {
    saving.value = false;
  }
}
async function serviceSaved(draft: ServiceDraft) {
  if (
    saving.value ||
    !allowed(editingService.value ? "services.update" : "services.create")
  )
    return;
  saving.value = true;
  mutationError.value = "";
  try {
    await writeService(draft, editingService.value);
    toast.value = "Xizmat saqlandi.";
    saving.value = false;
    await close();
    query.value = "";
    categoryId.value = "";
    branchId.value = "";
    status.value = "";
    page.value = 1;
    clearTimeout(searchTimer);
    await load();
  } catch (e) {
    mutationError.value = errorText(e);
  } finally {
    saving.value = false;
  }
}
async function details(s: CatalogService) {
  try {
    selected.value = await readService(s.id);
    if (mounted) begin("details");
  } catch (e) {
    error.value = errorText(e);
  }
}
async function toggle(s: CatalogService) {
  if (!allowed("services.delete")) return;
  menu.value = "";
  if (s.active) {
    selected.value = s;
    begin("confirm");
    opener = document.querySelector<HTMLElement>(
      `[aria-label="${s.code} amallar"]`,
    );
  } else await changeStatus(s, true);
}
async function changeStatus(s: CatalogService, active: boolean) {
  if (saving.value || !allowed("services.delete")) return;
  saving.value = true;
  mutationError.value = "";
  try {
    await writeServiceStatus(s, active);
    toast.value = active
      ? "Xizmat qayta faollashtirildi."
      : "Xizmat faolsizlantirildi.";
    saving.value = false;
    await close();
    await load();
  } catch (e) {
    if (modal.value) mutationError.value = errorText(e);
    else error.value = errorText(e);
  } finally {
    saving.value = false;
  }
}
async function deactivate() {
  if (selected.value) await changeStatus(selected.value, false);
}
async function exportCsv() {
  if (!allowed("categories.export") || saving.value) return;
  try {
    downloadBlob(await exportCatalog(filters()), "xizmatlar.csv");
    toast.value = "Filtrlangan katalog CSV sifatida yuklandi.";
  } catch (e) {
    error.value = errorText(e);
  }
}
function outside(e: MouseEvent) {
  if (!(e.target as HTMLElement).closest(".sv-more")) menu.value = "";
}
onMounted(() => {
  document.addEventListener("click", outside);
  void load();
});
onBeforeUnmount(() => {
  mounted = false;
  sequence++;
  clearTimeout(searchTimer);
  document.removeEventListener("click", outside);
});
</script>
<template>
  <section class="sv-page" :aria-busy="loading">
    <header class="sv-heading">
      <div>
        <h1>Xizmatlar</h1>
        <p>Klinika xizmatlari va narxlarini boshqarish.</p>
      </div>
      <div>
        <button
          v-if="allowed('categories.create')"
          data-permission="categories.create"
          :disabled="loading || saving"
          class="sv-button sv-outline"
          @click="categoryForm()"
        >
          <FolderPlus :size="21" />Kategoriya qo‘shish</button
        ><button
          v-if="allowed('services.create')"
          data-permission="services.create"
          :disabled="loading || saving"
          class="sv-button sv-primary"
          @click="serviceForm()"
        >
          <Plus :size="21" />Xizmat qo‘shish
        </button>
      </div>
    </header>
    <div v-if="error" class="sv-toast" role="alert">
      {{ error
      }}<button data-permission="services.view" @click="load">
        Qayta urinish
      </button>
    </div>
    <div v-if="toast" class="sv-toast" role="status">
      {{ toast
      }}<button
        data-permission="services.view"
        aria-label="Xabarni yopish"
        @click="toast = ''"
      >
        ×
      </button>
    </div>
    <div class="sv-stats">
      <article>
        <span class="sv-stat-icon blue"><Boxes :size="29" /></span>
        <div>
          <p>Jami xizmatlar</p>
          <strong>{{ stats.total }}</strong>
        </div>
      </article>
      <article>
        <span class="sv-stat-icon green"><CheckCircle2 :size="29" /></span>
        <div>
          <p>Faol xizmatlar</p>
          <strong>{{ stats.active }}</strong>
        </div>
      </article>
      <component
        :is="allowed('categories.view') ? 'button' : 'article'"
        class="sv-category-card"
        data-permission="services.view"
        aria-label="Kategoriyalarni boshqarish"
        @click="allowed('categories.view') && begin('manage')"
      >
        <span class="sv-stat-icon purple"><Folder :size="29" /></span>
        <div>
          <p>Kategoriyalar</p>
          <strong>{{ stats.categories }}</strong>
        </div>
      </component>
      <article>
        <span class="sv-stat-icon pink"><Ban :size="29" /></span>
        <div>
          <p>Faol emas</p>
          <strong>{{ stats.inactive }}</strong>
        </div>
      </article>
    </div>
    <section class="sv-panel">
      <nav class="sv-tabs" aria-label="Xizmat kategoriyalari">
        <button
          data-permission="services.view"
          :class="{ active: !categoryId }"
          :aria-pressed="!categoryId"
          @click="categoryId = ''"
        >
          Barchasi <span>{{ stats.total }}</span></button
        ><button
          v-for="c in catalog.categories"
          data-permission="services.view"
          :key="c.id"
          :class="{ active: categoryId === c.id }"
          :aria-pressed="categoryId === c.id"
          @click="categoryId = c.id"
        >
          {{ c.name }} <span>{{ countCategory(c.id) }}</span>
        </button>
      </nav>
      <div class="sv-filters">
        <label class="sv-search"
          ><Search :size="19" /><input
            v-model="query"
            aria-label="Xizmat nomi yoki kodi"
            placeholder="Xizmat nomi yoki kodi..." /></label
        ><select v-model="branchId" aria-label="Filial filtri">
          <option value="">Barcha filiallar</option>
          <option v-for="b in catalogBranches" :key="b.id" :value="b.id">
            {{ b.name }}
          </option></select
        ><select v-model="status" aria-label="Holat filtri">
          <option value="">Barcha holatlar</option>
          <option value="active">Faol</option>
          <option value="inactive">Faol emas</option></select
        ><button
          v-if="allowed('categories.export')"
          data-permission="categories.export"
          :disabled="loading"
          class="sv-button sv-outline"
          @click="exportCsv"
        >
          <Download :size="20" />Eksport
        </button>
      </div>
      <div class="sv-table-scroll">
        <table>
          <thead>
            <tr>
              <th>Kod</th>
              <th>Xizmat nomi</th>
              <th>Kategoriya</th>
              <th>Davomiylik</th>
              <th class="sv-price">Narx</th>
              <th>Holat</th>
              <th class="sv-actions-head">Amallar</th>
            </tr>
          </thead>
          <tbody>
            <template v-if="!loading && !error">
              <tr v-for="s in rows" :key="s.id">
                <td>{{ s.code }}</td>
                <td>{{ s.name }}</td>
                <td>
                  <CategoryBadge
                    v-if="categoryFor(s)"
                    :category="categoryFor(s)!"
                  /><span v-else>—</span>
                </td>
                <td>
                  {{ s.duration === null ? "—" : `${s.duration} daqiqa` }}
                </td>
                <td class="sv-price">{{ money(s.price) }}</td>
                <td>
                  <span class="sv-status" :class="{ inactive: !s.active }"
                    ><i />{{ s.active ? "Faol" : "Faol emas" }}</span
                  >
                </td>
                <td>
                  <div class="sv-actions">
                    <button
                      class="sv-icon"
                      data-permission="services.view"
                      :aria-label="`${s.code} ko‘rish`"
                      title="Ko‘rish"
                      @click="details(s)"
                    >
                      <Eye :size="19" /></button
                    ><button
                      class="sv-icon"
                      v-if="allowed('services.update')"
                      data-permission="services.update"
                      :aria-label="`${s.code} tahrirlash`"
                      title="Tahrirlash"
                      @click="serviceForm(s)"
                    >
                      <Pencil :size="19" />
                    </button>
                    <div v-if="allowed('services.delete')" class="sv-more">
                      <button
                        class="sv-icon"
                        data-permission="services.delete"
                        :aria-label="`${s.code} amallar`"
                        :aria-expanded="menu === s.id"
                        @click="menu = menu === s.id ? '' : s.id"
                      >
                        <MoreHorizontal :size="20" />
                      </button>
                      <div
                        v-if="menu === s.id"
                        class="sv-menu"
                        @keydown.esc.stop="menu = ''"
                      >
                        <button
                          data-permission="services.delete"
                          :disabled="saving"
                          @click="toggle(s)"
                        >
                          {{
                            s.active
                              ? "Faolsizlantirish"
                              : "Qayta faollashtirish"
                          }}
                        </button>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
            </template>
            <tr v-if="loading">
              <td colspan="7" class="sv-empty" role="status">
                Xizmatlar yuklanmoqda…
              </td>
            </tr>
            <tr v-else-if="!rows.length && !error">
              <td colspan="7" class="sv-empty">
                <template v-if="!stats.total"
                  ><h2>Katalog hali bo‘sh</h2>
                  <p>Birinchi xizmatni qo‘shing.</p>
                  <button
                    v-if="allowed('services.create')"
                    data-permission="services.create"
                    class="sv-button sv-primary"
                    @click="serviceForm()"
                  >
                    Xizmat qo‘shish
                  </button></template
                ><template v-else
                  ><h2>Xizmat topilmadi</h2>
                  <p>Qidiruv yoki filtrlarni o‘zgartiring.</p>
                  <button
                    class="sv-button"
                    data-permission="services.view"
                    @click="
                      query = '';
                      categoryId = '';
                      branchId = '';
                      status = '';
                    "
                  >
                    Filtrlarni tozalash
                  </button></template
                >
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <footer class="sv-pagination">
        <span
          >{{ total ? (page - 1) * size + 1 : 0 }}–{{
            Math.min(page * size, total)
          }}
          / {{ total }} ta xizmat</span
        >
        <div>
          <select v-model="size" aria-label="Sahifadagi yozuvlar">
            <option :value="10">10 ta</option>
            <option :value="20">20 ta</option>
            <option :value="50">50 ta</option></select
          ><button
            class="sv-icon"
            data-permission="services.view"
            :disabled="page === 1"
            aria-label="Oldingi sahifa"
            @click="page--"
          >
            <ChevronLeft :size="18" /></button
          ><button
            v-for="n in pageCount"
            :key="n"
            class="sv-page-number"
            data-permission="services.view"
            :class="{ active: page === n }"
            :aria-label="`Sahifa ${n}`"
            :aria-current="page === n ? 'page' : undefined"
            @click="page = n"
          >
            {{ n }}</button
          ><button
            class="sv-icon"
            data-permission="services.view"
            :disabled="page === pageCount"
            aria-label="Keyingi sahifa"
            @click="page++"
          >
            <ChevronRight :size="18" />
          </button>
        </div>
      </footer>
    </section>

    <CategoryForm
      v-if="modal === 'category'"
      :categories="catalog.categories"
      :category="editingCategory"
      :saving="saving"
      :server-error="mutationError"
      :status-allowed="allowed('categories.status')"
      @close="close"
      @save="categorySaved"
    /><ServiceForm
      v-if="modal === 'service'"
      :catalog="catalog"
      :service="editingService"
      :branches="catalogBranches"
      :saving="saving"
      :server-error="mutationError"
      :status-allowed="allowed('services.delete')"
      @close="close"
      @save="serviceSaved"
    /><WorkspaceDialog
      v-if="modal === 'manage'"
      class="sv-dialog sv-manage-dialog"
      title="Kategoriyalar"
      subtitle="Xizmat kategoriyalarini boshqarish"
      :icon="Folder"
      @close="close"
      ><p v-if="mutationError" class="sv-error" role="alert">
        {{ mutationError }}
      </p>
      <div class="sv-manage-list">
        <article v-for="c in catalog.categories" :key="c.id">
          <CategoryBadge :category="c" /><span
            >{{ countCategory(c.id) }} ta xizmat</span
          ><button
            v-if="allowed('categories.status')"
            data-permission="categories.status"
            class="sv-status"
            :class="{ inactive: !c.active }"
            :aria-label="`${c.name} holatini o‘zgartirish`"
            :disabled="saving"
            @click="categoryStatus(c)"
          >
            <i />{{ c.active ? "Faol" : "Faol emas" }}</button
          ><span v-else class="sv-status" :class="{ inactive: !c.active }"
            ><i />{{ c.active ? "Faol" : "Faol emas" }}</span
          ><button
            class="sv-icon"
            v-if="allowed('categories.update')"
            data-permission="categories.update"
            :aria-label="`${c.name} kategoriyasini tahrirlash`"
            @click="categoryForm(c, true)"
          >
            <Pencil :size="19" />
          </button>
        </article>
      </div>
      <template #footer
        ><footer class="sv-modal-footer">
          <button
            v-if="allowed('categories.create')"
            data-permission="categories.create"
            class="sv-button"
            @click="categoryForm(undefined, true)"
          >
            <Plus :size="18" />Kategoriya qo‘shish</button
          ><button class="sv-button sv-primary" @click="close">Yopish</button>
        </footer></template
      ></WorkspaceDialog
    ><WorkspaceDialog
      v-if="modal === 'details' && selected"
      class="sv-dialog sv-details-dialog"
      title="Xizmat tafsilotlari"
      :subtitle="selected.code"
      :icon="Boxes"
      @close="close"
      ><div class="sv-details">
        <h3>{{ selected.name }}</h3>
        <dl>
          <div>
            <dt>Kod</dt>
            <dd>{{ selected.code }}</dd>
          </div>
          <div>
            <dt>Kategoriya</dt>
            <dd>
              <CategoryBadge
                v-if="categoryFor(selected)"
                :category="categoryFor(selected)!"
              /><span v-else>—</span>
            </dd>
          </div>
          <div>
            <dt>Xizmat turi</dt>
            <dd>
              {{ serviceTypes.find((t) => t.id === selected!.type)?.name }}
            </dd>
          </div>
          <div>
            <dt>Filiallar</dt>
            <dd>
              {{ branchNames(selected.branchIds) }}
            </dd>
          </div>
          <div>
            <dt>Narx</dt>
            <dd>{{ money(selected.price) }}</dd>
          </div>
          <div>
            <dt>Vaqt bo‘yicha yozilish</dt>
            <dd>{{ selected.timed ? "Ha" : "Yo‘q" }}</dd>
          </div>
          <div>
            <dt>Davomiylik</dt>
            <dd>
              {{
                selected.duration === null ? "—" : `${selected.duration} daqiqa`
              }}
            </dd>
          </div>
          <div v-if="selected.type === 'laboratory'">
            <dt>Natija tayyor bo‘lish muddati</dt>
            <dd>
              {{ selected.turnaround }}
              {{ selected.turnaroundUnit === "hour" ? "soat" : "kun" }}
            </dd>
          </div>
          <div>
            <dt>Tavsif</dt>
            <dd>{{ selected.description || "Kiritilmagan" }}</dd>
          </div>
          <div>
            <dt>Holat</dt>
            <dd>{{ selected.active ? "Faol" : "Faol emas" }}</dd>
          </div>
        </dl>
      </div>
      <template #footer
        ><footer class="sv-modal-footer">
          <button class="sv-button" @click="close">Yopish</button
          ><button
            class="sv-button sv-primary"
            v-if="allowed('services.update')"
            data-permission="services.update"
            @click="serviceForm(selected, true)"
          >
            <Pencil :size="18" />Tahrirlash
          </button>
        </footer></template
      ></WorkspaceDialog
    ><WorkspaceDialog
      v-if="modal === 'confirm' && selected"
      class="sv-dialog sv-confirm-dialog"
      title="Xizmatni faolsizlantirish"
      :icon="Ban"
      @close="close"
      ><div class="sv-details">
        <p v-if="mutationError" class="sv-error" role="alert">
          {{ mutationError }}
        </p>
        <p>“{{ selected.name }}” xizmati faolsizlantirilsinmi?</p>
        <p>
          Mavjud xizmat yozuvi saqlanadi. Keyin qayta faollashtirish mumkin.
        </p>
      </div>
      <template #footer
        ><footer class="sv-modal-footer">
          <button class="sv-button" @click="close">Bekor qilish</button
          ><button
            class="sv-button sv-danger"
            data-permission="services.delete"
            :disabled="saving"
            @click="deactivate"
          >
            Faolsizlantirish
          </button>
        </footer></template
      ></WorkspaceDialog
    >
  </section>
</template>
