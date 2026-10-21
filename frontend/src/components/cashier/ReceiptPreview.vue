<script setup lang="ts">
import type { Payment, Refund } from "../../mocks/cashier";
import { receiptLines } from "./receipts";
defineProps<{ record: Payment | Refund; payment?: Payment }>();
</script>
<template>
  <div class="cs-receipt-stage">
    <article class="cs-receipt" aria-label="80 mm namunaviy chek">
      <div class="cs-receipt-brand">Blusser<small>MEDICAL SOFTWARE</small></div>
      <p
        v-for="(line, i) in receiptLines(record, payment)"
        :key="i"
        :class="{
          'cs-receipt-total': /^(QAYTIM|QAYTARILDI|Aynan shu to‘lov)/.test(
            line,
          ),
        }"
      >
        {{ line }}
      </p>
    </article>
  </div>
  <p class="cs-paper-size">80 mm · Namunaviy chek. Fiskal hujjat emas</p>
</template>
