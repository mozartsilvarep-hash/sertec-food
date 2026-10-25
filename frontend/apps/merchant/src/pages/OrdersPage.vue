<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  ApiError, CANCELLATION_REASONS, describeError, formatMoney, formatTime, merchantOrdersApi,
  type CancellationReason, type MerchantOrder, type OrderStatus,
} from '@sertec/api-client'
import { SButton, SPageState, SSheet } from '@sertec/ui'
import { notify } from '../notify'
import { can, me } from '../session'

const view = ref<'active' | 'finished'>('active')
const orders = ref<MerchantOrder[]>([])
const state = ref<'loading' | 'ready' | 'error' | 'offline'>('loading')
const selected = ref<MerchantOrder | null>(null)
const acting = ref(false)
const eta = ref(40)
const cancelling = ref<OrderStatus | null>(null)
const reason = ref<CancellationReason>('out_of_stock')
const note = ref('')
const manage = can('orders.manage')
const tz = computed(() => me.value?.tenant.timezone ?? 'America/Sao_Paulo')
let timer: ReturnType<typeof setInterval> | undefined

const columns = computed(() => {
  if (view.value === 'finished') return [{ title: 'Finalizados', items: orders.value }]
  const by = (s: OrderStatus[]) => orders.value.filter((o) => s.includes(o.status))
  return [
    { title: 'Novos', items: by(['pending_acceptance']) },
    { title: 'Em preparo', items: by(['accepted', 'preparing']) },
    { title: 'Prontos e em rota', items: by(['ready', 'out_for_delivery']) },
  ]
})

function minutesAgo(iso: string): string {
  const m = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000))
  return m < 1 ? 'agora' : m < 60 ? `há ${m} min` : `às ${formatTime(iso, tz.value)}`
}

async function load(silent = false) {
  if (!silent) state.value = 'loading'
  try {
    orders.value = (await merchantOrdersApi.list(view.value)).data
    state.value = 'ready'
  } catch (error) {
    if (!silent) state.value = error instanceof ApiError && error.code === 'network_error' ? 'offline' : 'error'
  }
}

async function open(order: MerchantOrder) {
  selected.value = order
  cancelling.value = null
  selected.value = await merchantOrdersApi.get(order.id)
  eta.value = selected.value.eta_minutes ?? 40
}

async function advance(status: OrderStatus) {
  if (!selected.value) return
  if ((status === 'rejected' || status === 'cancelled') && cancelling.value !== status) {
    cancelling.value = status
    return
  }
  acting.value = true
  try {
    const updated = await merchantOrdersApi.setStatus(selected.value.id, {
      status,
      eta_minutes: status === 'accepted' ? eta.value : null,
      reason: cancelling.value ? reason.value : null,
      note: cancelling.value ? note.value.trim() || null : null,
    })
    selected.value = updated
    cancelling.value = null
    note.value = ''
    notify(`Pedido #${updated.number}: ${updated.status_label}`)
    await load(true)
  } catch (error) {
    notify(describeError(error).message, 'error')
    await load(true)
  } finally {
    acting.value = false
  }
}

function switchView(v: 'active' | 'finished') {
  view.value = v
  void load()
}

function actionLabel(status: OrderStatus): string {
  return ({
    accepted: 'Aceitar pedido', preparing: 'Iniciar preparo', ready: 'Marcar como pronto',
    out_for_delivery: 'Saiu para entrega', completed: 'Concluir', rejected: 'Recusar', cancelled: 'Cancelar pedido',
  } as Record<string, string>)[status] ?? status
}

