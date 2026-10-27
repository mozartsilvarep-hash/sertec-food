<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import {
  ApiError, FIXED_COST_CATEGORIES, describeError, financeApi, formatMoney, merchantApi,
  type Branch, type FinanceSummary, type FixedCost, type FixedCostCategory, type PaymentFeeRow,
} from '@sertec/api-client'
import { SAlert, SButton, SPageState, STextField } from '@sertec/ui'
import BranchPicker from '../components/BranchPicker.vue'
import { notify } from '../notify'
import { me } from '../session'

/**
 * Financeiro: quanto entrou, quanto ficou com a maquininha/Pix, quanto saiu
 * em despesas e custos fixos — e o resultado do período.
 */
const tz = computed(() => me.value?.tenant.timezone ?? 'America/Sao_Paulo')
const tab = ref<'resultado' | 'fixos' | 'taxas'>('resultado')
const branches = ref<Branch[]>([])
const branchId = ref('')
const state = ref<'loading' | 'ready' | 'error' | 'unavailable'>('loading')

function money(v: string): string {
  const clean = v.replace(/\s|R\$/g, '').replace(/\.(?=\d{3}(\D|$))/g, '').replace(',', '.')
  const n = Number(clean)
  return clean !== '' && Number.isFinite(n) && n >= 0 ? n.toFixed(2) : ''
}

/* ---------- Resultado ---------- */
const day = (d: Date) => d.toLocaleDateString('en-CA', { timeZone: tz.value })
const PRESETS = [
  { key: 'today', label: 'Hoje' },
  { key: '7d', label: '7 dias' },
  { key: 'month', label: 'Este mês' },
  { key: 'last', label: 'Mês passado' },
] as const
const preset = ref<(typeof PRESETS)[number]['key'] | 'custom'>('month')
const period = reactive({ from: '', to: '' })
function applyPreset(k: typeof preset.value) {
  preset.value = k
  const now = new Date()
  const [y, m] = day(now).split('-').map(Number) as [number, number]
  if (k === 'today') Object.assign(period, { from: day(now), to: day(now) })
  if (k === '7d') Object.assign(period, { from: day(new Date(now.getTime() - 6 * 864e5)), to: day(now) })
  if (k === 'month') Object.assign(period, { from: `${y}-${String(m).padStart(2, '0')}-01`, to: day(now) })
  if (k === 'last') {
    const ly = m === 1 ? y - 1 : y
    const lm = m === 1 ? 12 : m - 1
    const last = new Date(Date.UTC(ly, lm, 0)).getUTCDate()
    Object.assign(period, { from: `${ly}-${String(lm).padStart(2, '0')}-01`, to: `${ly}-${String(lm).padStart(2, '0')}-${last}` })
  }
}
const summary = ref<FinanceSummary | null>(null)
const loadingSummary = ref(false)
async function loadSummary() {
  loadingSummary.value = true
  try {
    summary.value = await financeApi.summary({ from: period.from, to: period.to, branch: branchId.value || null })
  } finally {
    loadingSummary.value = false
  }
}
const positive = computed(() => Number(summary.value?.result ?? 0) >= 0)
const margin = computed(() => (summary.value?.margin_bp == null ? null : (summary.value.margin_bp / 100).toLocaleString('pt-BR', { maximumFractionDigits: 1 })))
const feeShare = computed(() => {
  const s = Number(summary.value?.sales ?? 0)
  return s > 0 ? ((Number(summary.value?.fees ?? 0) / s) * 100).toLocaleString('pt-BR', { maximumFractionDigits: 2 }) : null
})

