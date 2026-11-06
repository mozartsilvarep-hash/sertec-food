<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import {
  centsToString, describeError, floorApi, formatMoney, merchantApi, posApi, tabsApi, toCents,
  type Branch, type FloorRequest, type FloorTable, type PosCatalog, type PosLine, type ProductSummary, type TabDetail, type TabItem,
} from '@sertec/api-client'
import { SButton, SPageState, SSheet, SStepper } from '@sertec/ui'
import PosProductSheet, { type PosPick } from '../components/PosProductSheet.vue'
import { notice, notify } from '../notify'
import { can, me } from '../session'

/**
 * Garçom no celular (D103): pedidos do QR para confirmar, mesas do salão,
 * lançar itens e mandar para a cozinha. Fechar a conta fica no caixa.
 */
const branches = ref<Branch[]>([])
const branchId = ref('')
const tables = ref<FloorTable[]>([])
const requests = ref<FloorRequest[]>([])
const catalog = ref<PosCatalog | null>(null)
const state = ref<'loading' | 'ready' | 'error'>('loading')

async function refresh() {
  const data = await floorApi.get(branchId.value || null)
  // Pedido novo do QR: vibra o celular do garçom.
  if (data.requests.some((r) => !requests.value.find((x) => x.id === r.id)) && state.value === 'ready') navigator.vibrate?.([120, 60, 120])
  tables.value = data.tables
  requests.value = data.requests
}
async function load() {
  state.value = 'loading'
  try {
    branches.value = await merchantApi.branches().catch(() => [])
    branchId.value ||= branches.value[0]?.id ?? ''
    const [cat] = await Promise.all([posApi.catalog(branchId.value || null), refresh()])
    catalog.value = cat
    state.value = 'ready'
  } catch {
    state.value = 'error'
  }
}
let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  void load()
  timer = setInterval(() => { if (!document.hidden) void refresh().catch(() => null) }, 8000)
})
onBeforeUnmount(() => clearInterval(timer))
watch(branchId, (id, old) => { if (old && id !== old) void load() })

const since = (iso: string) => {
  const m = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000))
  return m < 1 ? 'agora' : m < 60 ? `${m} min` : `${Math.floor(m / 60)}h${String(m % 60).padStart(2, '0')}`
}

/* ---------- Pedidos do QR ---------- */
const deciding = ref<string | null>(null)
const rejecting = reactive({ id: '', reason: '' })
async function accept(r: FloorRequest, kitchen: boolean) {
  deciding.value = r.id
  try {
    await floorApi.accept(r.id, kitchen)
    notify(r.type === 'order' ? (kitchen ? `${r.table}: confirmado e enviado à cozinha` : `${r.table}: confirmado`) : `${r.table}: atendido`)
    await refresh()
  } catch (e) {
    notify(describeError(e).message, 'error')
    await refresh().catch(() => null)
  } finally {
    deciding.value = null
  }
}
async function reject() {
  deciding.value = rejecting.id
  try {
    await floorApi.reject(rejecting.id, rejecting.reason.trim() || null)
    rejecting.id = ''
    await refresh()
  } catch (e) {
    notify(describeError(e).message, 'error')
  } finally {
    deciding.value = null
  }
}
const KIND: Record<FloorRequest['type'], string> = { order: 'Pedido', waiter: 'Chamou o garçom', bill: 'Pediu a conta' }

