<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ApiError, cashApi, centsToString, describeError, formatMoney, loyaltyRewardCents, merchantApi, posApi, publicCatalogApi, toCents,
  type Branch, type CashSession, type LoyaltyRule, type PosCatalog, type PosCustomer, type PosMethod, type ProductSummary, type StorefrontInfo,
} from '@sertec/api-client'
import { SButton, SPageState, SSheet, SStepper } from '@sertec/ui'
import PosProductSheet, { type PosPick } from '../components/PosProductSheet.vue'
import { me } from '../session'

/**
 * PDV: venda rápida no balcão. Teclado: F2 busca (código ou nome), Enter
 * lança, F4 cobra, Esc fecha. O servidor recalcula tudo na hora de vender.
 */
const route = useRoute()
const router = useRouter()

const branches = ref<Branch[]>([])
const branchId = ref(typeof route.query.branch === 'string' ? route.query.branch : '')
const catalog = ref<PosCatalog | null>(null)
const session = ref<CashSession | null>(null)
const loyaltyRule = ref<LoyaltyRule | null>(null)
const state = ref<'loading' | 'ready' | 'error' | 'closed'>('loading')

async function load() {
  state.value = 'loading'
  try {
    branches.value = await merchantApi.branches()
    branchId.value ||= branches.value[0]?.id ?? ''
    const [cat, sess, store] = await Promise.all([
      posApi.catalog(branchId.value || null),
      cashApi.current(branchId.value || null),
      publicCatalogApi.storefront().catch(() => null as StorefrontInfo | null),
    ])
    catalog.value = cat
    session.value = sess
    loyaltyRule.value = store?.loyalty ?? null
    state.value = sess ? 'ready' : 'closed'
    await nextTick()
    searchEl.value?.focus()
  } catch {
    state.value = 'error'
  }
}

/* ---------- Catálogo ---------- */
const search = ref('')
const searchEl = ref<HTMLInputElement | null>(null)
const category = ref<string>('fav')
const fold = (s: string) => s.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase().trim()

const allProducts = computed(() => {
  const seen = new Map<string, ProductSummary>()
  for (const c of catalog.value?.menus.flatMap((m) => m.categories) ?? []) for (const p of c.products) if (!seen.has(p.id)) seen.set(p.id, p)
  return [...seen.values()]
})
const categories = computed(() => catalog.value?.menus.flatMap((m) => m.categories).filter((c) => c.products.length) ?? [])
const meta = (id: string) => catalog.value?.products[id]
const favorites = computed(() => {
  const ids = new Set([...allProducts.value.filter((p) => meta(p.id)?.favorite).map((p) => p.id), ...(catalog.value?.top ?? [])])
  return allProducts.value.filter((p) => ids.has(p.id))
})
const shown = computed(() => {
  const term = fold(search.value)
  if (term) {
    return allProducts.value.filter((p) => fold(p.name).includes(term) || fold(meta(p.id)?.code ?? '') === term || fold(meta(p.id)?.code ?? '').startsWith(term))
  }
  if (category.value === 'fav') return favorites.value.length ? favorites.value : allProducts.value
  return categories.value.find((c) => c.id === category.value)?.products ?? []
})

/* ---------- Venda ---------- */
interface Line {
  key: string
  product_id?: string
  variant_id?: string
  options?: { option: string; quantity: number }[]
  description?: string
  name: string
  detail: string
  unitCents: number
  quantity: number
  notes: string | null
}
const lines = ref<Line[]>([])
const openProduct = ref<string | null>(null)
const custom = reactive({ open: false, name: '', price: '' })
const customer = reactive({ phone: '', name: '', info: null as PosCustomer | null, looking: false })
const adjust = reactive({ discountType: 'amount' as 'amount' | 'percent', discount: '', service: false, servicePercent: '10', useLoyalty: false })
const flash = ref<string | null>(null)

function money(v: string): string {
  const clean = v.replace(/\s|R\$/g, '').replace(/\.(?=\d{3}(\D|$))/g, '').replace(',', '.')
  const n = Number(clean)
  return clean !== '' && Number.isFinite(n) && n >= 0 ? n.toFixed(2) : '0.00'
}
const cents = (v: string) => toCents(money(v))

