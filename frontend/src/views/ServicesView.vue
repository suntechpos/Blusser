<script setup lang="ts">
import {
  computed,
  nextTick,
  onMounted,
  onBeforeUnmount,
  ref,
  watch,
  toRaw,
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
  createServicesCatalog,
  filterServices,
  catalogCsv,
  catalogBranches,
  money,
  serviceTypes,
  saveCategory,
  saveService,
  type Catalog,
  type Category,
  type CategoryDraft,
  type CatalogService,
  type ServiceDraft,
} from "../mocks/servicesCatalog";
import "../components/services/services.css";
const props = defineProps<{ initialCatalog?: Catalog }>();
const catalog = ref(
    props.initialCatalog
      ? structuredClone(toRaw(props.initialCatalog))
      : createServicesCatalog(),
  ),
  query = ref(""),
  categoryId = ref(""),
  branchId = ref(""),
  status = ref(""),
  page = ref(1),
  size = ref(10),
  toast = ref("");
const modal = ref<
    "category" | "service" | "manage" | "details" | "confirm" | null
  >(null),
  editingCategory = ref<Category>(),
  editingService = ref<CatalogService>(),
  selected = ref<CatalogService>(),
  menu = ref(""),
  backToManage = ref(false);
let opener: HTMLElement | null = null;
const filtered = computed(() =>
  filterServices(catalog.value, {
    query: query.value,
    categoryId: categoryId.value,
    branchId: branchId.value,
    status: status.value,
  }),
);
const pageCount = computed(() =>
  Math.max(1, Math.ceil(filtered.value.length / size.value)),
);
const rows = computed(() =>
  filtered.value.slice((page.value - 1) * size.value, page.value * size.value),
);
const activeCount = computed(
  () => catalog.value.services.filter((s) => s.active).length,
);
const countCategory = (id: string) =>
  catalog.value.services.filter((s) => s.categoryId === id).length;
watch([query, categoryId, branchId, status, size], () => (page.value = 1), {
  flush: "sync",
});
watch(pageCount, (n) => {
  if (page.value > n) page.value = n;
});
const branchNames = (ids: string[]) =>
  ids.map((id) => catalogBranches.find((b) => b.id === id)?.name).join(", ");
const categoryFor = (s: CatalogService) =>
  catalog.value.categories.find((c) => c.id === s.categoryId)!;
