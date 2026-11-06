<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  ApiError, describeError, formatMoney, formatTime, merchantApi, tabsApi, toCents, centsToString,
  type Branch, type TabDetail, type TabItem, type TabSummary,
} from '@sertec/api-client'
import { SButton, SPageState, SSheet, SStepper } from '@sertec/ui'
import BranchPicker from '../components/BranchPicker.vue'
import DiningTablesPanel from '../components/DiningTablesPanel.vue'
import { notify } from '../notify'
import { can, me } from '../session'

/**
 * Mesas e comandas: contas abertas da unidade. Itens entram pelo PDV
 * (“Lançar na mesa”), vão para a cozinha em lotes impressos e a conta
 * fecha no PDV com taxa de serviço e divisão.
 */
const router = useRouter()
const branches = ref<Branch[]>([])
const branchId = ref('')
const tabs = ref<TabSummary[]>([])
const state = ref<'loading' | 'ready' | 'error' | 'offline'>('loading')
const tz = computed(() => me.value?.tenant.timezone ?? 'America/Sao_Paulo')

async function refresh() {
  tabs.value = await tabsApi.list(branchId.value || null)
}
async function load() {
  state.value = 'loading'
  try {
    branches.value = await merchantApi.branches()
    branchId.value ||= branches.value[0]?.id ?? ''
    await refresh()
    state.value = 'ready'
  } catch (e) {
    state.value = e instanceof ApiError && e.code === 'network_error' ? 'offline' : 'error'
  }
}
let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  void load()
  timer = setInterval(() => { if (!document.hidden && !detail.open) void refresh().catch(() => null) }, 20000)
})
onBeforeUnmount(() => clearInterval(timer))
watch(branchId, (id, old) => { if (old && id !== old) void refresh() })

/* ---------- Abrir ---------- */
const form = reactive({ label: '', people: 1, customer: '', saving: false, error: '' })
const nextFree = computed(() => {
  const used = new Set(tabs.value.map((t) => t.label.toLowerCase()))
  const out: string[] = []
  for (let n = 1; out.length < 6 && n < 200; n++) if (!used.has(`mesa ${n}`)) out.push(`Mesa ${n}`)
  return out
})
async function openTab(label = form.label) {
  if (!label.trim()) return
  form.saving = true
  form.error = ''
  try {
    const tab = await tabsApi.open({ branch: branchId.value || null, label: label.trim(), people: form.people, customer_name: form.customer.trim() || null })
    Object.assign(form, { label: '', people: 1, customer: '' })
    await refresh()
    showDetail(tab)
    notify(`${tab.label} aberta`)
  } catch (e) {
    form.error = describeError(e).message
  } finally {
    form.saving = false
  }
}

/* ---------- Conta ---------- */
const detail = reactive({ open: false, tab: null as TabDetail | null, busy: false, error: '' })
const voiding = reactive({ id: '', reason: '' })
const cancelling = reactive({ open: false, reason: '' })
const activeItems = computed(() => detail.tab?.items.filter((i) => !i.voided_at) ?? [])
const perPerson = computed(() => (detail.tab && detail.tab.people > 1 ? centsToString(Math.round(toCents(detail.tab.subtotal) / detail.tab.people)) : null))

