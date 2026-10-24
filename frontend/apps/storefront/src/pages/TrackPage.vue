<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ApiError, formatMoney, formatTime, orderApi, type TrackedOrder } from '@sertec/api-client'
import { SPageState } from '@sertec/ui'
import BackHeader from '../components/BackHeader.vue'
import { store } from '../shop'

const props = defineProps<{ token: string }>()
const order = ref<TrackedOrder | null>(null)
const state = ref<'loading' | 'ready' | 'error' | 'offline' | 'notfound'>('loading')
let timer: ReturnType<typeof setInterval> | undefined

const reached = computed(() => {
  if (!order.value) return -1
  return order.value.steps.findIndex((s) => s.status === order.value?.status)
})
const cancelled = computed(() => order.value && ['rejected', 'cancelled'].includes(order.value.status))
const tz = computed(() => store.value?.timezone ?? 'America/Sao_Paulo')

async function load(silent = false) {
  if (!silent) state.value = 'loading'
  try {
    order.value = await orderApi.track(props.token)
    state.value = 'ready'
    if (order.value.final) clearInterval(timer)
  } catch (error) {
    if (silent) return
    if (error instanceof ApiError && error.status === 404) state.value = 'notfound'
    else state.value = error instanceof ApiError && error.code === 'network_error' ? 'offline' : 'error'
  }
}

onMounted(async () => {
  await load()
  // Atualização simples a cada 20 s; tempo real chega na Sprint 4.
  timer = setInterval(() => load(true), 20_000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <BackHeader title="Seu pedido" />
  <main class="mx-auto max-w-2xl px-4 pt-6 pb-16">
    <SPageState v-if="state === 'loading'" state="loading" />
    <SPageState v-else-if="state === 'notfound'" state="empty" title="Pedido não encontrado" message="Confira se o link está completo." />
    <SPageState v-else-if="state === 'offline'" state="offline" @retry="load()" />
    <SPageState v-else-if="state === 'error' || !order" state="error" @retry="load()" />

    <template v-else>
      <p class="text-sm text-muted">Pedido #{{ order.number }} · {{ formatTime(order.placed_at, tz) }}</p>
      <h2 class="mt-1 font-display text-3xl leading-tight font-extrabold" aria-live="polite">{{ order.status_label }}</h2>
      <p v-if="!order.final && order.eta_minutes" class="mt-2 text-muted">
        Previsão: cerca de {{ order.eta_minutes }} min {{ order.fulfillment_type === 'delivery' ? 'para chegar' : 'para retirar' }}.
      </p>
      <p v-if="cancelled" class="mt-2 text-danger">Fale com {{ order.branch.name }}{{ order.branch.phone ? ` pelo ${order.branch.phone}` : '' }} se tiver dúvidas.</p>

      <ol v-if="!cancelled" class="mt-8 grid gap-0" aria-label="Etapas do pedido">
        <li v-for="(step, i) in order.steps" :key="step.status" class="relative flex gap-4 pb-6 last:pb-0">
          <span
            class="relative z-[1] mt-0.5 grid size-6 shrink-0 place-items-center rounded-full border-2 text-xs font-bold"
            :class="i <= reached ? 'border-brand-600 bg-brand-600 text-white' : 'border-line bg-surface text-muted'"
            aria-hidden="true"
          >{{ i < reached ? '✓' : '' }}</span>
          <span v-if="i < order.steps.length - 1" class="absolute top-6 left-[11px] h-full w-0.5" :class="i < reached ? 'bg-brand-600' : 'bg-line'" aria-hidden="true" />
          <span :class="i === reached ? 'font-semibold text-ink' : i < reached ? 'text-ink' : 'text-muted'">
            {{ step.label }}<span v-if="i === reached" class="sr-only"> (etapa atual)</span>
          </span>
        </li>
      </ol>

      <section class="mt-10 rounded-(--radius-card) border border-line bg-surface p-4" aria-label="Itens do pedido">
        <ul class="divide-y divide-line">
          <li v-for="(item, i) in order.items" :key="i" class="flex justify-between gap-3 py-2 text-sm">
            <span>
              <span class="font-medium">{{ item.quantity }}× {{ item.name }}</span>
              <span v-if="item.options.length" class="block text-muted">{{ item.options.map((o) => o.name).join(', ') }}</span>
            </span>
            <span class="tabular-nums">{{ formatMoney(item.line_total) }}</span>
          </li>
        </ul>
        <dl class="mt-3 grid gap-1 border-t border-line pt-3 text-sm">
          <div v-for="t in order.totals" :key="t.code" class="flex justify-between" :class="t.code === 'total' && 'text-base font-bold'">
            <dt :class="t.code !== 'total' && 'text-muted'">{{ t.label }}</dt>
            <dd class="tabular-nums">{{ formatMoney(t.amount) }}</dd>
          </div>
        </dl>
        <p class="mt-3 text-sm text-muted">
          {{ order.payment_method_label }} · {{ order.fulfillment_type === 'delivery' ? `Entrega em ${order.neighborhood}` : `Retirada em ${order.branch.name}` }}
        </p>
      </section>

      <RouterLink to="/" class="mt-8 block text-center font-semibold text-brand-700 hover:underline">Voltar ao cardápio</RouterLink>
    </template>
  </main>
</template>
