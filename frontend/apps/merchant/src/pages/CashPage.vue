<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import {
  ApiError, CASH_MANUAL, CASH_METHODS, cashApi, catalogApi, centsToString, describeError, formatMoney, formatTime, merchantApi, toCents,
  type Branch, type CashEntry, type CashMethod, type CashSession, type CashSummary, type Product,
} from '@sertec/api-client'
import { SAlert, SButton, SPageState, SStepper, STextField } from '@sertec/ui'
import BranchPicker from '../components/BranchPicker.vue'
import { notify } from '../notify'
import { can, me } from '../session'

/**
 * Caixa da unidade (mini PDV): abrir/fechar com conferência, venda de
 * balcão, entradas e saídas, e relatório do período (gerência).
 */
const manage = can('cash.manage')
const tz = computed(() => me.value?.tenant.timezone ?? 'America/Sao_Paulo')
const branches = ref<Branch[]>([])
const branchId = ref('')
const session = ref<CashSession | null>(null)
const entries = ref<CashEntry[]>([])
const state = ref<'loading' | 'ready' | 'error' | 'unavailable'>('loading')
const tab = ref<'caixa' | 'relatorio'>('caixa')
const panel = ref<'venda' | 'lancamento' | 'fechar' | null>(null)
const busy = ref(false)
const errors = ref<Record<string, string[]>>({})
const message = ref<string | null>(null)

// Aceita "12,50", "12.50" e "1.234,56"; sempre envia "1234.56".
function money(v: string): string {
  const clean = v.replace(/\s|R\$/g, '').replace(/\.(?=\d{3}(\D|$))/g, '').replace(',', '.')
  const n = Number(clean)
  return clean !== '' && Number.isFinite(n) && n >= 0 ? n.toFixed(2) : '0.00'
}
const cents = (v: string) => toCents(money(v))
const err = (k: string) => errors.value[k]?.[0] ?? null

async function loadSession() {
  session.value = await cashApi.current(branchId.value || null)
  entries.value = session.value ? await cashApi.entries({ branch: branchId.value || null, session: manage ? session.value.id : null }) : []
}

async function load() {
  state.value = 'loading'
  try {
    branches.value = await merchantApi.branches()
    branchId.value ||= branches.value[0]?.id ?? ''
    await loadSession()
    state.value = 'ready'
  } catch (e) {
    state.value = e instanceof ApiError && e.code === 'feature_unavailable' ? 'unavailable' : 'error'
  }
}

/** Executa a ação; a mensagem de sucesso pode vir da própria ação (ex.: troco). */
async function run(action: () => Promise<string | void>, ok: string) {
  busy.value = true
  errors.value = {}
  message.value = null
  try {
    notify((await action()) || ok)
  } catch (e) {
    const d = describeError(e)
    errors.value = d.fields ?? {}
    message.value = d.fields ? 'Confira os campos destacados.' : d.message
  } finally {
    busy.value = false
  }
}

/* ---------- Abrir / fechar ---------- */
const opening = ref('')
const closing = reactive({ counted: '', notes: '' })
const closingDiff = computed(() => (session.value ? cents(closing.counted) - toCents(session.value.expected_amount) : 0))

function openCash() {
  return run(async () => {
    session.value = await cashApi.open(branchId.value || null, money(opening.value))
    entries.value = []
    opening.value = ''
  }, 'Caixa aberto')
}

function closeCash() {
  if (!session.value) return
  const id = session.value.id
  return run(async () => {
    const closed = await cashApi.close(id, money(closing.counted), closing.notes.trim() || null)
    const diff = Number(closed.difference ?? 0)
    panel.value = null
    Object.assign(closing, { counted: '', notes: '' })
    lastClosed.value = closed
    await loadSession()
    return diff === 0 ? 'Caixa fechado e conferido' : `Caixa fechado com ${diff > 0 ? 'sobra' : 'falta'} de ${formatMoney(Math.abs(diff))}`
  }, 'Caixa fechado')
}
const lastClosed = ref<CashSession | null>(null)

