<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { FolderPlus, Check, X, ChevronDown } from "lucide-vue-next";
import WorkspaceDialog from "../doctor/WorkspaceDialog.vue";
import {
  blankService,
  serviceDraft,
  validateService,
  serviceTypes,
  type Catalog,
  type CatalogBranch,
  type CatalogService,
  type ServiceDraft,
} from "../../services/serviceCatalog";
const props = defineProps<{
  catalog: Catalog;
  branches: CatalogBranch[];
  service?: CatalogService;
  saving?: boolean;
  serverError?: string;
  statusAllowed?: boolean;
}>();
const emit = defineEmits<{ close: []; save: [draft: ServiceDraft] }>();
const draft = ref(
  props.service
    ? serviceDraft(props.service)
    : blankService(props.catalog.categories, props.branches),
);
const initial = JSON.stringify(draft.value),
  errors = ref<Record<string, string>>({}),
  branchesOpen = ref(false);
const dirty = computed(() => JSON.stringify(draft.value) !== initial);
const categories = computed(() =>
  props.catalog.categories.filter(
    (c) => c.active || c.id === props.service?.categoryId,
  ),
);
watch(
  () => draft.value.type,
  (type) => {
    if (type === "consultation") draft.value.timed = true;
    else if (type === "laboratory") draft.value.timed = false;
  },
);
function toggleBranch(id: string) {
  draft.value.branchIds = draft.value.branchIds.includes(id)
    ? draft.value.branchIds.filter((x) => x !== id)
    : [...draft.value.branchIds, id];
}
function save() {
  if (props.saving) return;
  errors.value = validateService(
    draft.value,
    props.catalog,
    props.branches,
    props.service,
  );
  if (!Object.keys(errors.value).length)
    emit("save", { ...draft.value, branchIds: [...draft.value.branchIds] });
}
</script>
<template>
  <WorkspaceDialog
    class="sv-dialog sv-service-dialog"
    :title="service ? 'Xizmatni tahrirlash' : 'Xizmat qo‘shish'"
    :subtitle="
      service
        ? 'Xizmat ma’lumotlarini yangilang'
        : 'Yangi xizmat ma’lumotlarini kiriting'
    "
    :icon="FolderPlus"
    :dirty="!saving && dirty"
    @close="emit('close')"
    ><form
      id="sv-service-form"
      class="sv-form"
      novalidate
      :inert="saving || undefined"
      :aria-busy="saving"
      :data-permission="service ? 'services.update' : 'services.create'"
      @submit.prevent="save"
    >
      <p v-if="serverError" class="sv-error" role="alert">{{ serverError }}</p>
      <div class="sv-name-code">
        <label
          >Xizmat nomi <em>*</em
          ><input
            v-model="draft.name"
            aria-label="Xizmat nomi"
            placeholder="Masalan, Kardiolog konsultatsiyasi"
            maxlength="255"
            :aria-invalid="!!errors.name"
          /><small v-if="errors.name" class="sv-error">{{
            errors.name
          }}</small></label
        ><label
          >Xizmat kodi<input
            :value="draft.code"
            aria-label="Xizmat kodi"
            readonly
            class="sv-code"
          /><small>Avtomatik yaratiladi</small></label
        >
      </div>
      <label
        >Kategoriya <em>*</em
        ><select
          v-model="draft.categoryId"
          aria-label="Kategoriya"
          :aria-invalid="!!errors.categoryId"
        >
          <option value="">Kategoriyani tanlang</option>
          <option v-for="c in categories" :key="c.id" :value="c.id">
            {{ c.name
            }}{{ !c.active ? " (faol emas — mavjud bog‘lanish)" : "" }}
          </option></select
        ><small v-if="errors.categoryId" class="sv-error">{{
          errors.categoryId
        }}</small></label
      ><label
        >Xizmat turi <em>*</em
        ><select v-model="draft.type" aria-label="Xizmat turi">
          <option v-for="t in serviceTypes" :key="t.id" :value="t.id">
            {{ t.name }}
          </option></select
        ><small v-if="errors.type" class="sv-error">{{
          errors.type
        }}</small></label
      >
      <fieldset class="sv-branches">
        <legend>Filiallar <em>*</em></legend>
        <div class="sv-branch-selection">
          <span v-for="id in draft.branchIds" :key="id" class="sv-branch-chip"
            >{{ branches.find((b) => b.id === id)?.name
            }}<button
              type="button"
              :data-permission="service ? 'services.update' : 'services.create'"
              :aria-label="`${branches.find((b) => b.id === id)?.name}ni olib tashlash`"
              @click="toggleBranch(id)"
            >
              <X :size="14" /></button></span
          ><button
            type="button"
            :data-permission="service ? 'services.update' : 'services.create'"
            class="sv-branch-expand"
            aria-label="Filiallarni tanlash"
            :aria-expanded="branchesOpen"
            @click="branchesOpen = !branchesOpen"
          >
            {{ draft.branchIds.length ? "" : "Filiallarni tanlang"
            }}<ChevronDown :size="17" />
          </button>
        </div>
        <div v-if="branchesOpen" class="sv-branch-options">
          <label v-for="b in branches" :key="b.id"
            ><input
              type="checkbox"
              :checked="draft.branchIds.includes(b.id)"
              @change="toggleBranch(b.id)"
            />{{ b.name }}</label
          >
        </div>
        <small v-if="errors.branchIds" class="sv-error">{{
          errors.branchIds
        }}</small
        ><small v-else>Xizmat mavjud bo‘lgan filiallarni tanlang</small>
      </fieldset>
      <div v-if="draft.type !== 'consultation'" class="sv-timed-row">
        <span>Vaqt bo‘yicha yozilish</span
        ><button
          type="button"
          :data-permission="service ? 'services.update' : 'services.create'"
          role="switch"
          class="sv-switch"
          aria-label="Vaqt bo‘yicha yozilish"
          :aria-checked="draft.timed"
          :class="{ on: draft.timed }"
          @click="draft.timed = !draft.timed"
        >
          <span />
        </button>
      </div>
      <div class="sv-two-columns">
        <label
          >Narxi <em>*</em
          ><span class="sv-unit-input"
            ><input
              v-model="draft.price"
              aria-label="Narxi"
              inputmode="decimal"
              placeholder="0"
              :aria-invalid="!!errors.price"
            /><span>so‘m</span></span
          ><small v-if="errors.price" class="sv-error">{{
            errors.price
          }}</small></label
        ><label v-if="draft.type === 'consultation' || draft.timed"
          >Davomiyligi <em>*</em
          ><span class="sv-unit-input"
            ><input
              v-model="draft.duration"
              aria-label="Davomiyligi"
              inputmode="numeric"
              :aria-invalid="!!errors.duration"
            /><span>daqiqa</span></span
          ><small v-if="errors.duration" class="sv-error">{{
            errors.duration
          }}</small
          ><small v-else>Qabul vaqtini band qilish uchun</small></label
        >
      </div>
      <div v-if="draft.type === 'laboratory'" class="sv-two-columns">
        <label
          >Natija tayyor bo‘lish muddati <em>*</em
          ><input
            v-model="draft.turnaround"
            aria-label="Natija tayyor bo‘lish muddati"
            inputmode="decimal"
            :aria-invalid="!!errors.turnaround"
          /><small v-if="errors.turnaround" class="sv-error">{{
            errors.turnaround
          }}</small></label
        ><label
          >Muddat birligi<select
            v-model="draft.turnaroundUnit"
            aria-label="Muddat birligi"
          >
            <option value="hour">soat</option>
            <option value="day">kun</option>
          </select></label
        >
      </div>
      <label
        >Tavsif <small>(ixtiyoriy)</small
        ><textarea
          v-model="draft.description"
          aria-label="Xizmat tavsifi"
          placeholder="Xizmat haqida qisqa ma’lumot"
          maxlength="2000"
        />
      </label>
      <div v-if="!service || statusAllowed" class="sv-active-row">
        <div>
          <strong>Faol holat</strong
          ><small>Xizmat katalogning faol ro‘yxatida ko‘rinadi</small>
        </div>
        <button
          type="button"
          class="sv-switch"
          :data-permission="service ? 'services.delete' : 'services.create'"
          role="switch"
          aria-label="Xizmat faol holati"
          :aria-checked="draft.active"
          :class="{ on: draft.active }"
          @click="draft.active = !draft.active"
        >
          <span /></button
        ><span>{{ draft.active ? "Faol" : "Faol emas" }}</span>
      </div>
    </form>
    <template #footer="{ close }"
      ><footer class="sv-modal-footer">
        <small><em>*</em> Majburiy maydonlar</small
        ><button
          class="sv-button"
          type="button"
          :disabled="saving"
          @click="close"
        >
          Bekor qilish</button
        ><button
          class="sv-button sv-primary"
          type="submit"
          form="sv-service-form"
          :disabled="saving"
          :data-permission="service ? 'services.update' : 'services.create'"
        >
          <Check :size="19" />Saqlash
        </button>
      </footer></template
    ></WorkspaceDialog
  >
</template>