function showDetail(t: TabDetail) {
  Object.assign(detail, { open: true, tab: t, error: '' })
  Object.assign(voiding, { id: '', reason: '' })
  cancelling.open = false
}
async function openDetail(t: TabSummary) {
  try {
    showDetail(await tabsApi.get(t.id))
  } catch (e) {
    notify(describeError(e).message, 'error')
  }
}
async function run(fn: () => Promise<TabDetail | void>, ok?: string) {
  if (!detail.tab || detail.busy) return
  detail.busy = true
  detail.error = ''
  try {
    const t = await fn()
    if (t) detail.tab = t
    if (ok) notify(ok)
    void refresh()
  } catch (e) {
    const d = describeError(e)
    detail.error = d.fields ? Object.values(d.fields)[0]?.[0] ?? d.message : d.message
  } finally {
    detail.busy = false
  }
}
const saveInfo = (people: number) => run(() => tabsApi.update(detail.tab!.id, { people }))
function askVoid(i: TabItem) {
  if (!i.sent_at) {
    void run(() => tabsApi.voidItem(detail.tab!.id, i.id), `${i.name} removido`)
    return
  }
  Object.assign(voiding, { id: i.id, reason: '' })
}
const confirmVoid = () => run(async () => {
  const t = await tabsApi.voidItem(detail.tab!.id, voiding.id, voiding.reason)
  voiding.id = ''
  return t
}, 'Item cancelado')
async function sendKitchen() {
  if (!detail.tab) return
  await run(async () => {
    const r = await tabsApi.send(detail.tab!.id)
    if (r.batch) void router.push({ name: 'tab-kitchen', params: { id: r.tab.id }, query: { lote: String(r.batch) } })
    else notify('Nada novo para a cozinha')
    return r.tab
  })
}
const cancelTab = () => run(async () => {
  await tabsApi.cancel(detail.tab!.id, cancelling.reason)
  detail.open = false
}, 'Conta cancelada')
const goPos = (close = false) => detail.tab && router.push({ name: 'pos', query: { branch: branchId.value || undefined, mesa: detail.tab.id, ...(close ? { fechar: '1' } : {}) } })
const since = (iso: string) => {
  const m = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000))
  return m < 60 ? `${m} min` : `${Math.floor(m / 60)}h${String(m % 60).padStart(2, '0')}`
}
</script>