/* ---------- Mesa aberta ---------- */
const sheet = reactive({ open: false, label: '', tab: null as TabDetail | null, people: 1, busy: false, error: '' })
const activeItems = computed(() => sheet.tab?.items.filter((i) => !i.voided_at) ?? [])
async function openTable(t: FloorTable) {
  Object.assign(sheet, { open: true, label: t.label, tab: null, people: 1, error: '' })
  draft.value = []
  picking.value = false
  if (t.tab) {
    try {
      sheet.tab = await tabsApi.get(t.tab.id)
    } catch (e) {
      sheet.error = describeError(e).message
    }
  }
}
async function run(fn: () => Promise<void>) {
  if (sheet.busy) return
  sheet.busy = true
  sheet.error = ''
  try {
    await fn()
    void refresh()
  } catch (e) {
    const d = describeError(e)
    sheet.error = d.fields ? Object.values(d.fields)[0]?.[0] ?? d.message : d.message
  } finally {
    sheet.busy = false
  }
}
const openTab = () => run(async () => { sheet.tab = await tabsApi.open({ branch: branchId.value || null, label: sheet.label, people: sheet.people }) })
const setPeople = (n: number) => run(async () => { if (sheet.tab) sheet.tab = await tabsApi.update(sheet.tab.id, { people: n }) })
const toggleBill = () => run(async () => {
  if (!sheet.tab) return
  const r = await floorApi.bill(sheet.tab.id, !!sheet.tab.bill_requested_at)
  sheet.tab = { ...sheet.tab, bill_requested_at: r.bill_requested_at }
  notify(r.bill_requested_at ? 'Conta pedida ao caixa' : 'Pedido de conta cancelado')
})
const voiding = reactive({ id: '', reason: '' })
function askVoid(i: TabItem) {
  if (!i.sent_at) void run(async () => { sheet.tab = await tabsApi.voidItem(sheet.tab!.id, i.id) })
  else Object.assign(voiding, { id: i.id, reason: '' })
}
const confirmVoid = () => run(async () => {
  sheet.tab = await tabsApi.voidItem(sheet.tab!.id, voiding.id, voiding.reason)
  voiding.id = ''
})

/* ---------- Lançar itens ---------- */
interface Draft extends PosPick { key: string }
const draft = ref<Draft[]>([])
const picking = ref(false)
const search = ref('')
const openProduct = ref<string | null>(null)
const fold = (s: string) => s.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase().trim()
const products = computed(() => {
  const seen = new Map<string, ProductSummary>()
  for (const c of catalog.value?.menus.flatMap((m) => m.categories) ?? []) for (const p of c.products) if (!seen.has(p.id)) seen.set(p.id, p)
  const term = fold(search.value)
  const all = [...seen.values()]
  if (!term) {
    const fav = new Set([...all.filter((p) => catalog.value?.products[p.id]?.favorite).map((p) => p.id), ...(catalog.value?.top ?? [])])
    return fav.size ? all.filter((p) => fav.has(p.id)) : all.slice(0, 30)
  }
  return all.filter((p) => fold(p.name).includes(term) || fold(catalog.value?.products[p.id]?.code ?? '') === term).slice(0, 40)
})
function tap(p: ProductSummary) {
  if (!p.available) return
  const quick = catalog.value?.products[p.id]?.quick_variant
  if (!quick) {
    openProduct.value = p.id
    return
  }
  const same = draft.value.find((d) => d.product_id === p.id && !d.options.length && !d.notes)
  if (same) same.quantity++
  else draft.value.push({ key: `${p.id}:${Date.now()}`, product_id: p.id, variant_id: quick, options: [], quantity: 1, notes: null, name: p.name, detail: '', unitCents: toCents(p.promo_price_from ?? p.price_from ?? '0') })
}
function addPick(pick: PosPick) {
  draft.value.push({ key: `${pick.product_id}:${Date.now()}`, ...pick })
  openProduct.value = null
}
function setQty(d: Draft, q: number) {
  if (q <= 0) draft.value = draft.value.filter((x) => x !== d)
  else d.quantity = q
}
const draftCents = computed(() => draft.value.reduce((s, d) => s + d.unitCents * d.quantity, 0))
const launch = (kitchen: boolean) => run(async () => {
  if (!sheet.tab || !draft.value.length) return
  const lines: PosLine[] = draft.value.map((d) => ({ product_id: d.product_id, variant_id: d.variant_id, options: d.options, quantity: d.quantity, notes: d.notes }))
  sheet.tab = await tabsApi.addItems(sheet.tab.id, lines)
  draft.value = []
  picking.value = false
  if (kitchen) {
    const r = await tabsApi.send(sheet.tab.id)
    sheet.tab = r.tab
    notify(r.batch ? `Enviado à cozinha (envio ${r.batch})` : 'Lançado')
  } else {
    notify('Lançado na conta')
  }
})
const sendPending = () => run(async () => {
  if (!sheet.tab) return
  const r = await tabsApi.send(sheet.tab.id)
  sheet.tab = r.tab
  notify(r.batch ? `Enviado à cozinha (envio ${r.batch})` : 'Nada novo para a cozinha')
})
</script>

