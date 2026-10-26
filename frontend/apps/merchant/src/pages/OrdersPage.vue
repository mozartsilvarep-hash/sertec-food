<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  ApiError, CANCELLATION_REASONS, describeError, formatMoney, formatTime, formatTotal, merchantOrdersApi, paymentsApi, toCents,
  type CancellationReason, type MerchantOrder, type OrderStatus,
} from '@sertec/api-client'
import { SButton, SPageState, SSheet } from '@sertec/ui'
import { LATE_AFTER_MINUTES, now, pulse } from '../live'
import { notify } from '../notify'
import { can, me } from '../session'

const view = ref<'active' | 'finished'>('active')
const orders = ref<MerchantOrder[]>([])
const state = ref<'loading' | 'ready' | 'error' | 'offline'>('loading')
const selected = ref<MerchantOrder | null>(null)
// Conversa com o cliente já com o número do pedido (link wa.me, sem integração paga).
const customerWhatsApp = computed(() => {
  const o = selected.value
  const digits = o?.customer.phone.replace(/\D/g, '') ?? ''
  if (!o || (digits.length !== 10 && digits.length !== 11)) return null
  const first = o.customer.name.split(' ')[0] ?? ''
  const text = `Olá${first ? `, ${first}` : ''}! Aqui é da ${me.value?.tenant.name ?? o.branch.name}, sobre o seu pedido nº ${o.number}.`
  return `https://wa.me/55${digits}?text=${encodeURIComponent(text)}`
})
const acting = ref(false)
const eta = ref(40)
const cancelling = ref<OrderStatus | null>(null)
const reason = ref<CancellationReason>('out_of_stock')
const note = ref('')
const manage = can('orders.manage')
const canRefund = can('payments.refund')
const refunding = ref(false)
const refundAmount = ref('')
const refundReason = ref('')
const refundConfirm = ref(false)

const PAYMENT_LABEL: Record<string, string> = {
  approved: 'pago pelo site', partially_refunded: 'reembolsado em parte', refunded: 'reembolsado',
  in_review: 'em análise no Mercado Pago', charged_back: 'contestado pelo cliente', mismatch: 'divergente — confira no Mercado Pago',
}
const paidOnline = (o: MerchantOrder) => ['approved', 'partially_refunded'].includes(o.online_payment?.status ?? '')
const refundable = computed(() => toCents(selected.value?.online_payment?.refundable ?? '0'))
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

/** Pedido novo sem aceite há 5 min ou mais (mesmo limite da faixa ao vivo). */
function isLate(o: MerchantOrder): boolean {
  return o.status === 'pending_acceptance' && (now.value - new Date(o.placed_at).getTime()) / 60_000 >= LATE_AFTER_MINUTES
}

