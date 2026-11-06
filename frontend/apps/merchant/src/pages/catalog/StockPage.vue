<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import {
  ApiError, describeError, formatDateTime, merchantApi, stockApi,
  type Branch, type StockMove, type StockRow,
} from '@sertec/api-client'
import { SButton, SPageState, SSheet } from '@sertec/ui'
import BranchPicker from '../../components/BranchPicker.vue'
import { notify } from '../../notify'
import { can, me } from '../../session'

/**
 * Estoque simples por unidade: liga o controle por produto, dá entrada,
 * lança perdas e confere a contagem. Venda baixa sozinha; zerou, esgota.
 */
const branches = ref<Branch[]>([])
const branchId = ref('')
const rows = ref<StockRow[]>([])
const state = ref<'loading' | 'ready' | 'error' | 'offline'>('loading')
const search = ref('')
const filter = ref<'tracked' | 'low' | 'all'>('tracked')
const canManage = computed(() => can('catalog.manage'))
const tz = computed(() => me.value?.tenant.timezone ?? 'America/Sao_Paulo')

const fold = (s: string) => s.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase().trim()
const visible = computed(() => {
  const term = fold(search.value)
  return rows.value.filter((r) => (filter.value === 'all' || (filter.value === 'tracked' ? r.tracked : r.tracked && r.low))
    && (!term || fold(r.name).includes(term) || fold(r.code ?? '') === term))
})
const counts = computed(() => ({ tracked: rows.value.filter((r) => r.tracked).length, low: rows.value.filter((r) => r.tracked && r.low).length }))
const filters = computed(() => [['tracked', `Controlados (${counts.value.tracked})`], ['low', `Baixo (${counts.value.low})`], ['all', 'Todos']] as const)

async function loadRows() {
  const data = await stockApi.list(branchId.value || null)
  rows.value = data.products
  if (!counts.value.tracked && filter.value === 'tracked') filter.value = 'all'
}

async function load() {
  state.value = 'loading'
  try {
    branches.value = await merchantApi.branches()
    branchId.value ||= branches.value[0]?.id ?? ''
    await loadRows()
    state.value = 'ready'
  } catch (e) {
    state.value = e instanceof ApiError && e.code === 'network_error' ? 'offline' : 'error'
  }
}
watch(branchId, (id, old) => { if (old && id !== old) void loadRows().catch(() => (state.value = 'error')) })
onMounted(load)

/* ---------- Ligar controle / mínimo ---------- */
const setup = reactive({ open: false, row: null as StockRow | null, quantity: '', min: '', saving: false, error: '' })
function openSetup(r: StockRow) {
  Object.assign(setup, { open: true, row: r, quantity: r.quantity === null ? '' : String(r.quantity), min: r.min_quantity === null ? '' : String(r.min_quantity), error: '' })
}
const int = (v: string) => Math.max(0, Math.floor(Number(v.replace(/\D/g, '')) || 0))
async function saveSetup(tracked: boolean) {
  if (!setup.row) return
  setup.saving = true
  setup.error = ''
  try {
    const data = await stockApi.configure(setup.row.id, { branch: branchId.value || null, tracked, quantity: int(setup.quantity), min_quantity: int(setup.min) })
    rows.value = data.products
    setup.open = false
    notify(tracked ? `Estoque de ${setup.row.name} salvo` : `${setup.row.name} sem controle de estoque`)
  } catch (e) {
    setup.error = describeError(e).message
  } finally {
    setup.saving = false
  }
}

/* ---------- Ajuste ---------- */
const TYPES = [
  { value: 'entry', label: 'Entrada', hint: 'Chegou mercadoria: soma ao saldo.' },
  { value: 'loss', label: 'Perda', hint: 'Quebrou, venceu, consumo interno: tira do saldo.' },
  { value: 'count', label: 'Contagem', hint: 'Contou na prateleira: o saldo passa a ser este número.' },
] as const
const adj = reactive({ open: false, row: null as StockRow | null, type: 'entry' as 'entry' | 'loss' | 'count', quantity: '', reason: '', saving: false, error: '' })
function openAdjust(r: StockRow, type: 'entry' | 'loss' | 'count' = 'entry') {
  Object.assign(adj, { open: true, row: r, type, quantity: '', reason: '', error: '' })
}
async function saveAdjust() {
  if (!adj.row || adj.quantity.trim() === '') return
  adj.saving = true
  adj.error = ''
  try {
    const res = await stockApi.adjust(adj.row.id, { branch: branchId.value || null, type: adj.type, quantity: int(adj.quantity), reason: adj.reason.trim() || null })
    Object.assign(adj.row, { quantity: res.quantity, low: res.low, sold_out: res.sold_out || adj.row.sold_out && res.quantity <= 0 })
    adj.open = false
    notify(`${adj.row.name}: saldo ${res.quantity}`)
    void loadRows()
  } catch (e) {
    const d = describeError(e)
    adj.error = d.fields ? Object.values(d.fields)[0]?.[0] ?? d.message : d.message
  } finally {
    adj.saving = false
  }
}