<template>
  <div class="min-h-dvh bg-canvas pb-10">
    <header class="sticky top-0 z-10 flex items-center gap-3 border-b border-line bg-surface px-4 py-3">
      <RouterLink v-if="can('cash.operate') || can('orders.view')" to="/" class="text-sm font-semibold text-muted" aria-label="Voltar ao painel">‹</RouterLink>
      <h1 class="text-lg font-bold">Salão</h1>
      <select v-if="branches.length > 1" v-model="branchId" aria-label="Unidade" class="ml-auto rounded-lg border border-line bg-surface px-2 py-1.5 text-sm">
        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
      </select>
      <span class="truncate text-sm text-muted" :class="branches.length <= 1 && 'ml-auto'">{{ me?.user.name }}</span>
    </header>

    <SPageState v-if="state === 'loading'" state="loading" />
    <SPageState v-else-if="state === 'error'" state="error" @retry="load" />
    <main v-else class="mx-auto grid max-w-3xl gap-6 px-4 pt-4">
      <section v-if="requests.length" aria-label="Pedidos do QR aguardando" class="grid gap-3">
        <h2 class="text-sm font-bold tracking-wide text-danger uppercase">Aguardando você ({{ requests.length }})</h2>
        <article v-for="r in requests" :key="r.id" class="grid gap-2 rounded-(--radius-card) border-2 border-danger/40 bg-surface p-4">
          <div class="flex items-baseline justify-between gap-2">
            <p class="text-lg font-bold">{{ r.table }}</p>
            <p class="text-xs text-muted">{{ KIND[r.type] }} · {{ since(r.created_at) }}</p>
          </div>
          <p v-if="r.summary && r.type === 'order'" class="text-sm">{{ r.summary }}</p>
          <p v-if="r.customer_name || r.notes" class="text-sm text-muted"><template v-if="r.customer_name">{{ r.customer_name }}</template><template v-if="r.notes"> · Obs.: {{ r.notes }}</template></p>
          <div v-if="rejecting.id !== r.id" class="grid gap-2" :class="r.type === 'order' ? 'grid-cols-2' : 'grid-cols-1'">
            <template v-if="r.type === 'order'">
              <SButton class="col-span-2" :loading="deciding === r.id" @click="accept(r, true)">Confirmar e mandar à cozinha</SButton>
              <button type="button" class="min-h-11 rounded-(--radius-control) border border-line font-semibold" :disabled="deciding === r.id" @click="accept(r, false)">Só confirmar</button>
              <button type="button" class="min-h-11 rounded-(--radius-control) border border-line font-semibold text-danger" :disabled="deciding === r.id" @click="Object.assign(rejecting, { id: r.id, reason: '' })">Recusar</button>
            </template>
            <SButton v-else :loading="deciding === r.id" @click="accept(r, false)">Atendido</SButton>
          </div>
          <form v-else class="flex gap-2" @submit.prevent="reject">
            <input v-model="rejecting.reason" maxlength="140" placeholder="Motivo (o cliente vê)" aria-label="Motivo da recusa" class="min-h-11 min-w-0 flex-1 rounded-lg border border-line px-3">
            <button type="submit" class="min-h-11 rounded-lg bg-danger px-3 font-semibold text-white" :disabled="deciding === r.id">Recusar</button>
            <button type="button" class="min-h-11 px-2 text-sm text-muted" @click="rejecting.id = ''">Voltar</button>
          </form>
        </article>
      </section>

      <section aria-label="Mesas">
        <h2 class="mb-2 text-sm font-bold tracking-wide text-muted uppercase">Mesas</h2>
        <SPageState v-if="!tables.length" state="empty" title="Nenhuma mesa cadastrada" message="O gerente cadastra as mesas em Mesas e comandas › Mesas e QR Codes." />
        <ul v-else class="grid grid-cols-[repeat(auto-fill,minmax(8.5rem,1fr))] gap-2">
          <li v-for="t in tables" :key="t.id ?? t.label">
            <button
              type="button" class="flex h-full min-h-24 w-full flex-col justify-between rounded-(--radius-card) border p-3 text-left"
              :class="t.pending ? 'border-danger bg-danger-soft' : t.tab ? 'border-brand-600 bg-brand-50' : 'border-line bg-surface'" @click="openTable(t)"
            >
              <span class="flex items-start justify-between gap-1">
                <span class="min-w-0 truncate font-bold">{{ t.label }}</span>
                <span v-if="t.tab?.bill_requested_at" class="shrink-0 rounded-full bg-ink px-1.5 text-[10px] font-semibold text-white">CONTA</span>
              </span>
              <span v-if="t.tab" class="text-sm tabular-nums">{{ formatMoney(t.tab.subtotal) }}<span class="block text-xs text-muted">{{ t.tab.people }} pess. · {{ since(t.tab.opened_at) }}<template v-if="t.tab.pending_kitchen"> · {{ t.tab.pending_kitchen }} novo(s)</template></span></span>
              <span v-else class="text-sm text-muted">Livre</span>
            </button>
          </li>
        </ul>
      </section>
    </main>

    <SSheet :open="sheet.open" :label="sheet.label" @close="sheet.open = false">
      <div class="grid gap-4 overflow-y-auto p-5">
        <div class="flex items-start justify-between gap-2">
          <h2 class="text-xl font-bold">{{ sheet.label }}</h2>
          <div v-if="sheet.tab" class="grid justify-items-end gap-1 text-sm"><span class="text-muted" aria-hidden="true">Pessoas: {{ sheet.tab.people }}</span><SStepper :model-value="sheet.tab.people" label="pessoa" :min="1" :max="99" @update:model-value="setPeople" /></div>
        </div>

        <template v-if="!sheet.tab">
          <p class="text-sm text-muted">Mesa livre. Abra a conta para lançar.</p>
          <div class="flex items-center gap-3 text-sm"><span aria-hidden="true">Pessoas</span><SStepper v-model="sheet.people" label="pessoa" :min="1" :max="99" /></div>
          <SButton :loading="sheet.busy" @click="openTab">Abrir conta</SButton>
        </template>

        <template v-else-if="!picking">
          <ul v-if="sheet.tab.items.length" class="divide-y divide-line rounded-lg border border-line text-sm">
            <li v-for="i in sheet.tab.items" :key="i.id" class="grid gap-1 px-3 py-2" :class="i.voided_at && 'bg-canvas'">
              <div class="flex items-start gap-2">
                <p class="min-w-0 flex-1" :class="i.voided_at && 'text-muted line-through'"><strong>{{ i.quantity }}×</strong> {{ i.name }}<span v-if="i.detail" class="block text-xs text-muted">{{ i.detail }}</span><span v-if="i.notes" class="block text-xs font-medium text-warning">Obs.: {{ i.notes }}</span></p>
                <span class="shrink-0 tabular-nums" :class="i.voided_at && 'text-muted line-through'">{{ formatMoney(i.line_total) }}</span>
              </div>
              <div v-if="!i.voided_at" class="flex items-center gap-2 text-xs">
                <span :class="i.sent_at ? 'text-muted' : 'font-semibold text-warning'">{{ i.sent_at ? `Cozinha · envio ${i.kitchen_batch}` : 'Não enviado' }}</span>
                <button type="button" class="ml-auto min-h-8 px-2 text-danger" :disabled="sheet.busy" @click="askVoid(i)">{{ i.sent_at ? 'Cancelar' : 'Remover' }}</button>
              </div>
              <form v-if="voiding.id === i.id" class="flex gap-2" @submit.prevent="confirmVoid">
                <input v-model="voiding.reason" required maxlength="140" placeholder="Motivo" aria-label="Motivo do cancelamento" class="min-h-10 min-w-0 flex-1 rounded-lg border border-line px-3">
                <button type="submit" class="min-h-10 rounded-lg bg-danger px-3 font-semibold text-white" :disabled="sheet.busy">Cancelar item</button>
              </form>
            </li>
          </ul>
          <p v-else class="rounded-lg bg-canvas p-3 text-sm text-muted">Conta aberta, sem itens.</p>
          <p class="flex justify-between text-lg font-bold"><span>Subtotal</span><span class="tabular-nums">{{ formatMoney(sheet.tab.subtotal) }}</span></p>
          <div class="grid grid-cols-2 gap-2">
            <SButton class="col-span-2" @click="picking = true">Adicionar itens</SButton>
            <button type="button" class="min-h-11 rounded-(--radius-control) border border-line font-semibold disabled:opacity-50" :disabled="sheet.busy || !activeItems.some((i) => !i.sent_at)" @click="sendPending">Mandar à cozinha</button>
            <button type="button" class="min-h-11 rounded-(--radius-control) border font-semibold" :class="sheet.tab.bill_requested_at ? 'border-ink bg-ink text-white' : 'border-line'" :disabled="sheet.busy || !activeItems.length" @click="toggleBill">{{ sheet.tab.bill_requested_at ? 'Conta pedida ✓' : 'Pedir a conta' }}</button>
          </div>
        </template>

        <template v-else>
          <input v-model="search" type="search" placeholder="Buscar produto ou código" aria-label="Buscar produto" class="min-h-12 rounded-(--radius-control) border border-line px-4">
          <ul class="grid max-h-[40dvh] grid-cols-2 gap-2 overflow-y-auto" aria-label="Produtos">
            <li v-for="p in products" :key="p.id">
              <button type="button" class="flex h-full min-h-16 w-full flex-col justify-between rounded-(--radius-control) border border-line p-2.5 text-left text-sm disabled:opacity-45" :disabled="!p.available" @click="tap(p)">
                <span class="font-semibold leading-tight">{{ p.name }}</span>
                <span class="tabular-nums text-muted">{{ p.available ? formatMoney(p.promo_price_from ?? p.price_from ?? '0') : 'Esgotado' }}</span>
              </button>
            </li>
          </ul>
          <ul v-if="draft.length" class="grid gap-2 border-t border-line pt-3" aria-label="Itens para lançar">
            <li v-for="d in draft" :key="d.key" class="flex items-center gap-2 text-sm">
              <span class="min-w-0 flex-1"><span class="block font-semibold">{{ d.name }}</span><span v-if="d.detail" class="block text-xs text-muted">{{ d.detail }}</span></span>
              <SStepper :model-value="d.quantity" :label="d.name" :max="99" @update:model-value="setQty(d, $event)" />
            </li>
          </ul>
          <div class="grid grid-cols-2 gap-2">
            <SButton class="col-span-2" :loading="sheet.busy" :disabled="!draft.length" @click="launch(true)">Lançar e mandar à cozinha<template v-if="draftCents"> · {{ formatMoney(centsToString(draftCents)) }}</template></SButton>
            <button type="button" class="min-h-11 rounded-(--radius-control) border border-line font-semibold disabled:opacity-50" :disabled="sheet.busy || !draft.length" @click="launch(false)">Só lançar</button>
            <button type="button" class="min-h-11 rounded-(--radius-control) border border-line font-semibold" @click="picking = false; draft = []">Voltar</button>
          </div>
        </template>

        <p v-if="sheet.error" role="alert" class="text-sm text-danger">{{ sheet.error }}</p>
      </div>
    </SSheet>

    <PosProductSheet :product-id="openProduct" :branch-id="branchId || null" @close="openProduct = null" @add="addPick" />

    <!-- Tela fora do layout do painel: o aviso curto mora aqui. -->
    <div
      v-if="notice" role="status"
      class="fixed inset-x-4 bottom-4 z-50 mx-auto max-w-sm rounded-xl px-4 py-3 text-sm font-medium shadow-lg"
      :class="notice.tone === 'ok' ? 'bg-ink text-white' : 'bg-danger text-white'"
    >
      {{ notice.text }}
    </div>
  </div>
</template>