function tap(p: ProductSummary) {
  if (!p.available) return
  const quick = meta(p.id)?.quick_variant
  if (!quick) {
    openProduct.value = p.id
    return
  }
  const found = lines.value.find((l) => l.product_id === p.id && !l.options?.length && !l.notes)
  if (found) found.quantity++
  else lines.value.push({ key: `${p.id}:${Date.now()}`, product_id: p.id, variant_id: quick, options: [], name: p.name, detail: '', unitCents: toCents(p.promo_price_from ?? p.price_from ?? '0'), quantity: 1, notes: null })
  pulse(p.name)
}

function addPick(pick: PosPick) {
  lines.value.push({ key: `${pick.product_id}:${Date.now()}`, ...pick })
  openProduct.value = null
  pulse(pick.name)
  void nextTick(() => searchEl.value?.focus())
}

function addCustom() {
  const unit = cents(custom.price)
  if (!custom.name.trim() || unit <= 0) return
  lines.value.push({ key: `c:${Date.now()}`, description: custom.name.trim(), name: custom.name.trim(), detail: 'Avulso', unitCents: unit, quantity: 1, notes: null })
  Object.assign(custom, { open: false, name: '', price: '' })
}

function setQty(l: Line, q: number) {
  if (q <= 0) lines.value = lines.value.filter((x) => x !== l)
  else l.quantity = q
}

let flashTimer: ReturnType<typeof setTimeout> | undefined
function pulse(name: string) {
  flash.value = `${name} adicionado`
  clearTimeout(flashTimer)
  flashTimer = setTimeout(() => (flash.value = null), 1500)
}

function onSearchEnter() {
  const term = fold(search.value)
  if (!term) return
  const byCode = allProducts.value.find((p) => fold(meta(p.id)?.code ?? '') === term)
  const pick = byCode ?? (shown.value.length === 1 ? shown.value[0] : null)
  if (pick) {
    tap(pick)
    search.value = ''
  }
}

async function lookupCustomer() {
  const digits = customer.phone.replace(/\D/g, '')
  customer.info = null
  if (digits.length < 10) return
  customer.looking = true
  try {
    customer.info = await posApi.customer(digits)
    if (customer.info.name && !customer.name) customer.name = customer.info.name
  } catch {
    customer.info = null
  } finally {
    customer.looking = false
  }
}

const subtotal = computed(() => lines.value.reduce((s, l) => s + l.unitCents * l.quantity, 0))
const discountCents = computed(() => {
  if (!adjust.discount) return 0
  const d = adjust.discountType === 'percent' ? Math.round((subtotal.value * Math.min(100, Number(money(adjust.discount)))) / 100) : cents(adjust.discount)
  return Math.min(d, subtotal.value)
})
const loyaltyCents = computed(() => (adjust.useLoyalty && loyaltyRule.value ? loyaltyRewardCents(loyaltyRule.value, subtotal.value - discountCents.value) : 0))
const serviceCents = computed(() => (adjust.service ? Math.round(((subtotal.value - discountCents.value - loyaltyCents.value) * Number(money(adjust.servicePercent))) / 100) : 0))
/** Estimativa na tela; o valor oficial vem do servidor (promoções incluídas). */
const total = computed(() => Math.max(0, subtotal.value - discountCents.value - loyaltyCents.value + serviceCents.value))

/* ---------- Cobrança ---------- */
const paying = ref(false)
const payments = ref<{ method: PosMethod; amount: string }[]>([])
const cashReceived = ref('')
const kitchen = ref(false)
const sending = ref(false)
const error = ref<string | null>(null)
const done = ref<{ id: string; number: number; total: string; change: string | null } | null>(null)
let saleKey = ''

const paid = computed(() => payments.value.reduce((s, p) => s + cents(p.amount), 0))
const remaining = computed(() => total.value - paid.value)
const cashPart = computed(() => payments.value.filter((p) => p.method === 'cash').reduce((s, p) => s + cents(p.amount), 0))
const change = computed(() => (cashReceived.value ? cents(cashReceived.value) - cashPart.value : null))

function startPay() {
  if (!lines.value.length) return
  error.value = null
  payments.value = []
  cashReceived.value = ''
  saleKey = crypto.randomUUID()
  paying.value = true
}

function addPayment(method: PosMethod) {
  const left = Math.max(0, remaining.value)
  const existing = payments.value.find((p) => p.method === method)
  if (existing) existing.amount = centsToString(cents(existing.amount) + left).replace('.', ',')
  else payments.value.push({ method, amount: centsToString(left).replace('.', ',') })
}