<template>
  <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
      <h1 class="font-display text-2xl font-bold">Mesas e comandas</h1>
      <p class="mt-1 max-w-[65ch] text-sm text-muted">Abra a conta, lance os itens pelo PDV, mande para a cozinha e feche com taxa de serviço e divisão.</p>
    </div>
    <div class="flex flex-wrap gap-2">
      <RouterLink :to="{ name: 'waiter' }" class="min-h-11 rounded-lg border border-line bg-surface px-4 py-2.5 text-sm font-semibold hover:bg-canvas">Modo garçom</RouterLink>
      <RouterLink :to="{ name: 'pos', query: { branch: branchId || undefined } }" class="min-h-11 rounded-lg border border-line bg-surface px-4 py-2.5 text-sm font-semibold hover:bg-canvas">Abrir o PDV</RouterLink>
    </div>
  </div>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'offline'" state="offline" @retry="load" />
  <SPageState v-else-if="state === 'error'" state="error" @retry="load" />
  <template v-else>
    <BranchPicker v-model="branchId" :branches="branches" />

    <form class="mb-6 grid gap-3 rounded-(--radius-card) border border-line bg-surface p-4" aria-label="Abrir mesa ou comanda" @submit.prevent="openTab()">
      <div class="grid grid-cols-[minmax(0,1fr)] gap-3 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)_auto] sm:items-end">
        <label class="grid min-w-0 gap-1 text-sm font-medium">Mesa ou comanda
          <input v-model="form.label" maxlength="40" required placeholder="Ex.: Mesa 4, Comanda 12, Balcão Ana" class="min-h-11 min-w-0 rounded-lg border border-line px-3">
        </label>
        <div class="grid gap-1 text-sm font-medium"><span aria-hidden="true">Pessoas</span><SStepper v-model="form.people" label="pessoa" :min="1" :max="99" /></div>
        <label class="grid min-w-0 gap-1 text-sm font-medium">Nome (opcional)
          <input v-model="form.customer" maxlength="120" class="min-h-11 min-w-0 rounded-lg border border-line px-3">
        </label>
        <SButton type="submit" :loading="form.saving">Abrir</SButton>
      </div>
      <div class="flex flex-wrap items-center gap-2 text-sm">
        <span class="text-muted">Livres:</span>
        <button v-for="l in nextFree" :key="l" type="button" class="min-h-9 rounded-full border border-line px-3 hover:bg-canvas" :disabled="form.saving" @click="openTab(l)">{{ l }}</button>
      </div>
      <p v-if="form.error" role="alert" class="text-sm text-danger">{{ form.error }}</p>
    </form>

    <SPageState v-if="!tabs.length" state="empty" title="Nenhuma conta aberta" message="Abra uma mesa acima ou use “Lançar na mesa” no PDV." />
    <ul v-else class="grid grid-cols-[repeat(auto-fill,minmax(10.5rem,1fr))] gap-3">
      <li v-for="t in tabs" :key="t.id">
        <button type="button" class="flex h-full w-full flex-col gap-1 rounded-(--radius-card) border border-line bg-surface p-4 text-left hover:border-ink focus-visible:border-ink" @click="openDetail(t)">
          <span class="flex items-start justify-between gap-2">
            <span class="min-w-0 truncate text-lg font-bold">{{ t.label }}</span>
            <span v-if="t.bill_requested_at" class="shrink-0 rounded-full bg-ink px-2 py-0.5 text-xs font-semibold text-white">Conta pedida</span>
            <span v-else-if="t.pending_kitchen" class="shrink-0 rounded-full bg-warning/15 px-2 py-0.5 text-xs font-semibold text-warning" :title="`${t.pending_kitchen} item(ns) ainda não foram para a cozinha`">{{ t.pending_kitchen }} novo{{ t.pending_kitchen > 1 ? 's' : '' }}</span>
          </span>
          <span v-if="t.customer_name" class="truncate text-sm text-muted">{{ t.customer_name }}</span>
          <span class="text-xl font-bold tabular-nums">{{ formatMoney(t.subtotal) }}</span>
          <span class="text-xs text-muted">{{ t.items_count }} {{ t.items_count === 1 ? 'item' : 'itens' }} · {{ t.people }} {{ t.people === 1 ? 'pessoa' : 'pessoas' }} · {{ since(t.opened_at) }}</span>
        </button>
      </li>
    </ul>
    <DiningTablesPanel v-if="can('cash.manage')" :branch-id="branchId" />
  </template>

  <SSheet :open="detail.open" :label="detail.tab?.label ?? 'Conta'" @close="detail.open = false">
    <div v-if="detail.tab" class="grid gap-4 overflow-y-auto p-5">
      <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0">
          <h2 class="truncate text-xl font-bold">{{ detail.tab.label }}</h2>
          <p class="text-sm text-muted">Aberta às {{ formatTime(detail.tab.opened_at, tz) }}<template v-if="detail.tab.customer_name"> · {{ detail.tab.customer_name }}</template></p>
        </div>
        <div class="grid justify-items-end gap-1 text-sm"><span class="text-muted" aria-hidden="true">Pessoas: {{ detail.tab.people }}</span><SStepper :model-value="detail.tab.people" label="pessoa" :min="1" :max="99" @update:model-value="saveInfo" /></div>
      </div>

      <p v-if="!detail.tab.items.length" class="rounded-lg bg-canvas p-4 text-sm text-muted">Sem itens ainda. Toque em “Lançar itens”.</p>
      <ul v-else class="divide-y divide-line rounded-lg border border-line text-sm">
        <li v-for="i in detail.tab.items" :key="i.id" class="grid gap-1 px-3 py-2" :class="i.voided_at && 'bg-canvas'">
          <div class="flex items-start gap-2">
            <p class="min-w-0 flex-1" :class="i.voided_at && 'text-muted line-through'">
              <strong>{{ i.quantity }}×</strong> {{ i.name }}
              <span v-if="i.detail" class="block text-xs text-muted">{{ i.detail }}</span>
              <span v-if="i.notes" class="block text-xs font-medium text-warning">Obs.: {{ i.notes }}</span>
            </p>
            <span class="shrink-0 tabular-nums" :class="i.voided_at && 'text-muted line-through'">{{ formatMoney(i.line_total) }}</span>
          </div>
          <div class="flex flex-wrap items-center gap-2 text-xs text-muted">
            <span v-if="i.voided_at" class="text-danger">Cancelado: {{ i.void_reason }}</span>
            <span v-else-if="i.sent_at">Cozinha · lote {{ i.kitchen_batch }}</span>
            <span v-else class="font-semibold text-warning">Não enviado</span>
            <span v-if="i.added_by">· {{ i.added_by }}</span>
            <button v-if="!i.voided_at" type="button" class="ml-auto min-h-8 rounded px-2 text-danger hover:bg-danger/10" :disabled="detail.busy" @click="askVoid(i)">{{ i.sent_at ? 'Cancelar item' : 'Remover' }}</button>
          </div>
          <form v-if="voiding.id === i.id" class="flex gap-2" @submit.prevent="confirmVoid">
            <input v-model="voiding.reason" required maxlength="140" placeholder="Motivo (já foi para a cozinha)" aria-label="Motivo do cancelamento" class="min-h-10 min-w-0 flex-1 rounded-lg border border-line px-3">
            <button type="submit" class="min-h-10 rounded-lg bg-danger px-3 font-semibold text-white" :disabled="detail.busy">Cancelar</button>
          </form>
        </li>
      </ul>

      <dl class="grid gap-1 text-sm">
        <div class="flex justify-between text-lg font-bold"><dt>Subtotal</dt><dd class="tabular-nums">{{ formatMoney(detail.tab.subtotal) }}</dd></div>
        <div v-if="perPerson" class="flex justify-between text-muted"><dt>Por pessoa ({{ detail.tab.people }})</dt><dd class="tabular-nums">{{ formatMoney(perPerson) }}</dd></div>
        <p class="text-xs text-muted">Taxa de serviço e descontos entram no fechamento.</p>
      </dl>

      <p v-if="detail.error" role="alert" class="text-sm text-danger">{{ detail.error }}</p>

      <div class="grid grid-cols-2 gap-2">
        <button type="button" class="min-h-11 rounded-lg border border-line font-semibold hover:bg-canvas" @click="goPos(false)">Lançar itens</button>
        <button type="button" class="min-h-11 rounded-lg border border-line font-semibold hover:bg-canvas disabled:opacity-50" :disabled="detail.busy || !detail.tab.pending_kitchen" @click="sendKitchen">Enviar à cozinha<template v-if="detail.tab.pending_kitchen"> ({{ detail.tab.pending_kitchen }})</template></button>
        <SButton class="col-span-2" :disabled="!activeItems.length" @click="goPos(true)">Fechar conta</SButton>
      </div>
      <RouterLink v-if="detail.tab.kitchen_batches" :to="{ name: 'tab-kitchen', params: { id: detail.tab.id } }" class="text-center text-sm text-brand-700 underline">Reimprimir último pedido da cozinha</RouterLink>

      <div class="border-t border-line pt-3">
        <button v-if="!cancelling.open" type="button" class="text-sm text-danger" @click="cancelling.open = true">Cancelar a conta inteira</button>
        <form v-else class="grid gap-2" @submit.prevent="cancelTab">
          <label class="grid gap-1 text-sm font-medium">Motivo
            <input v-model="cancelling.reason" required minlength="3" maxlength="140" class="min-h-11 rounded-lg border border-line px-3">
          </label>
          <p v-if="!can('cash.manage') && activeItems.some((i) => i.sent_at)" class="text-xs text-muted">Itens já foram para a cozinha: só um gerente pode cancelar.</p>
          <div class="flex gap-2">
            <button type="button" class="min-h-10 rounded-lg border border-line px-3 text-sm" @click="cancelling.open = false">Voltar</button>
            <button type="submit" class="min-h-10 rounded-lg bg-danger px-3 text-sm font-semibold text-white" :disabled="detail.busy">Cancelar conta</button>
          </div>
        </form>
      </div>
    </div>
  </SSheet>
</template>
