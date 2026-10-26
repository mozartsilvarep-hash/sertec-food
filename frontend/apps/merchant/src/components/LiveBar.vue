<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { LATE_AFTER_MINUTES, disableAlerts, enableAlerts, offline, pulse, soundEnabled, waitingMinutes } from '../live'

/** Faixa fixa do painel: pedidos novos, atraso no aceite e alertas sonoros. */
const route = useRoute()
const pending = computed(() => pulse.value?.pending ?? 0)
const waiting = computed(() => {
  const m = waitingMinutes.value
  return m < 60 ? `${m} min` : `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')} min`
})
const late = computed(() => pending.value > 0 && waitingMinutes.value >= LATE_AFTER_MINUTES)
</script>

<template>
  <div
    class="sticky top-0 z-20 flex flex-wrap items-center gap-x-4 gap-y-2 border-b px-4 py-2 text-sm md:px-8"
    :class="late ? 'border-danger bg-danger text-white' : pending > 0 ? 'border-brand-600 bg-brand-600 text-brand-contrast' : 'border-line bg-surface text-muted'"
    role="region"
    aria-label="Pedidos ao vivo"
  >
    <p class="flex items-center gap-2 font-semibold" aria-live="assertive">
      <span class="relative flex size-2.5" aria-hidden="true">
        <span v-if="pending > 0" class="absolute inline-flex size-full animate-ping rounded-full bg-current opacity-60" />
        <span class="relative inline-flex size-2.5 rounded-full" :class="offline ? 'bg-muted' : pending > 0 ? 'bg-current' : 'bg-success'" />
      </span>
      <template v-if="offline">Sem conexão — tentando de novo…</template>
      <template v-else-if="pending === 0">Ao vivo · nenhum pedido esperando</template>
      <template v-else>
        {{ pending }} {{ pending === 1 ? 'pedido novo' : 'pedidos novos' }}
        <span v-if="late"> · o mais antigo espera há {{ waiting }}</span>
      </template>
    </p>
    <RouterLink v-if="pending > 0 && route.path !== '/pedidos'" to="/pedidos" class="font-semibold underline underline-offset-2">Ver pedidos</RouterLink>
    <button
      type="button"
      class="ml-auto rounded-full border px-3 py-1 font-medium"
      :class="pending > 0 ? 'border-current' : 'border-line text-ink hover:bg-canvas'"
      :aria-pressed="soundEnabled"
      @click="soundEnabled ? disableAlerts() : enableAlerts()"
    >
      {{ soundEnabled ? '🔔 Som ligado' : '🔕 Ativar som de novos pedidos' }}
    </button>
  </div>
</template>
