<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import {
  ApiError, CASH_MANUAL, CASH_METHODS, cashApi, centsToString, describeError, formatMoney, formatTime, merchantApi, toCents,
  financeApi, type Branch, type CashEntry, type CashMethod, type CashSession, type CashSummary, type FixedCost,
} from '@sertec/api-client'
import { SAlert, SButton, SPageState, STextField } from '@sertec/ui'
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
const panel = ref<'lancamento' | 'fechar' | null>(null)
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

/* ---------- Entradas e saídas ---------- */
const manual = reactive({ kind: 'out' as 'withdrawal' | 'in' | 'out', category: 'withdrawal' as (typeof CASH_MANUAL)[number]['value'], method: 'cash' as CashMethod, amount: '', description: '', fixedCost: '' })
const manualDef = computed(() => CASH_MANUAL.find((c) => c.value === manual.category))
/** Tipos mostrados conforme o botão: sangria, entrada ou saída. */
const manualOptions = computed(() => manual.kind === 'withdrawal'
  ? CASH_MANUAL.filter((c) => c.value === 'withdrawal')
  : CASH_MANUAL.filter((c) => c.type === manual.kind && c.value !== 'withdrawal'))
const fixedCosts = ref<FixedCost[]>([])
function startManual(kind: 'withdrawal' | 'in' | 'out') {
  manual.kind = kind
  manual.category = kind === 'withdrawal' ? 'withdrawal' : kind === 'in' ? 'supply' : 'supplier'
  manual.fixedCost = ''
  panel.value = 'lancamento'
  if (manage && kind === 'out' && !fixedCosts.value.length) void financeApi.fixedCosts().then((c) => (fixedCosts.value = c.filter((x) => x.active))).catch(() => undefined)
}
function pickFixed() {
  const cost = fixedCosts.value.find((c) => c.id === manual.fixedCost)
  if (!cost) return
  manual.category = cost.category
  if (!manual.amount) manual.amount = cost.amount.replace('.', ',')
}

function saveEntry() {
  return run(async () => {
    await cashApi.entry({ branch: branchId.value || null, category: manual.category, method: manualDef.value?.drawerOnly ? 'cash' : manual.method, amount: money(manual.amount), description: manual.description.trim() || null, fixed_cost: manual.fixedCost || null })
    Object.assign(manual, { amount: '', description: '', fixedCost: '' })
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
  <p class="mb-6 max-w-[65ch] text-sm text-muted">Abra o caixa no começo do dia, venda no PDV, registre entradas e saídas e feche conferindo o dinheiro da gaveta. Os pedidos do cardápio entram sozinhos quando são concluídos.</p>

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
          <RouterLink :to="`/caixa/${lastClosed.id}/fechamento`" class="ml-1 font-semibold text-brand-700 underline">Imprimir fechamento</RouterLink>
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
            <RouterLink :to="{ path: '/pdv', query: branchId ? { branch: branchId } : {} }" class="inline-flex min-h-11 items-center rounded-(--radius-control) bg-brand-600 px-4 text-sm font-semibold text-brand-contrast hover:bg-brand-700">Abrir PDV</RouterLink>
            <SButton variant="secondary" @click="startManual('withdrawal')">Sangria</SButton>
            <SButton variant="secondary" @click="startManual('in')">Entrada</SButton>
            <SButton variant="secondary" @click="startManual('out')">Saída</SButton>
            <RouterLink :to="`/caixa/${session.id}/fechamento`" class="inline-flex min-h-11 items-center rounded-(--radius-control) px-3 text-sm font-semibold text-muted hover:bg-canvas hover:text-ink">Imprimir parcial</RouterLink>
            <SButton variant="ghost" @click="panel = 'fechar'">Fechar caixa</SButton>
          </div>
        </section>

        <!-- Entrada / saída -->
        <section v-if="panel === 'lancamento'" class="mb-6 grid max-w-xl gap-3 rounded-(--radius-card) border border-brand-600 bg-surface p-5" aria-label="Entrada ou saída">
          <h2 class="text-lg font-semibold">{{ manual.kind === 'withdrawal' ? 'Sangria (retirar dinheiro da gaveta)' : manual.kind === 'in' ? 'Entrada' : 'Saída (despesa)' }}</h2>
          <p v-if="manual.kind === 'withdrawal'" class="text-sm text-muted">Dinheiro que sai da gaveta para o cofre ou o banco. Não é despesa: só muda o dinheiro de lugar.</p>
          <label v-if="manual.kind === 'out' && fixedCosts.length" class="grid gap-1.5 text-sm font-medium">É pagamento de um custo fixo?
            <select v-model="manual.fixedCost" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3" @change="pickFixed">
              <option value="">Não, é uma despesa avulsa</option>
              <option v-for="c in fixedCosts" :key="c.id" :value="c.id">{{ c.name }} ({{ formatMoney(c.amount) }}/mês)</option>
            </select>
          </label>
          <label v-if="manual.kind !== 'withdrawal'" class="grid gap-1.5 text-sm font-medium">Tipo
            <select v-model="manual.category" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3">
              <option v-for="c in manualOptions" :key="c.value" :value="c.value">{{ c.label }}</option>
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
              <p class="text-muted">{{ e.method_label }}<template v-if="e.items"> · {{ e.items.filter((i) => i.name !== 'Desconto').map((i) => `${i.quantity}× ${i.name}`).join(', ') }}</template><template v-if="e.created_by"> · {{ e.created_by }}</template><template v-if="Number(e.fee_amount ?? 0) > 0"> · taxa {{ formatMoney(e.fee_amount!) }}</template><template v-if="e.fixed_cost"> · custo fixo</template></p>
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
            <span v-else class="tabular-nums">esperado {{ formatMoney(h.expected_amount) }} · contado {{ formatMoney(h.counted_amount) }} · <strong :class="Number(h.difference) === 0 ? 'text-success' : 'text-danger'">{{ Number(h.difference) === 0 ? 'ok' : formatMoney(h.difference) }}</strong>
              · <RouterLink :to="`/caixa/${h.id}/fechamento`" class="font-semibold text-brand-700 underline">Imprimir</RouterLink></span>
          </li>
        </ul>
      </div>
    </section>
  </template>
</template>
