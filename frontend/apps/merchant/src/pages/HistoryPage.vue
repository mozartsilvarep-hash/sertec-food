<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import {
  ApiError, describeError, formatDateTime, formatMoney, merchantOrdersApi, reportsApi, todayIn,
  type MerchantOrder, type OrderFilter, type PaymentMethod, type ReportSummary,
} from '@sertec/api-client'
import { SButton, SPageState } from '@sertec/ui'
import { can, me } from '../session'

const tz = computed(() => me.value?.tenant.timezone ?? 'America/Sao_Paulo')
const canReport = can('reports.view')

const periods = [
  { key: 'today', label: 'Hoje' },
  { key: 'yesterday', label: 'Ontem' },
  { key: '7d', label: '7 dias' },
  { key: '30d', label: '30 dias' },
  { key: 'custom', label: 'Período' },
] as const
type Period = typeof periods[number]['key']

const period = ref<Period>('today')
const form = reactive({ from: todayIn(tz.value), to: todayIn(tz.value), q: '', status: '', payment_method: '' as PaymentMethod | '' })
const orders = ref<MerchantOrder[]>([])
const cursor = ref<string | null>(null)
const summary = ref<ReportSummary | null>(null)
const state = ref<'loading' | 'ready' | 'error' | 'offline'>('loading')
const loadingMore = ref(false)
const error = ref<string | null>(null)

function setPeriod(p: Period) {
  period.value = p
  const today = todayIn(tz.value)
  if (p === 'today') Object.assign(form, { from: today, to: today })
  if (p === 'yesterday') Object.assign(form, { from: todayIn(tz.value, -1), to: todayIn(tz.value, -1) })
  if (p === '7d') Object.assign(form, { from: todayIn(tz.value, -6), to: today })
  if (p === '30d') Object.assign(form, { from: todayIn(tz.value, -29), to: today })
  if (p !== 'custom') void search()
}

const filter = computed<OrderFilter>(() => ({
  from: form.from || null, to: form.to || null, q: form.q.trim() || null, payment_method: form.payment_method || null,
}))
const view = computed(() => form.status || 'all')
const csvHref = computed(() => reportsApi.csvUrl({ ...filter.value, view: view.value }))

async function search() {
  state.value = 'loading'
  error.value = null
  try {
    const [list, sum] = await Promise.all([
      merchantOrdersApi.list(view.value, null, filter.value),
      canReport ? reportsApi.summary(filter.value) : Promise.resolve(null),
    ])
    orders.value = list.data
    cursor.value = list.meta.next_cursor
    summary.value = sum
    state.value = 'ready'
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      error.value = describeError(e).message
      state.value = 'ready'
    } else {
      state.value = e instanceof ApiError && e.code === 'network_error' ? 'offline' : 'error'
    }
  }
}

async function more() {
  if (!cursor.value) return
  loadingMore.value = true
  try {
    const list = await merchantOrdersApi.list(view.value, cursor.value, filter.value)
    orders.value.push(...list.data)
    cursor.value = list.meta.next_cursor
  } finally {
    loadingMore.value = false
  }
}

const STATUS_TONE: Record<string, string> = {
  completed: 'text-success', rejected: 'text-danger', cancelled: 'text-danger',
}

onMounted(search)
</script>

