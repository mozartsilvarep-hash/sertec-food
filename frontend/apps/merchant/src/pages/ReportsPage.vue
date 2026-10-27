<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { formatMoney, merchantApi, reportsApi, type Branch, type ReportSummary } from '@sertec/api-client'
import { SButton, SPageState } from '@sertec/ui'
import BranchPicker from '../components/BranchPicker.vue'
import { me } from '../session'

/** Relatórios de pedidos: faturamento, mais vendidos, dias e horários de pico. */
const tz = computed(() => me.value?.tenant.timezone ?? 'America/Sao_Paulo')
const day = (offset = 0) => new Date(Date.now() - offset * 86_400_000).toLocaleDateString('en-CA', { timeZone: tz.value })
const period = reactive({ from: day(29), to: day() })
const branches = ref<Branch[]>([])
const branchId = ref('')
const data = ref<ReportSummary | null>(null)
const state = ref<'loading' | 'ready' | 'error'>('loading')

const PRESETS = [{ label: 'Hoje', from: 0 }, { label: '7 dias', from: 6 }, { label: '30 dias', from: 29 }, { label: '90 dias', from: 89 }]

async function load() {
  state.value = 'loading'
  try {
    if (branches.value.length === 0) branches.value = await merchantApi.branches()
    data.value = await reportsApi.summary({ from: period.from, to: period.to, branch: branchId.value || null })
    state.value = 'ready'
  } catch {
    state.value = 'error'
  }
}

function preset(days: number) {
  period.from = day(days)
  period.to = day()
  void load()
}

const maxDay = computed(() => Math.max(1, ...(data.value?.by_day ?? []).map((d) => Number(d.revenue))))
const hours = computed(() => {
  const map = new Map((data.value?.by_hour ?? []).map((h) => [h.hour, h.orders]))
  const list = Array.from({ length: 24 }, (_, h) => ({ hour: h, orders: map.get(h) ?? 0 }))
  const first = list.findIndex((h) => h.orders > 0)
  const last = 23 - [...list].reverse().findIndex((h) => h.orders > 0)
  return first < 0 ? [] : list.slice(first, last + 1)
})
const maxHour = computed(() => Math.max(1, ...hours.value.map((h) => h.orders)))
const peak = computed(() => hours.value.reduce((a, b) => (b.orders > a.orders ? b : a), { hour: -1, orders: 0 }))
const fulfillmentTotal = computed(() => (data.value?.by_fulfillment?.delivery ?? 0) + (data.value?.by_fulfillment?.pickup ?? 0) + (data.value?.by_fulfillment?.counter ?? 0))

watch(branchId, () => void load())
onMounted(load)
</script>