function minutesAgo(iso: string): string {
  const m = Math.max(0, Math.round((now.value - new Date(iso).getTime()) / 60000))
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
  refundConfirm.value = false
  refundAmount.value = ''
  refundReason.value = ''
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

async function refund() {
  if (!selected.value) return
  if (!refundConfirm.value) {
    refundConfirm.value = true
    return
  }
  refunding.value = true
  try {
    const result = await paymentsApi.refund(selected.value.id, {
      amount: refundAmount.value.trim() || null,
      reason: refundReason.value.trim() || null,
    })
    selected.value = result.order
    refundConfirm.value = false
    refundAmount.value = ''
    notify(`Reembolso de ${formatMoney(result.refund.amount)} enviado ao Mercado Pago`)
    await load(true)
  } catch (error) {
    notify(describeError(error).message, 'error')
  } finally {
    refunding.value = false
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

// Recarrega quando o pulso muda (pedido novo, status, pagamento).
watch(() => pulse.value?.version, (v, old) => {
  if (old !== undefined && v !== old) void load(true)
})

onMounted(() => {
  void load()
  // Rede de segurança caso o pulso falhe; o normal é o watch acima.
  timer = setInterval(() => load(true), 60_000)
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
            :class="o.status !== 'pending_acceptance' ? 'border-line' : isLate(o) ? 'border-danger ring-2 ring-danger/20' : 'border-brand-600 ring-2 ring-brand-100'" @click="open(o)"
          >
            <span class="flex items-baseline justify-between gap-2">
              <span class="flex items-center gap-2">
                <span class="font-display text-lg font-bold">#{{ o.number }}</span>
                <span v-if="paidOnline(o)" class="rounded-full bg-success/10 px-2 py-0.5 text-xs font-semibold whitespace-nowrap text-success">Pago online</span>
              </span>
              <span class="text-sm" :class="isLate(o) ? 'font-semibold text-danger' : 'text-muted'">{{ minutesAgo(o.placed_at) }}</span>
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
          <div class="flex items-center gap-1">
            <RouterLink :to="`/pedidos/${selected.id}/imprimir`" class="rounded-lg px-3 py-2 text-sm font-semibold text-brand-700 hover:bg-canvas">Imprimir</RouterLink>
            <button type="button" class="-mr-2 grid size-10 place-items-center rounded-full text-2xl text-muted hover:bg-canvas" aria-label="Fechar" @click="selected = null">×</button>
          </div>
        </div>

        <dl class="mt-4 grid gap-3 text-sm">
          <div>
            <dt class="font-semibold">Cliente</dt>
            <dd>{{ selected.customer.name }} · <a :href="`tel:${selected.customer.phone.replace(/\D/g, '')}`" class="text-brand-700 underline">{{ selected.customer.phone }}</a></dd>
            <dd v-if="customerWhatsApp" class="mt-2">
              <a :href="customerWhatsApp" target="_blank" rel="noopener" class="inline-flex min-h-10 items-center rounded-(--radius-control) bg-[#1f7a4d] px-3 text-sm font-semibold text-white hover:bg-[#17603c]">Chamar no WhatsApp</a>
            </dd>
          </div>
          <div v-if="selected.fulfillment_type === 'delivery' && selected.address">
            <dt class="font-semibold">Entrega ({{ selected.delivery_zone }})</dt>
            <dd>{{ selected.address.street }}, {{ selected.address.number }}<template v-if="selected.address.complement"> — {{ selected.address.complement }}</template><br>{{ selected.address.neighborhood }}<template v-if="selected.address.reference"> · Ref.: {{ selected.address.reference }}</template></dd>
          </div>
          <div v-else><dt class="font-semibold">Retirada no local</dt></div>
          <div>
            <dt class="font-semibold">Pagamento</dt>
            <dd>
              {{ selected.payment_method_label }}<template v-if="selected.change_for"> · troco para {{ formatMoney(selected.change_for) }}</template>
              <template v-if="selected.online_payment?.status"> · {{ PAYMENT_LABEL[selected.online_payment.status] ?? selected.online_payment.status }}</template>
              <span v-if="selected.online_payment?.provider_reference" class="block text-muted">Mercado Pago nº {{ selected.online_payment.provider_reference }}</span>
              <span v-for="r in selected.online_payment?.refunds ?? []" :key="r.id" class="block text-muted">
                Reembolso {{ formatMoney(r.amount) }} · {{ r.status === 'failed' ? 'falhou' : r.status === 'rejected' ? 'recusado' : r.status === 'approved' ? 'feito' : 'em processamento' }} · {{ formatTime(r.created_at, tz) }}
              </span>
            </dd>
          </div>
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
          <div v-for="t in selected.totals ?? []" :key="t.code" class="flex justify-between" :class="t.code === 'total' && 'font-bold'"><dt>{{ t.label }}</dt><dd class="tabular-nums">{{ formatTotal(t) }}</dd></div>
        </dl>

        <section v-if="canRefund && refundable > 0" class="mt-6 grid gap-3 rounded-(--radius-card) border border-line p-4" aria-label="Reembolso">
          <h3 class="font-semibold">Reembolso pelo Mercado Pago</h3>
          <p class="text-sm text-muted">Disponível: {{ formatMoney(selected.online_payment!.refundable) }}. Deixe o valor em branco para devolver tudo.</p>
          <div class="grid grid-cols-[8rem_1fr] gap-2">
            <input v-model="refundAmount" inputmode="decimal" placeholder="Valor (R$)" aria-label="Valor do reembolso" class="min-h-11 rounded-(--radius-control) border border-line px-3">
            <input v-model="refundReason" maxlength="200" placeholder="Motivo (opcional)" aria-label="Motivo do reembolso" class="min-h-11 rounded-(--radius-control) border border-line px-3">
          </div>
          <div class="flex gap-2">
            <SButton :variant="refundConfirm ? 'primary' : 'secondary'" :loading="refunding" @click="refund">
              {{ refundConfirm ? `Confirmar reembolso de ${refundAmount.trim() ? `R$ ${refundAmount.trim()}` : formatMoney(selected.online_payment!.refundable)}` : 'Reembolsar' }}
            </SButton>
            <SButton v-if="refundConfirm" variant="ghost" @click="refundConfirm = false">Voltar</SButton>
          </div>
        </section>
      </div>

      <footer v-if="manage && selected.next_statuses.length" class="grid gap-3 border-t border-line bg-surface px-5 py-4">
        <template v-if="cancelling">
          <label class="grid gap-1.5 text-sm font-medium">Motivo
            <select v-model="reason" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3">
              <option v-for="r in CANCELLATION_REASONS" :key="r.value" :value="r.value">{{ r.label }}</option>
            </select>
          </label>
          <input v-model="note" maxlength="300" placeholder="Detalhe (opcional)" aria-label="Detalhe do motivo" class="min-h-11 rounded-(--radius-control) border border-line px-3">
          <p v-if="paidOnline(selected)" class="text-sm text-muted">O valor pago pelo site ({{ formatMoney(selected.online_payment!.refundable) }}) será devolvido automaticamente ao cliente.</p>
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