/* ---------- Venda de balcão ---------- */
const products = ref<Product[]>([])
const search = ref('')
interface Line { key: string; product_id?: string; variant_id?: string; name: string; unit: number; quantity: number }
const cart = ref<Line[]>([])
const sale = reactive({ method: 'cash' as CashMethod, discount: '', received: '', customName: '', customPrice: '' })
const fold = (s: string) => s.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase()
const options = computed(() => products.value
  .filter((p) => p.active && p.kind !== 'combo')
  .flatMap((p) => p.variants.filter((v) => v.active).map((v) => ({
    key: `${p.id}:${v.id}`, product_id: p.id, variant_id: v.id,
    name: p.variants.filter((x) => x.active).length > 1 ? `${p.name} (${v.name})` : p.name, unit: toCents(v.price),
  })))
  .filter((o) => o.unit > 0 && (!search.value || fold(o.name).includes(fold(search.value))))
  .slice(0, 40))
const subtotal = computed(() => cart.value.reduce((s, l) => s + l.unit * l.quantity, 0))
const total = computed(() => Math.max(0, subtotal.value - cents(sale.discount)))
const change = computed(() => (sale.method === 'cash' && sale.received ? cents(sale.received) - total.value : null))

async function startSale() {
  panel.value = 'venda'
  if (products.value.length === 0) {
    const list: Product[] = []
    let cursor: string | null = null
    do {
      const page = await catalogApi.products({ cursor, per_page: 100 })
      list.push(...page.data)
      cursor = page.meta.next_cursor
    } while (cursor && list.length < 1000)
    products.value = list.sort((a, b) => a.name.localeCompare(b.name, 'pt-BR'))
  }
}

function addLine(o: { key: string; product_id?: string; variant_id?: string; name: string; unit: number }) {
  const found = cart.value.find((l) => l.key === o.key)
  if (found) found.quantity++
  else cart.value.push({ ...o, quantity: 1 })
}

function addCustom() {
  const unit = cents(sale.customPrice)
  if (!sale.customName.trim() || unit <= 0) return
  addLine({ key: `custom:${Date.now()}`, name: sale.customName.trim(), unit })
  sale.customName = ''
  sale.customPrice = ''
}

function setQty(line: Line, q: number) {
  if (q <= 0) cart.value = cart.value.filter((l) => l !== line)
  else line.quantity = q
}

function finishSale() {
  return run(async () => {
    const entry = await cashApi.sale({
      branch: branchId.value || null,
      method: sale.method,
      discount: sale.discount ? money(sale.discount) : null,
      received: sale.method === 'cash' && sale.received ? money(sale.received) : null,
      items: cart.value.map((l) => (l.product_id
        ? { product_id: l.product_id, variant_id: l.variant_id, quantity: l.quantity }
        : { description: l.name, unit_price: centsToString(l.unit), quantity: l.quantity })),
    })
    cart.value = []
    Object.assign(sale, { discount: '', received: '' })
    await loadSession()
    if (entry.change && Number(entry.change) > 0) return `Venda registrada. Troco: ${formatMoney(entry.change)}`
  }, 'Venda registrada')
}

/* ---------- Entradas e saídas ---------- */
const manual = reactive({ category: 'withdrawal' as (typeof CASH_MANUAL)[number]['value'], method: 'cash' as CashMethod, amount: '', description: '' })
const manualDef = computed(() => CASH_MANUAL.find((c) => c.value === manual.category))

function saveEntry() {
  return run(async () => {
    await cashApi.entry({ branch: branchId.value || null, category: manual.category, method: manualDef.value?.drawerOnly ? 'cash' : manual.method, amount: money(manual.amount), description: manual.description.trim() || null })
    Object.assign(manual, { amount: '', description: '' })
    panel.value = null
    await loadSession()
  }, 'Lançamento registrado')
}

/* ---------- Estorno ---------- */
const voiding = ref<string | null>(null)
const voidReason = ref('')
function voidEntry(e: CashEntry) {
  return run(async () => {
    await cashApi.void(e.id, voidReason.value.trim())
    voiding.value = null
    voidReason.value = ''
    await loadSession()
  }, 'Lançamento estornado')
}

/* ---------- Relatório (gerência) ---------- */
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: tz.value })
const period = reactive({ from: today(), to: today() })
const summary = ref<CashSummary | null>(null)
const history = ref<CashSession[]>([])
async function loadReport() {
  summary.value = await cashApi.summary({ branch: branchId.value || null, from: period.from, to: period.to })
  history.value = await cashApi.sessions(branchId.value || null)
}
const maxDay = computed(() => Math.max(1, ...(summary.value?.by_day ?? []).map((d) => Math.max(Number(d.in), Number(d.out)))))