/* ---------- Custos fixos ---------- */
const costs = ref<FixedCost[]>([])
const costForm = reactive({ id: '' as string, name: '', category: 'bills' as FixedCostCategory, amount: '', due_day: '' })
const costErrors = ref<Record<string, string[]>>({})
const monthlyFixed = computed(() => costs.value.filter((c) => c.active).reduce((s, c) => s + Number(c.amount), 0))
function editCost(c: FixedCost | null) {
  costErrors.value = {}
  Object.assign(costForm, c
    ? { id: c.id, name: c.name, category: c.category, amount: c.amount.replace('.', ','), due_day: c.due_day ? String(c.due_day) : '' }
    : { id: 'new', name: '', category: 'bills', amount: '', due_day: '' })
}
async function saveCost() {
  costErrors.value = {}
  const body = { name: costForm.name.trim(), category: costForm.category, amount: money(costForm.amount), due_day: costForm.due_day ? Number(costForm.due_day) : null }
  try {
    if (costForm.id === 'new') await financeApi.createFixedCost(body)
    else await financeApi.updateFixedCost(costForm.id, body)
    costForm.id = ''
    costs.value = await financeApi.fixedCosts()
    notify('Custo fixo salvo')
  } catch (e) {
    costErrors.value = describeError(e).fields ?? {}
    if (!Object.keys(costErrors.value).length) notify(describeError(e).message, 'error')
  }
}
async function toggleCost(c: FixedCost) {
  await financeApi.updateFixedCost(c.id, { active: !c.active })
  costs.value = await financeApi.fixedCosts()
}
async function removeCost(c: FixedCost) {
  const kept = await financeApi.removeFixedCost(c.id)
  costs.value = await financeApi.fixedCosts()
  notify(kept ? 'Custo já pago pelo caixa: ficou desativado (o histórico continua)' : 'Custo removido')
}

/* ---------- Taxas ---------- */
const fees = ref<PaymentFeeRow[]>([])
const savingFees = ref(false)
const feeError = ref<string | null>(null)
async function saveFees() {
  savingFees.value = true
  feeError.value = null
  try {
    fees.value = await financeApi.saveFees(fees.value.map((f) => ({ method: f.method, percent: f.percent.replace(',', '.') || '0', fixed: money(f.fixed) || '0.00' })))
    notify('Taxas salvas. Valem para as próximas vendas.')
  } catch (e) {
    const d = describeError(e)
    feeError.value = d.fields ? Object.values(d.fields)[0]?.[0] ?? d.message : d.message
  } finally {
    savingFees.value = false
  }
}
const example = (f: PaymentFeeRow) => {
  const fee = 100 * (Number(f.percent.replace(',', '.')) || 0) / 100 + (Number(money(f.fixed)) || 0)
  return `Venda de R$ 100,00: taxa ${formatMoney(fee.toFixed(2))}, você recebe ${formatMoney((100 - fee).toFixed(2))}`
}

async function load() {
  state.value = 'loading'
  try {
    branches.value = await merchantApi.branches()
    applyPreset('month')
    const [c, f] = await Promise.all([financeApi.fixedCosts(), financeApi.fees()])
    costs.value = c
    fees.value = f.map((x) => ({ ...x, percent: x.percent.replace('.', ','), fixed: x.fixed.replace('.', ',') }))
    await loadSummary()
    state.value = 'ready'
  } catch (e) {
    state.value = e instanceof ApiError && e.code === 'feature_unavailable' ? 'unavailable' : 'error'
  }
}
watch(branchId, () => void loadSummary())
onMounted(load)

const CATEGORY_LABEL = Object.fromEntries(FIXED_COST_CATEGORIES.map((c) => [c.value, c.label]))
</script>