<template>
  <h1 class="mb-2 font-display text-2xl font-bold">Relatórios</h1>
  <p class="mb-6 max-w-[65ch] text-sm text-muted">Faturamento considera só os pedidos concluídos. Para entradas e saídas do dinheiro, veja o Caixa.</p>
  <BranchPicker v-model="branchId" :branches="[{ id: '', name: 'Todas as unidades', phone: null, address: null, active: true }, ...branches]" />
  <div class="mb-6 flex flex-wrap items-end gap-2">
    <SButton v-for="p in PRESETS" :key="p.label" variant="secondary" @click="preset(p.from)">{{ p.label }}</SButton>
    <label class="grid gap-1.5 text-sm font-medium">De<input v-model="period.from" type="date" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3"></label>
    <label class="grid gap-1.5 text-sm font-medium">Até<input v-model="period.to" type="date" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3"></label>
    <SButton @click="load">Ver</SButton>
    <a :href="reportsApi.csvUrl({ from: period.from, to: period.to, branch: branchId || null })" class="inline-flex min-h-11 items-center rounded-(--radius-control) border border-line px-4 text-sm font-semibold hover:bg-canvas">Baixar pedidos (CSV)</a>
  </div>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'error' || !data" state="error" @retry="load" />
  <div v-else class="grid gap-8">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <div class="rounded-(--radius-card) border border-line bg-surface p-4"><p class="text-sm text-muted">Faturamento</p><p class="text-2xl font-bold tabular-nums">{{ formatMoney(data.revenue) }}</p></div>
      <div class="rounded-(--radius-card) border border-line bg-surface p-4"><p class="text-sm text-muted">Pedidos concluídos</p><p class="text-2xl font-bold tabular-nums">{{ data.completed }}</p></div>
      <div class="rounded-(--radius-card) border border-line bg-surface p-4"><p class="text-sm text-muted">Ticket médio</p><p class="text-2xl font-bold tabular-nums">{{ formatMoney(data.average_ticket) }}</p></div>
      <div class="rounded-(--radius-card) border border-line bg-surface p-4"><p class="text-sm text-muted">Cancelados</p><p class="text-2xl font-bold tabular-nums">{{ data.cancelled }}</p><p v-if="Number(data.refunded) > 0" class="text-sm text-muted">Reembolsado: {{ formatMoney(data.refunded) }}</p></div>
    </div>

    <section aria-label="Mais vendidos">
      <h2 class="mb-3 text-lg font-semibold">Mais vendidos</h2>
      <p v-if="!data.top_products?.length" class="text-sm text-muted">Nenhum pedido concluído no período.</p>
      <ol v-else class="divide-y divide-line rounded-(--radius-card) border border-line bg-surface text-sm">
        <li v-for="(p, i) in data.top_products" :key="p.name" class="flex items-baseline gap-3 px-4 py-2.5">
          <span class="w-5 text-muted tabular-nums">{{ i + 1 }}</span>
          <span class="min-w-0 flex-1 font-medium">{{ p.name }}</span>
          <span class="text-muted tabular-nums">{{ p.quantity }} un.</span>
          <span class="w-24 text-right tabular-nums">{{ formatMoney(p.total) }}</span>
        </li>
      </ol>
    </section>

    <div class="grid gap-8 lg:grid-cols-2">
      <section v-if="(data.by_day?.length ?? 0) > 0" aria-label="Faturamento por dia">
        <h2 class="mb-3 text-lg font-semibold">Faturamento por dia</h2>
        <ul class="grid gap-1.5">
          <li v-for="d in data.by_day" :key="d.date" class="grid grid-cols-[3.5rem_1fr_6rem] items-center gap-3 text-sm">
            <span class="text-muted tabular-nums">{{ d.date.split('-').reverse().slice(0, 2).join('/') }}</span>
            <span class="h-3 rounded-full bg-brand-600" :style="{ width: `${Math.max(2, (Number(d.revenue) / maxDay) * 100)}%` }" />
            <span class="text-right tabular-nums">{{ formatMoney(d.revenue) }}</span>
          </li>
        </ul>
      </section>

      <section v-if="hours.length" aria-label="Horários de pico">
        <h2 class="mb-1 text-lg font-semibold">Horários de pico</h2>
        <p class="mb-3 text-sm text-muted">Mais pedidos às {{ peak.hour }}h ({{ peak.orders }} no período).</p>
        <div class="flex h-36 items-end gap-1" role="img" :aria-label="`Pedidos por hora; pico às ${peak.hour}h`">
          <div v-for="h in hours" :key="h.hour" class="flex flex-1 flex-col items-center gap-1">
            <span class="w-full rounded-t bg-brand-600" :class="h.hour === peak.hour ? 'opacity-100' : 'opacity-60'" :style="{ height: `${(h.orders / maxHour) * 100}%`, minHeight: h.orders ? '4px' : '0' }" :title="`${h.hour}h: ${h.orders}`" />
            <span class="text-[10px] text-muted tabular-nums">{{ h.hour }}</span>
          </div>
        </div>
      </section>
    </div>

    <div class="grid gap-8 lg:grid-cols-2">
      <section aria-label="Formas de pagamento">
        <h2 class="mb-3 text-lg font-semibold">Formas de pagamento</h2>
        <table class="w-full text-sm">
          <tbody>
            <tr v-for="m in data.by_payment_method" :key="m.method" class="border-b border-line"><td class="py-2">{{ m.label }}</td><td class="text-right text-muted tabular-nums">{{ m.orders }} pedidos</td><td class="w-28 text-right tabular-nums">{{ formatMoney(m.total) }}</td></tr>
          </tbody>
        </table>
      </section>
      <section v-if="fulfillmentTotal" aria-label="Entrega, retirada e balcão">
        <h2 class="mb-3 text-lg font-semibold">Entrega, retirada e balcão</h2>
        <div class="flex h-4 overflow-hidden rounded-full bg-canvas">
          <span class="bg-brand-600" :style="{ width: `${((data.by_fulfillment?.delivery ?? 0) / fulfillmentTotal) * 100}%` }" />
          <span class="bg-brand-100" :style="{ width: `${((data.by_fulfillment?.pickup ?? 0) / fulfillmentTotal) * 100}%` }" />
          <span class="bg-ink" :style="{ width: `${((data.by_fulfillment?.counter ?? 0) / fulfillmentTotal) * 100}%` }" />
        </div>
        <p class="mt-2 text-sm"><span class="font-semibold">{{ data.by_fulfillment?.delivery }}</span> entregas · <span class="font-semibold">{{ data.by_fulfillment?.pickup }}</span> retiradas · <span class="font-semibold">{{ data.by_fulfillment?.counter ?? 0 }}</span> no balcão (PDV)</p>
      </section>
    </div>
  </div>
</template>