watch(tab, (t) => { if (t === 'relatorio') void loadReport() })
watch(branchId, (id, old) => { if (old) void (tab.value === 'relatorio' ? loadReport() : loadSession()) })
onMounted(load)
</script>

<template>
  <h1 class="mb-2 font-display text-2xl font-bold">Caixa</h1>
  <p class="mb-6 max-w-[65ch] text-sm text-muted">Abra o caixa no começo do dia, registre as vendas de balcão, as entradas e as saídas, e feche conferindo o dinheiro da gaveta. Os pedidos do cardápio entram sozinhos quando são concluídos.</p>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'unavailable'" state="empty" title="Caixa não incluído no seu plano" message="Fale com a SerTec para liberar o caixa." />
  <SPageState v-else-if="state === 'error'" state="error" @retry="load" />
  <template v-else>
    <BranchPicker v-model="branchId" :branches="branches" />
    <div v-if="manage" class="mb-6 flex gap-2" role="tablist">
      <button v-for="t in [{ v: 'caixa', l: 'Caixa do dia' }, { v: 'relatorio', l: 'Relatório' }]" :key="t.v" role="tab" type="button" :aria-selected="tab === t.v" class="rounded-full px-4 py-2 text-sm font-semibold" :class="tab === t.v ? 'bg-ink text-white' : 'bg-surface text-muted hover:text-ink'" @click="tab = t.v as typeof tab">{{ t.l }}</button>
    </div>
    <SAlert v-if="message" class="mb-4">{{ message }}</SAlert>

    <!-- ===== Caixa do dia ===== -->
    <template v-if="tab === 'caixa'">
      <section v-if="!session" class="grid max-w-md gap-4 rounded-(--radius-card) border border-line bg-surface p-5" aria-label="Abrir caixa">
        <p v-if="lastClosed" class="rounded-(--radius-control) bg-brand-50 p-3 text-sm">
          Último fechamento: esperado {{ formatMoney(lastClosed.expected_amount) }}, contado {{ formatMoney(lastClosed.counted_amount) }}
          <strong :class="Number(lastClosed.difference) === 0 ? 'text-success' : 'text-danger'">({{ Number(lastClosed.difference) === 0 ? 'conferido' : `diferença ${formatMoney(lastClosed.difference)}` }})</strong>.
        </p>
        <h2 class="text-lg font-semibold">O caixa está fechado</h2>
        <STextField v-model="opening" label="Troco inicial na gaveta (R$)" inputmode="decimal" placeholder="0,00" :error="err('opening_amount')" />
        <SButton :loading="busy" @click="openCash">Abrir caixa</SButton>
      </section>

      <template v-else>
        <section class="mb-6 grid gap-4 rounded-(--radius-card) border border-line bg-surface p-5 sm:grid-cols-[1fr_auto]" aria-label="Caixa aberto">
          <div>
            <p class="text-sm text-muted">Aberto às {{ formatTime(session.opened_at, tz) }}<template v-if="session.opened_by"> por {{ session.opened_by }}</template> · troco inicial {{ formatMoney(session.opening_amount) }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums">Dinheiro na gaveta: {{ formatMoney(session.expected_amount) }}</p>
            <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm">
              <span v-for="m in session.by_method ?? []" :key="m.method"><span class="text-muted">{{ m.label }}:</span> <span class="tabular-nums">{{ formatMoney(m.in) }}</span><template v-if="Number(m.out) > 0"> <span class="text-danger tabular-nums">− {{ formatMoney(m.out) }}</span></template></span>
            </p>
          </div>
          <div class="flex flex-wrap items-start gap-2">
            <SButton @click="startSale">Nova venda</SButton>
            <SButton variant="secondary" @click="panel = 'lancamento'">Entrada / saída</SButton>
            <SButton variant="ghost" @click="panel = 'fechar'">Fechar caixa</SButton>
          </div>
        </section>

        <!-- Venda de balcão -->
        <section v-if="panel === 'venda'" class="mb-6 grid gap-5 rounded-(--radius-card) border border-brand-600 bg-surface p-5 lg:grid-cols-[1fr_22rem]" aria-label="Venda de balcão">
          <div class="grid content-start gap-3">
            <h2 class="text-lg font-semibold">Venda de balcão</h2>
            <input v-model="search" type="search" placeholder="Buscar produto" aria-label="Buscar produto" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3">
            <div class="grid max-h-80 grid-cols-2 gap-2 overflow-y-auto sm:grid-cols-3">
              <button v-for="o in options" :key="o.key" type="button" class="rounded-(--radius-control) border border-line p-2 text-left text-sm hover:border-brand-600 hover:bg-brand-50" @click="addLine(o)">
                <span class="block font-medium">{{ o.name }}</span>
                <span class="text-muted tabular-nums">{{ formatMoney(centsToString(o.unit)) }}</span>
              </button>
            </div>
            <div class="flex flex-wrap items-end gap-2">
              <div class="min-w-40 flex-1"><STextField v-model="sale.customName" label="Item avulso" placeholder="Ex.: Bolo de aniversário" /></div>
              <div class="w-28"><STextField v-model="sale.customPrice" label="Valor (R$)" inputmode="decimal" /></div>
              <SButton variant="secondary" @click="addCustom">Adicionar</SButton>
            </div>
          </div>
          <div class="grid content-start gap-3 rounded-(--radius-control) bg-canvas p-4">
            <p v-if="cart.length === 0" class="text-sm text-muted">Toque nos produtos para adicionar.</p>
            <ul class="grid gap-2" aria-label="Itens da venda">
              <li v-for="l in cart" :key="l.key" class="flex items-center gap-2 text-sm">
                <span class="min-w-0 flex-1">{{ l.name }}<span class="block text-muted tabular-nums">{{ formatMoney(centsToString(l.unit * l.quantity)) }}</span></span>
                <SStepper :model-value="l.quantity" :label="l.name" :max="999" @update:model-value="setQty(l, $event)" />
              </li>
            </ul>
            <STextField v-model="sale.discount" label="Desconto (R$)" inputmode="decimal" />
            <fieldset>
              <legend class="mb-1.5 text-sm font-medium">Pagamento</legend>
              <div class="flex flex-wrap gap-1.5">
                <label v-for="m in CASH_METHODS" :key="m.value" class="cursor-pointer rounded-full border border-line px-3 py-1.5 text-sm has-[:checked]:border-brand-600 has-[:checked]:bg-brand-600 has-[:checked]:text-brand-contrast">
                  <input v-model="sale.method" type="radio" :value="m.value" class="sr-only">{{ m.label }}
                </label>
              </div>
            </fieldset>
            <STextField v-if="sale.method === 'cash'" v-model="sale.received" label="Recebido (R$)" inputmode="decimal" />
            <p v-if="change !== null" class="text-sm" :class="change < 0 ? 'text-danger' : 'font-semibold'">{{ change < 0 ? 'Valor recebido menor que o total' : `Troco: ${formatMoney(centsToString(change))}` }}</p>
            <SButton block :loading="busy" :disabled="cart.length === 0 || (change !== null && change < 0)" @click="finishSale">Concluir venda · {{ formatMoney(centsToString(total)) }}</SButton>
            <SButton variant="ghost" @click="panel = null">Fechar</SButton>
          </div>
        </section>

        <!-- Entrada / saída -->
        <section v-if="panel === 'lancamento'" class="mb-6 grid max-w-xl gap-3 rounded-(--radius-card) border border-brand-600 bg-surface p-5" aria-label="Entrada ou saída">
          <h2 class="text-lg font-semibold">Entrada ou saída</h2>
          <label class="grid gap-1.5 text-sm font-medium">Tipo
            <select v-model="manual.category" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3">
              <optgroup label="Entradas"><option v-for="c in CASH_MANUAL.filter((x) => x.type === 'in')" :key="c.value" :value="c.value">{{ c.label }}</option></optgroup>
              <optgroup label="Saídas"><option v-for="c in CASH_MANUAL.filter((x) => x.type === 'out')" :key="c.value" :value="c.value">{{ c.label }}</option></optgroup>
            </select>
          </label>
          <label v-if="!manualDef?.drawerOnly" class="grid gap-1.5 text-sm font-medium">Forma
            <select v-model="manual.method" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3">
              <option v-for="m in CASH_METHODS" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>
          </label>
          <p v-else class="text-sm text-muted">Sempre em dinheiro (mexe na gaveta).</p>
          <STextField v-model="manual.amount" label="Valor (R$)" inputmode="decimal" :error="err('amount')" />
          <STextField v-model="manual.description" label="Descrição (opcional)" placeholder="Ex.: Leite condensado, depósito no banco" />
          <div class="flex gap-2"><SButton :loading="busy" @click="saveEntry">Registrar</SButton><SButton variant="ghost" @click="panel = null">Cancelar</SButton></div>
        </section>

        <!-- Fechar -->
        <section v-if="panel === 'fechar'" class="mb-6 grid max-w-xl gap-3 rounded-(--radius-card) border border-brand-600 bg-surface p-5" aria-label="Fechar caixa">
          <h2 class="text-lg font-semibold">Fechar caixa</h2>
          <p class="text-sm">Conte o dinheiro da gaveta. Esperado: <strong class="tabular-nums">{{ formatMoney(session.expected_amount) }}</strong></p>
          <STextField v-model="closing.counted" label="Dinheiro contado (R$)" inputmode="decimal" :error="err('counted_amount')" />
          <p v-if="closing.counted" class="text-sm font-semibold" :class="closingDiff === 0 ? 'text-success' : 'text-danger'">
            {{ closingDiff === 0 ? 'Conferido, sem diferença' : closingDiff > 0 ? `Sobra de ${formatMoney(centsToString(closingDiff))}` : `Falta de ${formatMoney(centsToString(-closingDiff))}` }}
          </p>
          <STextField v-model="closing.notes" label="Observação (opcional)" />
          <div class="flex gap-2"><SButton :loading="busy" @click="closeCash">Confirmar fechamento</SButton><SButton variant="ghost" @click="panel = null">Cancelar</SButton></div>
        </section>

        <section aria-label="Lançamentos do caixa">
          <h2 class="mb-3 font-semibold">Lançamentos de hoje</h2>
          <p v-if="entries.length === 0" class="text-sm text-muted">Nada lançado ainda.</p>
          <ul v-else class="divide-y divide-line rounded-(--radius-card) border border-line bg-surface">
            <li v-for="e in entries" :key="e.id" class="grid gap-1 px-4 py-3 text-sm" :class="e.voided && 'opacity-55'">
              <div class="flex flex-wrap items-baseline justify-between gap-2">
                <span><span class="text-muted tabular-nums">{{ formatTime(e.occurred_at, tz) }}</span> · <strong>{{ e.category_label }}</strong><template v-if="e.description"> · {{ e.description }}</template></span>
                <span class="font-semibold tabular-nums" :class="e.type === 'out' ? 'text-danger' : 'text-success'" :style="e.voided ? 'text-decoration: line-through' : ''">{{ e.type === 'out' ? '−' : '+' }} {{ formatMoney(e.amount) }}</span>
              </div>
              <p class="text-muted">{{ e.method_label }}<template v-if="e.items"> · {{ e.items.filter((i) => i.name !== 'Desconto').map((i) => `${i.quantity}× ${i.name}`).join(', ') }}</template><template v-if="e.created_by"> · {{ e.created_by }}</template></p>
              <p v-if="e.voided" class="text-danger">Estornado: {{ e.void_reason }}</p>
              <div v-else-if="manage">
                <div v-if="voiding === e.id" class="mt-1 flex flex-wrap items-end gap-2">
                  <div class="min-w-48 flex-1"><STextField v-model="voidReason" label="Motivo do estorno" :error="err('reason')" /></div>
                  <SButton variant="secondary" :loading="busy" @click="voidEntry(e)">Estornar</SButton>
                  <SButton variant="ghost" @click="voiding = null">Cancelar</SButton>
                </div>
                <button v-else type="button" class="text-xs font-semibold text-muted underline hover:text-ink" @click="voiding = e.id; voidReason = ''">Estornar</button>
              </div>
            </li>
          </ul>
        </section>
      </template>
    </template>

    <!-- ===== Relatório ===== -->
    <section v-else aria-label="Relatório do caixa" class="grid gap-6">
      <div class="flex flex-wrap items-end gap-3">
        <label class="grid gap-1.5 text-sm font-medium">De<input v-model="period.from" type="date" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3"></label>
        <label class="grid gap-1.5 text-sm font-medium">Até<input v-model="period.to" type="date" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3"></label>
        <SButton variant="secondary" @click="loadReport">Atualizar</SButton>
        <a :href="cashApi.csvUrl({ branch: branchId || null, from: period.from, to: period.to })" class="inline-flex min-h-11 items-center rounded-(--radius-control) border border-line px-4 text-sm font-semibold hover:bg-canvas">Baixar planilha (CSV)</a>
      </div>
      <template v-if="summary">
        <div class="grid gap-3 sm:grid-cols-3">
          <div class="rounded-(--radius-card) border border-line bg-surface p-4"><p class="text-sm text-muted">Entradas</p><p class="text-2xl font-bold text-success tabular-nums">{{ formatMoney(summary.in) }}</p></div>
          <div class="rounded-(--radius-card) border border-line bg-surface p-4"><p class="text-sm text-muted">Saídas</p><p class="text-2xl font-bold text-danger tabular-nums">{{ formatMoney(summary.out) }}</p></div>
          <div class="rounded-(--radius-card) border border-line bg-surface p-4"><p class="text-sm text-muted">Saldo</p><p class="text-2xl font-bold tabular-nums">{{ formatMoney(summary.balance) }}</p></div>
        </div>
        <div class="grid gap-6 lg:grid-cols-2">
          <div>
            <h3 class="mb-2 font-semibold">Por forma de pagamento</h3>
            <table class="w-full text-sm">
              <tbody>
                <tr v-for="m in summary.by_method" :key="m.method" class="border-b border-line"><td class="py-2">{{ m.label }}</td><td class="text-right text-success tabular-nums">{{ formatMoney(m.in) }}</td><td class="text-right text-danger tabular-nums">{{ Number(m.out) ? `− ${formatMoney(m.out)}` : '' }}</td></tr>
              </tbody>
            </table>
          </div>
          <div>
            <h3 class="mb-2 font-semibold">Por categoria</h3>
            <table class="w-full text-sm">
              <tbody>
                <tr v-for="c in summary.by_category" :key="c.category" class="border-b border-line"><td class="py-2">{{ c.label }} <span class="text-muted">({{ c.count }})</span></td><td class="text-right tabular-nums" :class="c.type === 'out' ? 'text-danger' : 'text-success'">{{ c.type === 'out' ? '−' : '' }} {{ formatMoney(c.total) }}</td></tr>
              </tbody>
            </table>
          </div>
        </div>
        <div v-if="summary.by_day.length > 1">
          <h3 class="mb-2 font-semibold">Dia a dia</h3>
          <ul class="grid gap-1.5" aria-label="Entradas e saídas por dia">
            <li v-for="d in summary.by_day" :key="d.date" class="grid grid-cols-[6rem_1fr] items-center gap-3 text-sm">
              <span class="text-muted tabular-nums">{{ d.date.split('-').reverse().slice(0, 2).join('/') }}</span>
              <span class="grid gap-0.5">
                <span class="h-2 rounded-full bg-success" :style="{ width: `${(Number(d.in) / maxDay) * 100}%` }" :title="`Entradas ${formatMoney(d.in)}`" />
                <span class="h-2 rounded-full bg-danger" :style="{ width: `${(Number(d.out) / maxDay) * 100}%` }" :title="`Saídas ${formatMoney(d.out)}`" />
              </span>
            </li>
          </ul>
        </div>
      </template>
      <div>
        <h3 class="mb-2 font-semibold">Fechamentos</h3>
        <p v-if="history.length === 0" class="text-sm text-muted">Nenhum caixa ainda.</p>
        <ul v-else class="divide-y divide-line rounded-(--radius-card) border border-line bg-surface text-sm">
          <li v-for="h in history" :key="h.id" class="flex flex-wrap justify-between gap-2 px-4 py-3">
            <span>{{ new Date(h.opened_at).toLocaleDateString('pt-BR', { timeZone: tz }) }} · {{ h.branch.name }}<template v-if="h.closed_by"> · fechado por {{ h.closed_by }}</template></span>
            <span v-if="h.open" class="font-semibold text-brand-700">Aberto</span>
            <span v-else class="tabular-nums">esperado {{ formatMoney(h.expected_amount) }} · contado {{ formatMoney(h.counted_amount) }} · <strong :class="Number(h.difference) === 0 ? 'text-success' : 'text-danger'">{{ Number(h.difference) === 0 ? 'ok' : formatMoney(h.difference) }}</strong></span>
          </li>
        </ul>
      </div>
    </section>
  </template>
</template>
