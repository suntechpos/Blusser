<script setup lang="ts">
import DateField from "../employees/DateField.vue";
import type { PatientDraft } from "../../mocks/reception";
import { localDate } from "../../mocks/reception";
const model = defineModel<PatientDraft>({ required: true });
defineProps<{ errors: Record<string, string>; maxDate?: string }>();
</script>
<template>
  <div class="rp-form-grid">
    <label
      >Familiya <span>*</span
      ><input
        v-model="model.lastName"
        aria-label="Familiya *"
        autocomplete="family-name"
        :aria-invalid="!!errors.lastName"
        placeholder="Familiyani kiriting"
        maxlength="255"
      /><small v-if="errors.lastName" class="rp-error">{{
        errors.lastName
      }}</small></label
    ><label
      >Ism <span>*</span
      ><input
        v-model="model.firstName"
        aria-label="Ism *"
        autocomplete="given-name"
        :aria-invalid="!!errors.firstName"
        placeholder="Ismni kiriting"
        maxlength="255"
      /><small v-if="errors.firstName" class="rp-error">{{
        errors.firstName
      }}</small></label
    ><label
      >Telefon raqami<input
        v-model="model.phone"
        aria-label="Telefon raqami"
        autocomplete="tel"
        type="tel"
        placeholder="+998 __ ___ __ __"
        maxlength="32"
        :aria-invalid="!!errors.phone"
      /><small v-if="errors.phone" class="rp-error">{{
        errors.phone
      }}</small></label
    ><label
      >Tug‘ilgan sana <span>*</span
      ><DateField
        v-model="model.dob"
        label="Tug‘ilgan sana *"
        :max="maxDate || localDate()"
      /><small v-if="errors.dob" class="rp-error">{{
        errors.dob
      }}</small></label
    ><label
      >Otasining ismi<input
        v-model="model.middleName"
        aria-label="Otasining ismi"
        autocomplete="additional-name"
        placeholder="Otasining ismini kiriting"
        maxlength="255" /></label
    ><label
      >Jinsi<select v-model="model.gender" aria-label="Jinsi">
        <option value="">Tanlang</option>
        <option value="male">Erkak</option>
        <option value="female">Ayol</option>
      </select></label
    >
  </div>
</template>