<template>
  <h1 class="mb-2 font-display text-2xl font-bold">Financeiro</h1>
  <p class="mb-6 max-w-[65ch] text-sm text-muted">Vendas menos taxas da maquininha e do Pix, despesas e custos fixos: o que sobrou no período.</p>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'unavailable'" state="empty" title="Financeiro não incluído no seu plano" message="Fale com a SerTec para liberar o caixa." />
  <SPageState v-else-if="state === 'error'" state="error" @retry="load" />
  <template v-else>
    <div class="relative mb-6 flex w-fit max-w-full gap-1 overflow-x-auto rounded-full border border-line bg-surface p-1 [scrollbar-width:none]" role="tablist">
      <button
        v-for="t in [{ k: 'resultado', l: 'Resultado' }, { k: 'fixos', l: 'Custos fixos' }, { k: 'taxas', l: 'Taxas de cartão e Pix' }] as const" :key="t.k" type="button" role="tab" :aria-selected="tab === t.k"
        class="shrink-0 rounded-full px-4 py-1.5 text-sm font-medium whitespace-nowrap" :class="tab === t.k ? 'bg-ink text-white' : 'text-muted'" @click="tab = t.k"
      >
        {{ t.l }}
      </button>
    </div>

    <!-- ===== Resultado ===== -->
    <section v-if="tab === 'resultado'" aria-label="Resultado do período" class="grid max-w-5xl gap-6">
      <div class="flex flex-wrap items-end gap-3">
        <div class="flex flex-wrap gap-1.5" role="group" aria-label="Período">
          <button v-for="p in PRESETS" :key="p.key" type="button" class="rounded-full border px-3 py-1.5 text-sm" :class="preset === p.key ? 'border-ink bg-ink text-white' : 'border-line bg-surface'" @click="applyPreset(p.key); loadSummary()">{{ p.label }}</button>
        </div>
        <label class="grid gap-1 text-sm font-medium">De<input v-model="period.from" type="date" class="min-h-10 rounded-lg border border-line bg-surface px-2" @change="preset = 'custom'"></label>
        <label class="grid gap-1 text-sm font-medium">Até<input v-model="period.to" type="date" class="min-h-10 rounded-lg border border-line bg-surface px-2" @change="preset = 'custom'"></label>
        <SButton variant="secondary" :loading="loadingSummary" @click="loadSummary">Atualizar</SButton>
      </div>
      <BranchPicker v-if="branches.length > 1" v-model="branchId" :branches="branches" allow-all />

      <template v-if="summary">
        <div class="grid gap-4 lg:grid-cols-[1fr_1.2fr]">
          <div class="rounded-(--radius-card) border p-5" :class="positive ? 'border-success/40 bg-success/5' : 'border-danger/40 bg-danger-soft'">
            <p class="text-sm text-muted">Resultado do período</p>
            <p class="mt-1 text-4xl font-bold tabular-nums" :class="positive ? 'text-success' : 'text-danger'">{{ formatMoney(summary.result) }}</p>
            <p v-if="margin !== null" class="mt-1 text-sm">{{ margin }}% das vendas</p>
            <p class="mt-3 text-xs text-muted">Sangria ({{ formatMoney(summary.cash_moves.withdrawal) }}) e reforço ({{ formatMoney(summary.cash_moves.supply) }}) só mudam o dinheiro de lugar: não entram no resultado.</p>
          </div>
          <dl class="grid content-start gap-1.5 rounded-(--radius-card) border border-line bg-surface p-5 text-sm" aria-label="Como chegamos ao resultado">
            <div class="flex justify-between"><dt>Vendas (balcão e cardápio)</dt><dd class="font-semibold tabular-nums">{{ formatMoney(summary.sales) }}</dd></div>
            <div v-if="Number(summary.refunds)" class="flex justify-between text-danger"><dt>− Estornos</dt><dd class="tabular-nums">{{ formatMoney(summary.refunds) }}</dd></div>
            <div class="flex justify-between text-danger"><dt>− Taxas de cartão e Pix <span v-if="feeShare" class="text-muted">({{ feeShare }}%)</span></dt><dd class="tabular-nums">{{ formatMoney(summary.fees) }}</dd></div>
            <div v-if="Number(summary.other_in)" class="flex justify-between text-success"><dt>+ Outras entradas</dt><dd class="tabular-nums">{{ formatMoney(summary.other_in) }}</dd></div>
            <div class="flex justify-between text-danger"><dt>− Despesas lançadas</dt><dd class="tabular-nums">{{ formatMoney(summary.expenses) }}</dd></div>
            <div class="flex justify-between text-danger"><dt>− Custos fixos do período</dt><dd class="tabular-nums">{{ formatMoney(summary.fixed_costs) }}</dd></div>
            <div class="mt-1 flex justify-between border-t border-line pt-2 text-base font-bold"><dt>= Resultado</dt><dd class="tabular-nums">{{ formatMoney(summary.result) }}</dd></div>
          </dl>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
          <div>
            <h2 class="mb-2 font-semibold">Por forma de pagamento</h2>
            <div class="relative overflow-x-auto rounded-(--radius-card) border border-line bg-surface">
              <table class="w-full text-sm">
                <thead class="bg-canvas text-left text-muted"><tr><th class="px-3 py-2 font-medium">Forma</th><th class="px-3 py-2 text-right font-medium">Vendido</th><th class="px-3 py-2 text-right font-medium">Taxa</th><th class="px-3 py-2 text-right font-medium">Recebe</th></tr></thead>
                <tbody class="divide-y divide-line">
                  <tr v-for="m in summary.by_method" :key="m.method"><td class="px-3 py-2">{{ m.label }} <span class="text-muted">({{ m.count }})</span></td><td class="px-3 py-2 text-right tabular-nums">{{ formatMoney(m.gross) }}</td><td class="px-3 py-2 text-right text-danger tabular-nums">{{ Number(m.fees) ? `− ${formatMoney(m.fees)}` : '—' }}</td><td class="px-3 py-2 text-right font-semibold tabular-nums">{{ formatMoney(m.net) }}</td></tr>
                  <tr v-if="!summary.by_method.length"><td colspan="4" class="px-3 py-4 text-center text-muted">Nenhuma venda no período.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
          <div class="grid content-start gap-6">
            <div>
              <h2 class="mb-2 font-semibold">Despesas por tipo</h2>
              <p v-if="!summary.expenses_by_category.length" class="text-sm text-muted">Nenhuma despesa lançada no caixa.</p>
              <ul v-else class="divide-y divide-line rounded-(--radius-card) border border-line bg-surface text-sm">
                <li v-for="e in summary.expenses_by_category" :key="e.category" class="flex justify-between px-3 py-2"><span>{{ e.label }} <span class="text-muted">({{ e.count }})</span></span><span class="tabular-nums">{{ formatMoney(e.total) }}</span></li>
              </ul>
            </div>
            <div>
              <h2 class="mb-2 font-semibold">Custos fixos no período</h2>
              <p v-if="!summary.fixed_items.length" class="text-sm text-muted">Nenhum custo fixo cadastrado. <button type="button" class="font-semibold text-brand-700 underline" @click="tab = 'fixos'">Cadastrar</button></p>
              <ul v-else class="divide-y divide-line rounded-(--radius-card) border border-line bg-surface text-sm">
                <li v-for="f in summary.fixed_items" :key="f.id" class="flex justify-between px-3 py-2"><span>{{ f.name }} <span class="text-muted">({{ formatMoney(f.monthly) }}/mês)</span></span><span class="tabular-nums">{{ formatMoney(f.period) }}</span></li>
              </ul>
              <p v-if="Number(summary.fixed_paid_in_cash)" class="mt-2 text-xs text-muted">{{ formatMoney(summary.fixed_paid_in_cash) }} pagos pelo caixa nesse período já estão dentro dos custos fixos (não contam duas vezes).</p>
            </div>
          </div>
        </div>
      </template>
    </section>

    <!-- ===== Custos fixos ===== -->
    <section v-else-if="tab === 'fixos'" aria-label="Custos fixos" class="grid max-w-3xl gap-4">
      <p class="text-sm text-muted">Despesas que se repetem todo mês. Entram no resultado proporcionais aos dias do período. Ao pagar pelo caixa, escolha o custo na saída para não contar duas vezes.</p>
      <p class="text-sm">Total por mês: <strong class="tabular-nums">{{ formatMoney(monthlyFixed.toFixed(2)) }}</strong></p>
      <ul v-if="costs.length" class="divide-y divide-line rounded-(--radius-card) border border-line bg-surface">
        <li v-for="c in costs" :key="c.id" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm" :class="!c.active && 'opacity-55'">
          <span class="min-w-0">
            <strong>{{ c.name }}</strong> · {{ formatMoney(c.amount) }}/mês
            <span class="block text-muted">{{ CATEGORY_LABEL[c.category] }}<template v-if="c.due_day"> · vence dia {{ c.due_day }}</template><template v-if="c.branch"> · {{ c.branch.name }}</template><template v-if="!c.active"> · desativado</template></span>
          </span>
          <span class="flex gap-2">
            <SButton variant="secondary" @click="editCost(c)">Editar</SButton>
            <SButton variant="ghost" @click="toggleCost(c)">{{ c.active ? 'Desativar' : 'Ativar' }}</SButton>
            <SButton variant="ghost" @click="removeCost(c)">Remover</SButton>
          </span>
        </li>
      </ul>
      <p v-else class="text-sm text-muted">Nenhum custo fixo ainda (aluguel, internet, salários, contador…).</p>
      <div v-if="!costForm.id"><SButton @click="editCost(null)">Novo custo fixo</SButton></div>
      <form v-else class="grid gap-4 rounded-(--radius-card) border border-brand-100 bg-surface p-5 sm:grid-cols-2" aria-label="Custo fixo" @submit.prevent="saveCost">
        <STextField v-model="costForm.name" label="Nome" placeholder="Ex.: Aluguel" :error="costErrors.name?.[0]" />
        <label class="grid gap-1.5 text-sm font-medium">Tipo
          <select v-model="costForm.category" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3">
            <option v-for="c in FIXED_COST_CATEGORIES" :key="c.value" :value="c.value">{{ c.label }}</option>
          </select>
        </label>
        <STextField v-model="costForm.amount" label="Valor por mês (R$)" inputmode="decimal" :error="costErrors.amount?.[0]" />
        <STextField v-model="costForm.due_day" label="Dia do vencimento (opcional)" inputmode="numeric" :error="costErrors.due_day?.[0]" />
        <div class="flex gap-3 sm:col-span-2"><SButton type="submit">Salvar custo</SButton><SButton variant="ghost" @click="costForm.id = ''">Cancelar</SButton></div>
      </form>
    </section>

    <!-- ===== Taxas ===== -->
    <section v-else aria-label="Taxas de cartão e Pix" class="grid max-w-3xl gap-4">
      <p class="text-sm text-muted">Quanto a maquininha, o banco ou o Mercado Pago cobram em cada forma. A taxa é calculada em cada venda e aparece no resultado. Mudanças valem para as próximas vendas.</p>
      <SAlert v-if="feeError">{{ feeError }}</SAlert>
      <form class="grid gap-3" aria-label="Taxas" @submit.prevent="saveFees">
        <div v-for="f in fees" :key="f.method" class="grid gap-2 rounded-(--radius-card) border border-line bg-surface p-4 sm:grid-cols-[1fr_8rem_8rem] sm:items-end">
          <div>
            <p class="font-semibold">{{ f.label }}</p>
            <p class="text-xs text-muted">{{ example(f) }}</p>
          </div>
          <STextField v-model="f.percent" :label="`Taxa % (${f.label})`" inputmode="decimal" />
          <STextField v-model="f.fixed" :label="`Fixo R$ (${f.label})`" inputmode="decimal" />
        </div>
        <div><SButton type="submit" :loading="savingFees">Salvar taxas</SButton></div>
      </form>
    </section>
  </template>
</template>
