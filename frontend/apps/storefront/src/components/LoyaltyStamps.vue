<script setup lang="ts">
import type { LoyaltyStatus } from '@sertec/api-client'

/** Cartão de selos: "2 de 3 selos" + prêmios disponíveis. */
defineProps<{ status: LoyaltyStatus; title?: string }>()
</script>

<template>
  <div class="rounded-(--radius-card) border border-brand-600/40 bg-brand-50 p-4" aria-label="Cartão fidelidade">
    <p class="text-sm font-semibold">{{ title ?? 'Cartão fidelidade' }}</p>
    <div class="mt-2 flex flex-wrap gap-1.5" role="img" :aria-label="`${status.stamps} de ${status.required} selos`">
      <span
        v-for="i in status.required"
        :key="i"
        class="grid size-7 place-items-center rounded-full border-2 text-xs font-bold"
        :class="i <= status.stamps ? 'border-brand-600 bg-brand-600 text-brand-contrast' : 'border-brand-600/40 bg-surface text-muted'"
      >{{ i <= status.stamps ? '★' : i }}</span>
    </div>
    <p class="mt-2 text-sm">
      {{ status.stamps }} de {{ status.required }} selos.
      <template v-if="status.rewards > 0"><strong>Você tem {{ status.rewards === 1 ? '1 prêmio' : `${status.rewards} prêmios` }}: {{ status.reward_label }}.</strong></template>
      <template v-else>Faltam {{ status.required - status.stamps }} para ganhar {{ status.reward_label }}.</template>
    </p>
  </div>
</template>
