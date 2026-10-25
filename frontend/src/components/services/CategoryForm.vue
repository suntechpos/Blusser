<script setup lang="ts">
import { computed, ref } from "vue";
import { FolderPlus, Check } from "lucide-vue-next";
import WorkspaceDialog from "../doctor/WorkspaceDialog.vue";
import CategoryBadge from "./CategoryBadge.vue";
import {
  blankCategory,
  palette,
  validateCategory,
  type Category,
  type CategoryDraft,
} from "../../services/serviceCatalog";
const props = defineProps<{
  categories: Category[];
  category?: Category;
  saving?: boolean;
  serverError?: string;
  statusAllowed?: boolean;
}>();
const emit = defineEmits<{ close: []; save: [draft: CategoryDraft] }>();
const draft = ref<CategoryDraft>(
    props.category ? { ...props.category } : blankCategory(),
  ),
  initial = JSON.stringify(draft.value),
  errors = ref<Record<string, string>>({});
const dirty = computed(() => JSON.stringify(draft.value) !== initial);
const preview = computed(() => ({
  ...draft.value,
  id: props.category?.id ?? "preview",
  service_count: props.category?.service_count ?? 0,
  name: draft.value.name.trim() || "Kategoriya nomi",
}));
const colorNames = {
  blue: "Ko‘k",
  cyan: "Moviy",
  green: "Yashil",
  purple: "Binafsha",
  orange: "To‘q sariq",
  pink: "Pushti",
  slate: "Kulrang",
};
function save() {
  if (props.saving) return;
  errors.value = validateCategory(
    draft.value,
    props.categories,
    props.category?.id,
  );
  if (!Object.keys(errors.value).length) emit("save", { ...draft.value });
}
</script>
<template>
  <WorkspaceDialog
    class="sv-dialog sv-category-dialog"
    :title="category ? 'Kategoriyani tahrirlash' : 'Kategoriya qo‘shish'"
    subtitle="Xizmatlarni guruhlash uchun yangi kategoriya"
    :icon="FolderPlus"
    :dirty="!saving && dirty"
    @close="emit('close')"
    ><form
      id="sv-category-form"
      class="sv-form"
      novalidate
      :inert="saving || undefined"
      :aria-busy="saving"
      :data-permission="category ? 'categories.update' : 'categories.create'"
      @submit.prevent="save"
    >
      <p v-if="serverError" class="sv-error" role="alert">{{ serverError }}</p>
      <label
        >Kategoriya nomi <em>*</em
        ><input
          v-model="draft.name"
          aria-label="Kategoriya nomi"
          placeholder="Masalan, Laboratoriya"
          maxlength="120"
          :aria-invalid="!!errors.name"
          aria-describedby="sv-category-name-error"
        /><small
          v-if="errors.name"
          id="sv-category-name-error"
          class="sv-error"
          >{{ errors.name }}</small
        ></label
      ><label
        >Tavsif <small>(ixtiyoriy)</small
        ><textarea
          v-model="draft.description"
          aria-label="Kategoriya tavsifi"
          placeholder="Kategoriya haqida qisqa izoh"
          maxlength="2000"
        />
      </label>
      <fieldset class="sv-palette">
        <legend>Kategoriya rangi</legend>
        <div>
          <button
            v-for="color in palette"
            :key="color"
            type="button"
            :data-permission="
              category ? 'categories.update' : 'categories.create'
            "
            :class="[color, { selected: draft.color === color }]"
            :aria-label="`Rang: ${colorNames[color]}`"
            :aria-pressed="draft.color === color"
            @click="draft.color = color"
          >
            <Check v-if="draft.color === color" :size="22" />
          </button>
        </div>
      </fieldset>
      <div class="sv-category-preview">
        <CategoryBadge :category="preview" /><small
          >Ro‘yxatdagi ko‘rinishi</small
        >
      </div>
      <div v-if="!category || statusAllowed" class="sv-active-row">
        <div>
          <strong>Faol holat</strong
          ><small>Xizmat qo‘shishda tanlash mumkin</small>
        </div>
        <button
          type="button"
          :data-permission="
            category ? 'categories.status' : 'categories.create'
          "
          class="sv-switch"
          role="switch"
          aria-label="Kategoriya faol holati"
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
        <button
          class="sv-button"
          type="button"
          :disabled="saving"
          @click="close"
        >
          Bekor qilish</button
        ><button
          class="sv-button sv-primary"
          type="submit"
          form="sv-category-form"
          :disabled="saving"
          :data-permission="
            category ? 'categories.update' : 'categories.create'
          "
        >
          <Check :size="19" />Saqlash
        </button>
      </footer></template
    ></WorkspaceDialog
  >
</template>