<template>
  <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <h1 class="font-display text-2xl font-bold">Histórico</h1>
    <a v-if="canReport" :href="csvHref" class="rounded-(--radius-control) border border-line bg-surface px-4 py-2 text-sm font-semibold hover:bg-canvas" download>Exportar CSV</a>
  </div>

  <form class="mb-6 grid gap-3" @submit.prevent="search">
    <div class="flex flex-wrap gap-1.5" role="group" aria-label="Período">
      <button
        v-for="p in periods" :key="p.key" type="button" :aria-pressed="period === p.key"
        class="rounded-full border px-3 py-1.5 text-sm font-medium"
        :class="period === p.key ? 'border-ink bg-ink text-white' : 'border-line bg-surface text-muted hover:text-ink'"
        @click="setPeriod(p.key)"
      >
        {{ p.label }}
      </button>
    </div>
    <div class="flex flex-wrap items-end gap-3 [&>label]:min-w-36 [&>label]:flex-1">
      <label class="grid gap-1 text-sm font-medium">De
        <input v-model="form.from" type="date" class="min-h-11 w-full min-w-0 rounded-(--radius-control) border border-line bg-surface px-3" @change="period = 'custom'">
      </label>
      <label class="grid gap-1 text-sm font-medium">Até
        <input v-model="form.to" type="date" class="min-h-11 w-full min-w-0 rounded-(--radius-control) border border-line bg-surface px-3" @change="period = 'custom'">
      </label>
      <label class="grid min-w-56! flex-[2]! gap-1 text-sm font-medium">Buscar
        <input v-model="form.q" type="search" placeholder="Nº, nome ou celular" class="min-h-11 w-full min-w-0 rounded-(--radius-control) border border-line bg-surface px-3">
      </label>
      <label class="grid gap-1 text-sm font-medium">Situação
        <select v-model="form.status" class="min-h-11 w-full min-w-0 rounded-(--radius-control) border border-line bg-surface px-3">
          <option value="">Todas</option>
          <option value="completed">Concluídos</option>
          <option value="active">Em andamento</option>
          <option value="rejected">Recusados</option>
          <option value="cancelled">Cancelados</option>
        </select>
      </label>
      <label class="grid gap-1 text-sm font-medium">Pagamento
        <select v-model="form.payment_method" class="min-h-11 w-full min-w-0 rounded-(--radius-control) border border-line bg-surface px-3">
          <option value="">Todos</option>
          <option value="pix">Pix</option>
          <option value="online_card">Cartão pelo site</option>
          <option value="card_on_delivery">Cartão na entrega</option>
          <option value="cash">Dinheiro</option>
        </select>
      </label>
      <SButton type="submit">Filtrar</SButton>
    </div>
    <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>
  </form>

  <section v-if="summary" aria-label="Resumo do período" class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-(--radius-card) border border-line bg-surface p-4">
      <p class="text-sm text-muted">Faturamento (concluídos)</p>
      <p class="mt-1 font-display text-2xl font-bold tabular-nums">{{ formatMoney(summary.revenue) }}</p>
    </div>
    <div class="rounded-(--radius-card) border border-line bg-surface p-4">
      <p class="text-sm text-muted">Pedidos concluídos</p>
      <p class="mt-1 font-display text-2xl font-bold tabular-nums">{{ summary.completed }} <span class="text-base font-normal text-muted">de {{ summary.orders }}</span></p>
    </div>
    <div class="rounded-(--radius-card) border border-line bg-surface p-4">
      <p class="text-sm text-muted">Ticket médio</p>
      <p class="mt-1 font-display text-2xl font-bold tabular-nums">{{ formatMoney(summary.average_ticket) }}</p>
    </div>
    <div class="rounded-(--radius-card) border border-line bg-surface p-4">
      <p class="text-sm text-muted">Cancelados · reembolsado</p>
      <p class="mt-1 font-display text-2xl font-bold tabular-nums">{{ summary.cancelled }} · {{ formatMoney(summary.refunded) }}</p>
    </div>
    <ul v-if="summary.by_payment_method.length" class="flex flex-wrap gap-x-6 gap-y-1 text-sm sm:col-span-2 lg:col-span-4">
      <li v-for="m in summary.by_payment_method" :key="m.method"><span class="text-muted">{{ m.label }}:</span> <strong class="tabular-nums">{{ formatMoney(m.total) }}</strong> <span class="text-muted">({{ m.orders }})</span></li>
    </ul>
  </section>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'offline'" state="offline" @retry="search" />
  <SPageState v-else-if="state === 'error'" state="error" @retry="search" />
  <SPageState v-else-if="orders.length === 0" state="empty" title="Nenhum pedido encontrado" message="Mude o período ou a busca." />

  <div v-else class="relative overflow-x-auto rounded-(--radius-card) border border-line bg-surface">
    <table class="w-full min-w-[44rem] text-sm">
      <thead class="border-b border-line text-left text-muted">
        <tr>
          <th scope="col" class="px-4 py-3 font-medium">Nº</th>
          <th scope="col" class="px-4 py-3 font-medium">Data</th>
          <th scope="col" class="px-4 py-3 font-medium">Cliente</th>
          <th scope="col" class="px-4 py-3 font-medium">Tipo</th>
          <th scope="col" class="px-4 py-3 font-medium">Pagamento</th>
          <th scope="col" class="px-4 py-3 font-medium">Situação</th>
          <th scope="col" class="px-4 py-3 text-right font-medium">Total</th>
          <th scope="col" class="px-4 py-3"><span class="sr-only">Ações</span></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-line">
        <tr v-for="o in orders" :key="o.id">
          <td class="px-4 py-3 font-semibold tabular-nums">#{{ o.number }}</td>
          <td class="px-4 py-3 whitespace-nowrap tabular-nums">{{ formatDateTime(o.placed_at, tz) }}</td>
          <td class="px-4 py-3">{{ o.customer.name }}</td>
          <td class="px-4 py-3">{{ o.fulfillment_type === 'delivery' ? `Entrega · ${o.address?.neighborhood ?? ''}` : 'Retirada' }}</td>
          <td class="px-4 py-3">{{ o.payment_method_label }}</td>
          <td class="px-4 py-3" :class="STATUS_TONE[o.status] ?? ''">{{ o.status_label }}</td>
          <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ formatMoney(o.total) }}</td>
          <td class="px-4 py-3 text-right"><RouterLink :to="`/pedidos/${o.id}/imprimir`" class="text-brand-700 hover:underline" :aria-label="`Imprimir pedido ${o.number}`">Imprimir</RouterLink></td>
        </tr>
      </tbody>
    </table>
    <div v-if="cursor" class="border-t border-line p-3 text-center">
      <SButton variant="secondary" :loading="loadingMore" @click="more">Carregar mais</SButton>
    </div>
  </div>
</template>