/* ---------- Histórico ---------- */
const hist = reactive({ open: false, row: null as StockRow | null, moves: [] as StockMove[], loading: false })
async function openHistory(r: StockRow) {
  Object.assign(hist, { open: true, row: r, moves: [], loading: true })
  try {
    hist.moves = await stockApi.movements(r.id, branchId.value || null)
  } catch (e) {
    notify(describeError(e).message, 'error')
  } finally {
    hist.loading = false
  }
}
</script>

<template>
  <h1 class="mb-2 font-display text-2xl font-bold">Estoque</h1>
  <p class="mb-6 max-w-[65ch] text-sm text-muted">Controle só o que faz sentido contar (bebidas, sobremesas, itens prontos). A venda no site, no PDV e nas mesas baixa sozinha; quando zera, o produto fica esgotado e volta ao repor.</p>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'offline'" state="offline" @retry="load" />
  <SPageState v-else-if="state === 'error'" state="error" @retry="load" />
  <template v-else>
    <BranchPicker v-model="branchId" :branches="branches" />
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <label class="min-w-0 flex-1 basis-56">
        <span class="sr-only">Buscar produto</span>
        <input v-model="search" type="search" placeholder="Buscar por nome ou código" class="min-h-11 w-full rounded-full border border-line bg-surface px-4">
      </label>
      <div class="flex gap-1 rounded-full border border-line bg-surface p-1 text-sm" role="group" aria-label="Filtro">
        <button v-for="f in filters" :key="f[0]" type="button" class="min-h-9 rounded-full px-3" :class="filter === f[0] ? 'bg-ink text-white' : 'text-muted hover:text-ink'" :aria-pressed="filter === f[0]" @click="filter = f[0]">{{ f[1] }}</button>
      </div>
    </div>

    <SPageState v-if="visible.length === 0" state="empty" :title="filter === 'low' ? 'Nada com estoque baixo' : 'Nenhum produto'" :message="filter === 'tracked' ? 'Escolha “Todos” e ligue o controle nos produtos que quer contar.' : undefined" />
    <ul v-else class="divide-y divide-line rounded-(--radius-card) border border-line bg-surface">
      <li v-for="r in visible" :key="r.id" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
        <div class="min-w-0 flex-1 basis-48">
          <p class="truncate font-medium" :class="!r.active && 'text-muted'">{{ r.name }}<span v-if="r.code" class="ml-2 text-xs text-muted">{{ r.code }}</span></p>
          <p class="text-xs text-muted">
            <template v-if="r.tracked">Mínimo {{ r.min_quantity }}</template>
            <template v-else>Sem controle</template>
            <span v-if="r.sold_out" class="ml-2 rounded-full bg-danger/10 px-2 py-0.5 font-semibold text-danger">Esgotado</span>
            <span v-else-if="r.tracked && r.low" class="ml-2 rounded-full bg-warning/15 px-2 py-0.5 font-semibold text-warning">Estoque baixo</span>
          </p>
        </div>
        <p v-if="r.tracked" class="w-16 text-right text-xl font-bold tabular-nums" :class="r.low ? 'text-danger' : ''" :aria-label="`Saldo ${r.quantity}`">{{ r.quantity }}</p>
        <div class="flex flex-wrap gap-2">
          <template v-if="r.tracked">
            <button v-if="canManage" type="button" class="min-h-10 rounded-lg border border-line px-3 text-sm font-medium hover:bg-canvas" @click="openAdjust(r)">Ajustar</button>
            <button type="button" class="min-h-10 rounded-lg border border-line px-3 text-sm hover:bg-canvas" @click="openHistory(r)">Histórico</button>
          </template>
          <button v-if="canManage" type="button" class="min-h-10 rounded-lg px-3 text-sm text-brand-700 hover:bg-canvas" @click="openSetup(r)">{{ r.tracked ? 'Configurar' : 'Controlar estoque' }}</button>
        </div>
      </li>
    </ul>
  </template>

  <SSheet :open="setup.open" :label="`Estoque de ${setup.row?.name ?? ''}`" @close="setup.open = false">
    <form class="grid gap-4 p-5" @submit.prevent="saveSetup(true)">
      <h2 class="text-lg font-bold">{{ setup.row?.name }}</h2>
      <label v-if="!setup.row?.tracked" class="grid gap-1 text-sm font-medium">Quantidade agora
        <input v-model="setup.quantity" inputmode="numeric" required class="min-h-11 rounded-lg border border-line px-3" placeholder="0">
      </label>
      <label class="grid gap-1 text-sm font-medium">Avisar quando chegar a
        <input v-model="setup.min" inputmode="numeric" class="min-h-11 rounded-lg border border-line px-3" placeholder="0">
        <span class="text-xs font-normal text-muted">Aparece como “estoque baixo” no saldo igual ou menor.</span>
      </label>
      <p v-if="setup.error" role="alert" class="text-sm text-danger">{{ setup.error }}</p>
      <div class="flex flex-wrap justify-between gap-2">
        <button v-if="setup.row?.tracked" type="button" class="min-h-11 rounded-lg px-3 text-sm text-danger hover:bg-danger/10" :disabled="setup.saving" @click="saveSetup(false)">Parar de controlar</button>
        <SButton type="submit" :loading="setup.saving" class="ml-auto">Salvar</SButton>
      </div>
    </form>
  </SSheet>

  <SSheet :open="adj.open" :label="`Ajustar ${adj.row?.name ?? ''}`" @close="adj.open = false">
    <form class="grid gap-4 p-5" @submit.prevent="saveAdjust">
      <div class="flex items-baseline justify-between gap-2">
        <h2 class="min-w-0 truncate text-lg font-bold">{{ adj.row?.name }}</h2>
        <p class="shrink-0 text-sm text-muted">Saldo <strong class="text-ink tabular-nums">{{ adj.row?.quantity }}</strong></p>
      </div>
      <fieldset class="grid grid-cols-3 gap-2">
        <legend class="sr-only">Tipo de ajuste</legend>
        <label v-for="t in TYPES" :key="t.value" class="flex min-h-11 cursor-pointer items-center justify-center rounded-lg border px-2 text-sm font-medium" :class="adj.type === t.value ? 'border-ink bg-ink text-white' : 'border-line'">
          <input v-model="adj.type" type="radio" name="adj-type" :value="t.value" class="sr-only">{{ t.label }}
        </label>
      </fieldset>
      <p class="text-xs text-muted">{{ TYPES.find((t) => t.value === adj.type)?.hint }}</p>
      <label class="grid gap-1 text-sm font-medium">{{ adj.type === 'count' ? 'Quantidade contada' : 'Quantidade' }}
        <input v-model="adj.quantity" inputmode="numeric" required class="min-h-11 rounded-lg border border-line px-3 text-lg tabular-nums" placeholder="0">
      </label>
      <label class="grid gap-1 text-sm font-medium">Motivo (opcional)
        <input v-model="adj.reason" maxlength="140" class="min-h-11 rounded-lg border border-line px-3" :placeholder="adj.type === 'entry' ? 'Ex.: compra do fornecedor' : adj.type === 'loss' ? 'Ex.: vencido' : 'Ex.: contagem de segunda'">
      </label>
      <p v-if="adj.error" role="alert" class="text-sm text-danger">{{ adj.error }}</p>
      <SButton type="submit" :loading="adj.saving">Salvar ajuste</SButton>
    </form>
  </SSheet>

  <SSheet :open="hist.open" :label="`Histórico de ${hist.row?.name ?? ''}`" @close="hist.open = false">
    <div class="grid gap-3 overflow-y-auto p-5">
      <h2 class="text-lg font-bold">{{ hist.row?.name }}</h2>
      <SPageState v-if="hist.loading" state="loading" />
      <p v-else-if="!hist.moves.length" class="text-sm text-muted">Sem movimentos.</p>
      <ol v-else class="divide-y divide-line text-sm">
        <li v-for="(m, i) in hist.moves" :key="i" class="flex items-start justify-between gap-3 py-2">
          <div class="min-w-0">
            <p class="font-medium">{{ m.label }}<template v-if="m.order_number"> · pedido #{{ m.order_number }}</template></p>
            <p class="text-xs text-muted">{{ formatDateTime(m.occurred_at, tz) }}<template v-if="m.user"> · {{ m.user }}</template><template v-if="m.reason"> · {{ m.reason }}</template></p>
          </div>
          <p class="shrink-0 text-right tabular-nums"><span :class="m.quantity < 0 ? 'text-danger' : 'text-success'">{{ m.quantity > 0 ? '+' : '' }}{{ m.quantity }}</span><br><span class="text-xs text-muted">saldo {{ m.balance_after }}</span></p>
        </li>
      </ol>
    </div>
  </SSheet>
</template>