function begin(next: typeof modal.value) {
  opener = document.activeElement as HTMLElement;
  modal.value = next;
  menu.value = "";
}
function categoryForm(category?: Category, manage = false) {
  editingCategory.value = category;
  backToManage.value = manage;
  if (manage) modal.value = "category";
  else begin("category");
}
function serviceForm(service?: CatalogService, fromDetails = false) {
  editingService.value = service;
  if (fromDetails) modal.value = "service";
  else begin("service");
}
async function close() {
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
function categorySaved(draft: CategoryDraft) {
  const result = saveCategory(catalog.value, draft, editingCategory.value?.id);
  if (result.category) {
    toast.value = "Kategoriya lokal namunada saqlandi.";
    void close();
  }
}
function serviceSaved(draft: ServiceDraft) {
  const result = saveService(catalog.value, draft, editingService.value?.id);
  if (result.service) {
    query.value = "";
    categoryId.value = "";
    branchId.value = "";
    status.value = "";
    page.value =
      Math.floor(
        filtered.value.findIndex((s) => s.id === result.service!.id) /
          size.value,
      ) + 1;
    toast.value = "Xizmat lokal namunada saqlandi.";
    void close();
  }
}
function details(s: CatalogService) {
  selected.value = s;
  begin("details");
}
function toggle(s: CatalogService) {
  menu.value = "";
  if (s.active) {
    selected.value = s;
    begin("confirm");
    opener = document.querySelector<HTMLElement>(
      `[aria-label="${s.code} amallar"]`,
    );
  } else {
    s.active = true;
    toast.value = "Xizmat lokal namunada qayta faollashtirildi.";
  }
}
function deactivate() {
  if (selected.value) {
    selected.value.active = false;
    toast.value = "Xizmat lokal namunada faolsizlantirildi.";
  }
  void close();
}
function exportCsv() {
  const blob = new Blob(
    [catalogCsv(filtered.value, catalog.value.categories)],
    { type: "text/csv;charset=utf-8" },
  );
  const url = URL.createObjectURL(blob),
    a = document.createElement("a");
  a.href = url;
  a.download = "xizmatlar.csv";
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
  toast.value = "Filtrlangan lokal katalog CSV sifatida yuklandi.";
}
function outside(e: MouseEvent) {
  if (!(e.target as HTMLElement).closest(".sv-more")) menu.value = "";
}
onMounted(() => document.addEventListener("click", outside));
onBeforeUnmount(() => document.removeEventListener("click", outside));
</script>
<template>
  <section class="sv-page">
    <header class="sv-heading">
      <div>
        <h1>Xizmatlar</h1>
        <p>Klinika xizmatlari va narxlarini boshqarish.</p>
      </div>
      <div>
        <button class="sv-button sv-outline" @click="categoryForm()">
          <FolderPlus :size="21" />Kategoriya qo‘shish</button
        ><button class="sv-button sv-primary" @click="serviceForm()">
          <Plus :size="21" />Xizmat qo‘shish
        </button>
      </div>
    </header>
    <div v-if="toast" class="sv-toast" role="status">
      {{ toast
      }}<button aria-label="Xabarni yopish" @click="toast = ''">×</button>
    </div>
    <div class="sv-stats">
      <article>
        <span class="sv-stat-icon blue"><Boxes :size="29" /></span>
        <div>
          <p>Jami xizmatlar</p>
          <strong>{{ catalog.services.length }}</strong>
        </div>
      </article>
      <article>
        <span class="sv-stat-icon green"><CheckCircle2 :size="29" /></span>
        <div>
          <p>Faol xizmatlar</p>
          <strong>{{ activeCount }}</strong>
        </div>
      </article>
      <button
        class="sv-category-card"
        aria-label="Kategoriyalarni boshqarish"
        @click="begin('manage')"
      >
        <span class="sv-stat-icon purple"><Folder :size="29" /></span>
        <div>
          <p>Kategoriyalar</p>
          <strong>{{ catalog.categories.length }}</strong>
        </div>
      </button>
      <article>
        <span class="sv-stat-icon pink"><Ban :size="29" /></span>
        <div>
          <p>Faol emas</p>
          <strong>{{ catalog.services.length - activeCount }}</strong>
        </div>
      </article>
    </div>
    <section class="sv-panel">
      <nav class="sv-tabs" aria-label="Xizmat kategoriyalari">
        <button
          :class="{ active: !categoryId }"
          :aria-pressed="!categoryId"
          @click="categoryId = ''"
        >
          Barchasi <span>{{ catalog.services.length }}</span></button
        ><button
          v-for="c in catalog.categories"
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
        ><button class="sv-button sv-outline" @click="exportCsv">
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
            <tr v-for="s in rows" :key="s.id">
              <td>{{ s.code }}</td>
              <td>{{ s.name }}</td>
              <td><CategoryBadge :category="categoryFor(s)" /></td>
              <td>{{ s.duration === null ? "—" : `${s.duration} daqiqa` }}</td>
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
                    :aria-label="`${s.code} ko‘rish`"
                    title="Ko‘rish"
                    @click="details(s)"
                  >
                    <Eye :size="19" /></button
                  ><button
                    class="sv-icon"
                    :aria-label="`${s.code} tahrirlash`"
                    title="Tahrirlash"
                    @click="serviceForm(s)"
                  >
                    <Pencil :size="19" />
                  </button>
                  <div class="sv-more">
                    <button
                      class="sv-icon"
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
                      <button @click="toggle(s)">
                        {{
                          s.active ? "Faolsizlantirish" : "Qayta faollashtirish"
                        }}
                      </button>
                    </div>
                  </div>
                </div>
              </td>
            </tr>
            <tr v-if="!rows.length">
              <td colspan="7" class="sv-empty">
                <template v-if="!catalog.services.length"
                  ><h2>Katalog hali bo‘sh</h2>
                  <p>Birinchi xizmatni qo‘shing.</p>
                  <button class="sv-button sv-primary" @click="serviceForm()">
                    Xizmat qo‘shish
                  </button></template
                ><template v-else
                  ><h2>Xizmat topilmadi</h2>
                  <p>Qidiruv yoki filtrlarni o‘zgartiring.</p>
                  <button
                    class="sv-button"
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
          >{{ filtered.length ? (page - 1) * size + 1 : 0 }}–{{
            Math.min(page * size, filtered.length)
          }}
          / {{ filtered.length }} ta xizmat</span
        >
        <div>
          <select v-model="size" aria-label="Sahifadagi yozuvlar">
            <option :value="10">10 ta</option>
            <option :value="20">20 ta</option>
            <option :value="50">50 ta</option></select
          ><button
            class="sv-icon"
            :disabled="page === 1"
            aria-label="Oldingi sahifa"
            @click="page--"
          >
            <ChevronLeft :size="18" /></button
          ><button
            v-for="n in pageCount"
            :key="n"
            class="sv-page-number"
            :class="{ active: page === n }"
            :aria-label="`Sahifa ${n}`"
            :aria-current="page === n ? 'page' : undefined"
            @click="page = n"
          >
            {{ n }}</button
          ><button
            class="sv-icon"
            :disabled="page === pageCount"
            aria-label="Keyingi sahifa"
            @click="page++"
          >
            <ChevronRight :size="18" />
          </button>
        </div>
      </footer>
    </section>
    <p class="sv-demo">Namunaviy ma’lumotlar · faqat lokal xotirada</p>
    <CategoryForm
      v-if="modal === 'category'"
      :categories="catalog.categories"
      :category="editingCategory"
      @close="close"
      @save="categorySaved"
    /><ServiceForm
      v-if="modal === 'service'"
      :catalog="catalog"
      :service="editingService"
      @close="close"
      @save="serviceSaved"
    /><WorkspaceDialog
      v-if="modal === 'manage'"
      class="sv-dialog sv-manage-dialog"
      title="Kategoriyalar"
      subtitle="Lokal kategoriyalarni boshqarish"
      :icon="Folder"
      @close="close"
      ><div class="sv-manage-list">
        <article v-for="c in catalog.categories" :key="c.id">
          <CategoryBadge :category="c" /><span
            >{{ countCategory(c.id) }} ta xizmat</span
          ><span class="sv-status" :class="{ inactive: !c.active }"
            ><i />{{ c.active ? "Faol" : "Faol emas" }}</span
          ><button
            class="sv-icon"
            :aria-label="`${c.name} kategoriyasini tahrirlash`"
            @click="categoryForm(c, true)"
          >
            <Pencil :size="19" />
          </button>
        </article>
      </div>
      <template #footer
        ><footer class="sv-modal-footer">
          <button class="sv-button" @click="categoryForm(undefined, true)">
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
            <dd><CategoryBadge :category="categoryFor(selected)" /></dd>
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
        <p class="sv-local-note">
          Lokal namuna · haqiqiy narxlar katalogiga ulanmagan
        </p>
      </div>
      <template #footer
        ><footer class="sv-modal-footer">
          <button class="sv-button" @click="close">Yopish</button
          ><button
            class="sv-button sv-primary"
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
        <p>
          “{{ selected.name }}” xizmati lokal katalogda faolsizlantirilsinmi?
        </p>
        <p>
          Mavjud xizmat yozuvi saqlanadi. Keyin qayta faollashtirish mumkin.
        </p>
      </div>
      <template #footer
        ><footer class="sv-modal-footer">
          <button class="sv-button" @click="close">Bekor qilish</button
          ><button class="sv-button sv-danger" @click="deactivate">
            Faolsizlantirish
          </button>
        </footer></template
      ></WorkspaceDialog
    >
  </section>
</template>