async function confirmSale() {
  if (sending.value) return
  sending.value = true
  error.value = null
  try {
    const result = await posApi.sale({
      branch: branchId.value || null,
      items: lines.value.map((l) => (l.product_id
        ? { product_id: l.product_id, variant_id: l.variant_id, options: l.options ?? [], quantity: l.quantity, notes: l.notes }
        : { description: l.description, unit_price: centsToString(l.unitCents), quantity: l.quantity })),
      customer: customer.phone.trim() ? { phone: customer.phone, name: customer.name.trim() || null } : (customer.name.trim() ? { name: customer.name.trim() } : null),
      use_loyalty: adjust.useLoyalty,
      discount: adjust.discount ? { type: adjust.discountType, value: money(adjust.discount) } : null,
      service_percent: adjust.service ? Number(money(adjust.servicePercent)) : null,
      payments: payments.value.map((p) => ({ method: p.method, amount: money(p.amount) })),
      cash_received: cashReceived.value ? money(cashReceived.value) : null,
      send_to_kitchen: kitchen.value,
    }, saleKey)
    paying.value = false
    done.value = { id: result.order_id, number: result.order_number, total: result.total, change: result.change }
    session.value = await cashApi.current(branchId.value || null)
  } catch (e) {
    const d = describeError(e)
    // Total do servidor diferente (ex.: promoção): ajusta e pede confirmação.
    if (e instanceof ApiError && e.code === 'sale_payment_mismatch' && typeof e.details?.total === 'string') {
      error.value = `${d.message} Confira os valores.`
    } else {
      error.value = d.fields ? Object.values(d.fields)[0]?.[0] ?? d.message : d.message
    }
  } finally {
    sending.value = false
  }
}

function newSale() {
  lines.value = []
  done.value = null
  kitchen.value = false
  Object.assign(customer, { phone: '', name: '', info: null })
  Object.assign(adjust, { discount: '', service: false, useLoyalty: false })
  void nextTick(() => searchEl.value?.focus())
}

/* ---------- Teclado e tela cheia ---------- */
function onKey(e: KeyboardEvent) {
  if (e.key === 'F2') {
    e.preventDefault()
    searchEl.value?.focus()
  } else if (e.key === 'F4') {
    e.preventDefault()
    if (done.value) newSale()
    else if (!paying.value) startPay()
  } else if (e.key === 'Enter' && done.value) {
    e.preventDefault()
    newSale()
  }
}
const isFull = ref(false)
async function toggleFull() {
  try {
    if (document.fullscreenElement) await document.exitFullscreen()
    else await document.documentElement.requestFullscreen()
  } catch {
    /* navegador sem tela cheia */
  }
}
const onFull = () => (isFull.value = !!document.fullscreenElement)

watch(branchId, (id, old) => {
  if (old && id !== old) {
    void router.replace({ query: { branch: id } })
    lines.value = []
    void load()
  }
})
onMounted(() => {
  void load()
  window.addEventListener('keydown', onKey)
  document.addEventListener('fullscreenchange', onFull)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  document.removeEventListener('fullscreenchange', onFull)
})

const METHOD_KEYS: Record<PosMethod, string> = { cash: 'Dinheiro', pix: 'Pix', debit: 'Débito', credit: 'Crédito', other: 'Outro' }
</script>