onMounted(() => {
  void load()
  // Atualização periódica até o painel em tempo real (Sprint 4).
  timer = setInterval(() => load(true), 15_000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <h1 class="font-display text-2xl font-bold">Pedidos</h1>
    <div class="inline-flex rounded-full border border-line bg-surface p-1" role="tablist">
      <button
        v-for="v in (['active', 'finished'] as const)" :key="v" type="button" role="tab" :aria-selected="view === v"
        class="rounded-full px-4 py-1.5 text-sm font-medium" :class="view === v ? 'bg-ink text-white' : 'text-muted'" @click="switchView(v)"
      >
        {{ v === 'active' ? 'Em andamento' : 'Finalizados' }}
      </button>
    </div>
  </div>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'offline'" state="offline" @retry="load()" />
  <SPageState v-else-if="state === 'error'" state="error" @retry="load()" />
  <SPageState v-else-if="orders.length === 0" state="empty" :title="view === 'active' ? 'Nenhum pedido em andamento' : 'Nenhum pedido finalizado'" message="Novos pedidos aparecem aqui automaticamente." />

  <div v-else class="grid gap-6" :class="view === 'active' && 'lg:grid-cols-3'">
    <section v-for="col in columns" :key="col.title" :aria-label="col.title">
      <h2 class="mb-2 text-sm font-semibold text-muted">{{ col.title }} <span class="tabular-nums">({{ col.items.length }})</span></h2>
      <ul class="grid gap-2">
        <li v-for="o in col.items" :key="o.id">
          <button
            type="button" class="w-full rounded-(--radius-card) border bg-surface p-4 text-left hover:border-ink"
            :class="o.status === 'pending_acceptance' ? 'border-brand-600 ring-2 ring-brand-100' : 'border-line'" @click="open(o)"
          >
            <span class="flex items-baseline justify-between gap-2">
              <span class="font-display text-lg font-bold">#{{ o.number }}</span>
              <span class="text-sm text-muted">{{ minutesAgo(o.placed_at) }}</span>
            </span>
            <span class="mt-1 block text-sm">{{ o.customer.name }} · {{ o.fulfillment_type === 'delivery' ? o.address?.neighborhood : 'Retirada' }}</span>
            <span class="mt-1 flex items-baseline justify-between text-sm">
              <span class="text-muted">{{ o.status_label }}</span>
              <span class="font-semibold tabular-nums">{{ formatMoney(o.total) }}</span>
            </span>
          </button>
        </li>
      </ul>
    </section>
  </div>

  <SSheet :open="selected !== null" :label="selected ? `Pedido ${selected.number}` : 'Pedido'" @close="selected = null">
    <template v-if="selected">
      <div class="overflow-y-auto px-5 pt-5 pb-4">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h2 class="font-display text-2xl font-bold">Pedido #{{ selected.number }}</h2>
            <p class="text-sm text-muted">{{ selected.status_label }} · {{ formatTime(selected.placed_at, tz) }} · {{ selected.branch.name }}</p>
          </div>
          <button type="button" class="-mr-2 grid size-10 place-items-center rounded-full text-2xl text-muted hover:bg-canvas" aria-label="Fechar" @click="selected = null">×</button>
        </div>

        <dl class="mt-4 grid gap-3 text-sm">
          <div><dt class="font-semibold">Cliente</dt><dd>{{ selected.customer.name }} · <a :href="`tel:${selected.customer.phone.replace(/\D/g, '')}`" class="text-brand-700 underline">{{ selected.customer.phone }}</a></dd></div>
          <div v-if="selected.fulfillment_type === 'delivery' && selected.address">
            <dt class="font-semibold">Entrega ({{ selected.delivery_zone }})</dt>
            <dd>{{ selected.address.street }}, {{ selected.address.number }}<template v-if="selected.address.complement"> — {{ selected.address.complement }}</template><br>{{ selected.address.neighborhood }}<template v-if="selected.address.reference"> · Ref.: {{ selected.address.reference }}</template></dd>
          </div>
          <div v-else><dt class="font-semibold">Retirada no local</dt></div>
          <div><dt class="font-semibold">Pagamento</dt><dd>{{ selected.payment_method_label }}<template v-if="selected.change_for"> · troco para {{ formatMoney(selected.change_for) }}</template></dd></div>
          <div v-if="selected.notes"><dt class="font-semibold">Observações</dt><dd>{{ selected.notes }}</dd></div>
        </dl>

        <ul class="mt-4 divide-y divide-line border-y border-line">
          <li v-for="(item, i) in selected.items ?? []" :key="i" class="py-2 text-sm">
            <p class="flex justify-between gap-3"><span class="font-semibold">{{ item.quantity }}× {{ item.name }}<span v-if="item.variant !== 'Única' && item.variant !== 'Único'" class="font-normal"> ({{ item.variant }})</span></span><span class="tabular-nums">{{ formatMoney(item.line_total) }}</span></p>
            <p v-for="(opt, j) in item.options" :key="j" class="text-muted">{{ opt.quantity > 1 ? `${opt.quantity}× ` : '' }}{{ opt.name }}</p>
            <p v-if="item.notes" class="font-medium text-danger">Obs.: {{ item.notes }}</p>
          </li>
        </ul>
        <dl class="mt-2 grid gap-1 text-sm">
          <div v-for="t in selected.totals ?? []" :key="t.code" class="flex justify-between" :class="t.code === 'total' && 'font-bold'"><dt>{{ t.label }}</dt><dd class="tabular-nums">{{ formatMoney(t.amount) }}</dd></div>
        </dl>
      </div>

      <footer v-if="manage && selected.next_statuses.length" class="grid gap-3 border-t border-line bg-surface px-5 py-4">
        <template v-if="cancelling">
          <label class="grid gap-1.5 text-sm font-medium">Motivo
            <select v-model="reason" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3">
              <option v-for="r in CANCELLATION_REASONS" :key="r.value" :value="r.value">{{ r.label }}</option>
            </select>
          </label>
          <input v-model="note" maxlength="300" placeholder="Detalhe (opcional)" aria-label="Detalhe do motivo" class="min-h-11 rounded-(--radius-control) border border-line px-3">
          <div class="flex gap-2">
            <SButton class="flex-1" :loading="acting" @click="advance(cancelling)">Confirmar: {{ actionLabel(cancelling).toLowerCase() }}</SButton>
            <SButton variant="ghost" @click="cancelling = null">Voltar</SButton>
          </div>
        </template>
        <template v-else>
          <label v-if="selected.status === 'pending_acceptance'" class="flex items-center gap-2 text-sm">
            Tempo estimado
            <select v-model.number="eta" class="min-h-10 rounded-lg border border-line bg-surface px-2">
              <option v-for="m in [20, 30, 40, 50, 60, 75, 90]" :key="m" :value="m">{{ m }} min</option>
            </select>
          </label>
          <div class="flex flex-wrap gap-2">
            <SButton
              v-for="n in selected.next_statuses"
              :key="n.status"
              :variant="n.status === 'rejected' || n.status === 'cancelled' ? 'ghost' : 'primary'"
              :class="n.status !== 'rejected' && n.status !== 'cancelled' && 'flex-1'"
              :loading="acting"
              @click="advance(n.status)"
            >
              {{ actionLabel(n.status) }}
            </SButton>
          </div>
        </template>
      </footer>
    </template>
  </SSheet>
</template>