<template>
  <div class="flex min-h-dvh flex-col bg-canvas lg:h-dvh">
    <header class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-line bg-surface px-4 py-2.5">
      <RouterLink to="/caixa" class="rounded-lg px-2 py-1.5 text-sm font-semibold text-muted hover:bg-canvas hover:text-ink">‹ Caixa</RouterLink>
      <h1 class="text-lg font-bold">PDV</h1>
      <label v-if="branches.length > 1" class="text-sm"><span class="sr-only">Unidade</span>
        <select v-model="branchId" class="rounded-lg border border-line bg-surface px-2 py-1.5 font-semibold">
          <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
        </select>
      </label>
      <span class="text-sm text-muted">{{ me?.user.name }}</span>
      <span v-if="session" class="ml-auto hidden text-sm sm:inline">Gaveta: <strong class="tabular-nums">{{ formatMoney(session.expected_amount) }}</strong></span>
      <span class="hidden text-xs text-muted lg:inline">F2 buscar · F4 cobrar · Esc fechar</span>
      <button type="button" class="rounded-lg border border-line px-3 py-1.5 text-sm font-semibold hover:bg-canvas" :class="!session && 'ml-auto'" @click="toggleFull">{{ isFull ? 'Sair da tela cheia' : 'Tela cheia' }}</button>
    </header>

    <SPageState v-if="state === 'loading'" state="loading" />
    <SPageState v-else-if="state === 'error'" state="error" @retry="load" />
    <div v-else-if="state === 'closed'" class="grid flex-1 place-items-center p-6">
      <div class="max-w-sm text-center">
        <p class="text-lg font-semibold">O caixa desta unidade está fechado</p>
        <p class="mt-1 text-sm text-muted">Abra o caixa para começar a vender.</p>
        <RouterLink to="/caixa" class="mt-4 inline-flex min-h-11 items-center rounded-(--radius-control) bg-brand-600 px-5 font-semibold text-brand-contrast">Ir para o caixa</RouterLink>
      </div>
    </div>

    <div v-else class="grid min-h-0 flex-1 grid-cols-[minmax(0,1fr)] lg:grid-cols-[minmax(0,1fr)_24rem]">
      <!-- Produtos -->
      <section class="flex min-h-0 flex-col gap-3 p-3 sm:p-4" aria-label="Produtos">
        <div class="flex gap-2">
          <input
            ref="searchEl" v-model="search" type="search" aria-label="Buscar por código ou nome" placeholder="Código ou nome (F2)"
            class="min-h-12 min-w-0 flex-1 rounded-(--radius-control) border border-line bg-surface px-4 text-base" @keydown.enter.prevent="onSearchEnter"
          >
          <SButton variant="secondary" @click="custom.open = !custom.open">Avulso</SButton>
        </div>
        <form v-if="custom.open" class="flex flex-wrap items-end gap-2 rounded-(--radius-control) border border-line bg-surface p-3" aria-label="Item avulso" @submit.prevent="addCustom">
          <label class="grid min-w-40 flex-1 gap-1 text-sm font-medium">Descrição<input v-model="custom.name" class="min-h-11 rounded-lg border border-line px-3" placeholder="Ex.: Bolo de aniversário"></label>
          <label class="grid w-28 gap-1 text-sm font-medium">Valor (R$)<input v-model="custom.price" inputmode="decimal" class="min-h-11 rounded-lg border border-line px-3"></label>
          <SButton type="submit">Lançar</SButton>
        </form>
        <div v-if="!search" class="relative flex gap-1.5 overflow-x-auto [scrollbar-width:none]" role="tablist" aria-label="Categorias">
          <button type="button" role="tab" :aria-selected="category === 'fav'" class="shrink-0 rounded-full px-4 py-2 text-sm font-semibold whitespace-nowrap" :class="category === 'fav' ? 'bg-ink text-white' : 'bg-surface text-muted'" @click="category = 'fav'">★ Favoritos</button>
          <button v-for="c in categories" :key="c.id" type="button" role="tab" :aria-selected="category === c.id" class="shrink-0 rounded-full px-4 py-2 text-sm font-semibold whitespace-nowrap" :class="category === c.id ? 'bg-ink text-white' : 'bg-surface text-muted'" @click="category = c.id">{{ c.name }}</button>
        </div>
        <ul class="grid min-h-0 flex-1 auto-rows-min grid-cols-2 gap-2 overflow-y-auto sm:grid-cols-3 xl:grid-cols-4" aria-label="Lista de produtos">
          <li v-for="p in shown" :key="p.id">
            <button
              type="button" class="flex h-full min-h-20 w-full flex-col justify-between rounded-(--radius-control) border border-line bg-surface p-3 text-left hover:border-brand-600 active:scale-[0.98] disabled:opacity-45"
              :disabled="!p.available" @click="tap(p)"
            >
              <span class="font-semibold leading-tight">{{ p.name }}</span>
              <span class="mt-2 flex items-end justify-between gap-2 text-sm">
                <span class="tabular-nums">{{ p.available ? formatMoney(p.promo_price_from ?? p.price_from ?? '0') : 'Esgotado' }}</span>
                <span v-if="meta(p.id)?.code" class="rounded bg-canvas px-1.5 font-mono text-xs text-muted">{{ meta(p.id)?.code }}</span>
              </span>
            </button>
          </li>
          <li v-if="!shown.length" class="col-span-full py-8 text-center text-sm text-muted">Nada encontrado. Use "Avulso" para lançar um item fora do cardápio.</li>
        </ul>
      </section>

      <!-- Venda -->
      <aside class="flex min-h-0 flex-col border-t border-line bg-surface lg:border-t-0 lg:border-l" aria-label="Venda atual">
        <div class="min-h-0 flex-1 overflow-y-auto p-4">
          <p v-if="flash" role="status" class="mb-2 rounded-lg bg-brand-50 px-3 py-1.5 text-sm font-medium text-brand-700">{{ flash }}</p>
          <p v-if="!lines.length" class="py-6 text-center text-sm text-muted">Toque nos produtos ou digite o código.</p>
          <ul class="grid gap-2" aria-label="Itens da venda">
            <li v-for="l in lines" :key="l.key" class="flex items-start gap-2 text-sm">
              <span class="min-w-0 flex-1">
                <span class="block font-semibold">{{ l.name }}</span>
                <span v-if="l.detail" class="block text-xs text-muted">{{ l.detail }}</span>
                <span v-if="l.notes" class="block text-xs font-semibold">Obs.: {{ l.notes }}</span>
                <span class="tabular-nums text-muted">{{ formatMoney(centsToString(l.unitCents * l.quantity)) }}</span>
              </span>
              <SStepper :model-value="l.quantity" :label="l.name" :max="999" @update:model-value="setQty(l, $event)" />
            </li>
          </ul>

          <details class="mt-4 border-t border-line pt-3">
            <summary class="cursor-pointer text-sm font-semibold">Cliente {{ customer.info?.name || customer.name ? `· ${customer.name || customer.info?.name}` : '(opcional)' }}</summary>
            <div class="mt-2 grid gap-2">
              <input v-model="customer.phone" type="tel" aria-label="Celular do cliente" placeholder="Celular (fidelidade)" class="min-h-11 rounded-lg border border-line px-3" @blur="lookupCustomer">
              <input v-model="customer.name" aria-label="Nome do cliente" placeholder="Nome" class="min-h-11 rounded-lg border border-line px-3">
              <p v-if="customer.info?.loyalty" class="text-sm">Cartão: {{ customer.info.loyalty.stamps }} de {{ customer.info.loyalty.required }} selos<template v-if="customer.info.loyalty.rewards"> · <strong>{{ customer.info.loyalty.rewards }} prêmio(s)</strong></template></p>
              <label v-if="customer.info?.loyalty?.rewards" class="flex items-center gap-2 text-sm font-semibold"><input v-model="adjust.useLoyalty" type="checkbox" class="size-4 accent-brand-600"> Usar prêmio ({{ customer.info.loyalty.reward_label }})</label>
            </div>
          </details>

          <details class="mt-3 border-t border-line pt-3">
            <summary class="cursor-pointer text-sm font-semibold">Desconto e taxa de serviço</summary>
            <div class="mt-2 grid gap-2">
              <div class="flex gap-2">
                <select v-model="adjust.discountType" aria-label="Tipo de desconto" class="rounded-lg border border-line px-2">
                  <option value="amount">R$</option><option value="percent">%</option>
                </select>
                <input v-model="adjust.discount" inputmode="decimal" aria-label="Desconto" placeholder="Desconto" class="min-h-11 min-w-0 flex-1 rounded-lg border border-line px-3">
              </div>
              <label class="flex items-center gap-2 text-sm"><input v-model="adjust.service" type="checkbox" class="size-4 accent-brand-600"> Taxa de serviço
                <input v-model="adjust.servicePercent" inputmode="decimal" aria-label="Percentual da taxa" class="w-16 rounded-lg border border-line px-2 py-1" :disabled="!adjust.service">%</label>
            </div>
          </details>
        </div>

        <footer class="border-t border-line p-4">
          <dl class="mb-3 grid gap-0.5 text-sm">
            <div class="flex justify-between"><dt class="text-muted">Subtotal</dt><dd class="tabular-nums">{{ formatMoney(centsToString(subtotal)) }}</dd></div>
            <div v-if="discountCents" class="flex justify-between text-success"><dt>Desconto</dt><dd class="tabular-nums">− {{ formatMoney(centsToString(discountCents)) }}</dd></div>
            <div v-if="loyaltyCents" class="flex justify-between text-success"><dt>Cartão fidelidade</dt><dd class="tabular-nums">− {{ formatMoney(centsToString(loyaltyCents)) }}</dd></div>
            <div v-if="serviceCents" class="flex justify-between"><dt class="text-muted">Taxa de serviço</dt><dd class="tabular-nums">{{ formatMoney(centsToString(serviceCents)) }}</dd></div>
            <div class="mt-1 flex justify-between text-2xl font-bold"><dt>Total</dt><dd class="tabular-nums">{{ formatMoney(centsToString(total)) }}</dd></div>
          </dl>
          <SButton block :disabled="!lines.length" @click="startPay">Cobrar (F4)</SButton>
          <button v-if="lines.length" type="button" class="mt-2 w-full text-sm text-muted underline" @click="lines = []">Limpar venda</button>
        </footer>
      </aside>
    </div>

    <PosProductSheet :product-id="openProduct" :branch-id="branchId || null" @close="openProduct = null" @add="addPick" />

    <!-- Cobrança -->
    <SSheet :open="paying" label="Cobrar" @close="paying = false">
      <form class="grid gap-4 overflow-y-auto p-5" aria-label="Cobrança" @submit.prevent="confirmSale">
        <div class="flex items-baseline justify-between">
          <h2 class="text-xl font-bold">Cobrar</h2>
          <p class="text-3xl font-bold tabular-nums">{{ formatMoney(centsToString(total)) }}</p>
        </div>
        <div class="grid grid-cols-3 gap-2 sm:grid-cols-5">
          <button v-for="m in catalog?.methods ?? []" :key="m.method" type="button" class="min-h-14 rounded-(--radius-control) border border-line text-sm font-semibold hover:border-brand-600 hover:bg-brand-50" @click="addPayment(m.method)">{{ METHOD_KEYS[m.method] }}</button>
        </div>
        <ul v-if="payments.length" class="grid gap-2" aria-label="Formas de pagamento">
          <li v-for="(p, i) in payments" :key="i" class="flex items-center gap-2">
            <span class="w-24 text-sm font-semibold">{{ METHOD_KEYS[p.method] }}</span>
            <input v-model="p.amount" inputmode="decimal" :aria-label="`Valor em ${METHOD_KEYS[p.method]}`" class="min-h-11 min-w-0 flex-1 rounded-lg border border-line px-3 text-right tabular-nums">
            <button type="button" class="px-2 text-xl text-muted" :aria-label="`Remover ${METHOD_KEYS[p.method]}`" @click="payments.splice(i, 1)">×</button>
          </li>
        </ul>
        <p v-else class="text-sm text-muted">Escolha a forma de pagamento. Para dividir, toque em outra forma e ajuste os valores.</p>
        <p class="text-sm font-semibold" :class="remaining === 0 ? 'text-success' : 'text-danger'">
          {{ remaining === 0 ? 'Valores conferidos' : remaining > 0 ? `Falta ${formatMoney(centsToString(remaining))}` : `Passou ${formatMoney(centsToString(-remaining))}` }}
        </p>
        <div v-if="cashPart > 0" class="grid gap-1">
          <label class="grid gap-1 text-sm font-medium">Dinheiro recebido (R$)<input v-model="cashReceived" inputmode="decimal" class="min-h-11 rounded-lg border border-line px-3"></label>
          <p v-if="change !== null" class="text-lg font-bold" :class="change < 0 ? 'text-danger' : ''">{{ change < 0 ? 'Valor menor que a parte em dinheiro' : `Troco: ${formatMoney(centsToString(change))}` }}</p>
        </div>
        <label class="flex items-center gap-2 text-sm font-semibold"><input v-model="kitchen" type="checkbox" class="size-5 accent-brand-600"> Mandar para a cozinha (cliente aguarda)</label>
        <p v-if="error" role="alert" class="rounded-lg bg-danger-soft p-3 text-sm text-danger">{{ error }}</p>
        <SButton type="submit" block :loading="sending" :disabled="remaining !== 0 || (change !== null && change < 0)">Confirmar venda</SButton>
      </form>
    </SSheet>

    <!-- Venda concluída -->
    <SSheet :open="done !== null" label="Venda concluída" @close="newSale">
      <div v-if="done" class="grid gap-4 p-6 text-center" role="status">
        <p class="text-sm text-muted">Venda nº {{ done.number }}</p>
        <p class="text-3xl font-bold tabular-nums">{{ formatMoney(done.total) }}</p>
        <p v-if="done.change && Number(done.change) > 0" class="rounded-(--radius-control) bg-brand-50 p-4 text-2xl font-bold text-brand-700">Troco: {{ formatMoney(done.change) }}</p>
        <div class="grid gap-2 sm:grid-cols-2">
          <RouterLink :to="`/pedidos/${done.id}/recibo`" class="inline-flex min-h-11 items-center justify-center rounded-(--radius-control) border border-line font-semibold hover:bg-canvas">Imprimir recibo</RouterLink>
          <SButton @click="newSale">Nova venda (Enter)</SButton>
        </div>
      </div>
    </SSheet>
  </div>
</template>
