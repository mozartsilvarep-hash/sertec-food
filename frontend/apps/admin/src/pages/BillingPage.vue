<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import {
  adminBillingApi, describeError, formatDay, formatMoney,
  type AdminInvoice, type AdminPlan, type BillingProviderSettings, type PlanMeta,
} from '@sertec/api-client'
import { SAlert, SButton, SPageState, STextField } from '@sertec/ui'
import { me } from '../session'

const superadmin = computed(() => me.value?.user.platform_role === 'superadmin')
const tab = ref<'invoices' | 'plans' | 'provider'>('invoices')
const message = ref<{ ok: boolean; text: string } | null>(null)

function say(ok: boolean, text: string) {
  message.value = { ok, text }
}

/* ---------- Faturas ---------- */
const filter = ref<'open' | 'overdue' | 'paid' | 'cancelled'>('open')
const invoices = ref<AdminInvoice[]>([])
const totals = ref<{ open_total: string; overdue_total: string; paid_month: string } | null>(null)
const invState = ref<'loading' | 'ready' | 'error'>('loading')

async function loadInvoices() {
  invState.value = 'loading'
  try {
    const r = await adminBillingApi.invoices(filter.value)
    invoices.value = r.data
    totals.value = r.meta
    invState.value = 'ready'
  } catch {
    invState.value = 'error'
  }
}
watch(filter, loadInvoices)

// Baixa manual / cancelamento: confirmação na própria tela.
const pending = ref<{ invoice: AdminInvoice; action: 'pay' | 'cancel'; note: string } | null>(null)

function ask(invoice: AdminInvoice, action: 'pay' | 'cancel') {
  pending.value = { invoice, action, note: action === 'pay' ? 'Pagamento recebido por fora' : '' }
}

async function confirmPending() {
  const p = pending.value
  if (!p) return
  try {
    if (p.action === 'pay') await adminBillingApi.payInvoice(p.invoice.id, p.note || null)
    else await adminBillingApi.cancelInvoice(p.invoice.id, p.note || null)
    say(true, p.action === 'pay' ? `Fatura de ${p.invoice.tenant.name} marcada como paga.` : 'Fatura cancelada.')
    pending.value = null
    await loadInvoices()
  } catch (e) {
    say(false, describeError(e).message)
  }
}

/* ---------- Planos ---------- */
const plans = ref<AdminPlan[]>([])
const meta = ref<PlanMeta | null>(null)
const editing = ref<string | null>(null)
const form = reactive<{ slug: string; name: string; description: string; active: boolean; prices: Record<string, string>; features: Record<string, boolean> }>({
  slug: '', name: '', description: '', active: true, prices: {}, features: {},
})
const errors = ref<Record<string, string[]>>({})

async function loadPlans() {
  const r = await adminBillingApi.plans()
  plans.value = r.data
  meta.value = r.meta
}

function edit(p: AdminPlan | null) {
  errors.value = {}
  editing.value = p?.slug ?? 'new'
  form.slug = p?.slug ?? ''
  form.name = p?.name ?? ''
  form.description = p?.description ?? ''
  form.active = p?.active ?? true
  form.prices = Object.fromEntries((meta.value?.periods ?? []).map((x) => [String(x.months), p?.prices[String(x.months)]?.replace('.', ',') ?? '']))
  form.features = Object.fromEntries((meta.value?.features ?? []).map((f) => [f.key, p?.features[f.key] ?? false]))
}

async function savePlan() {
  errors.value = {}
  const body = {
    name: form.name, description: form.description || null, active: form.active,
    prices: Object.fromEntries(Object.entries(form.prices).map(([k, v]) => [k, v.trim() || null])),
    features: { ...form.features },
  }
  try {
    if (editing.value === 'new') await adminBillingApi.createPlan({ slug: form.slug, ...body })
    else await adminBillingApi.updatePlan(editing.value!, body)
    say(true, 'Plano salvo. O preço novo vale para as próximas faturas.')
    editing.value = null
    await loadPlans()
  } catch (e) {
    const d = describeError(e)
    errors.value = d.fields ?? {}
    say(false, d.message)
  }
}

/* ---------- Conta Mercado Pago da SerTec ---------- */
const provider = ref<BillingProviderSettings | null>(null)
const mp = reactive({ token: '', secret: '' })
const savingMp = ref(false)

async function saveProvider() {
  savingMp.value = true
  try {
    provider.value = await adminBillingApi.saveSettings({ access_token: mp.token || null, webhook_secret: mp.secret || null })
    mp.token = ''
    mp.secret = ''
    say(true, 'Conta Mercado Pago da SerTec conectada.')
  } catch (e) {
    const d = describeError(e)
    say(false, d.fields?.access_token?.[0] ?? d.fields?.webhook_secret?.[0] ?? d.message)
  } finally {
    savingMp.value = false
  }
}

const confirmDisconnect = ref(false)
async function disconnect() {
  if (!confirmDisconnect.value) {
    confirmDisconnect.value = true
    return
  }
  confirmDisconnect.value = false
  provider.value = await adminBillingApi.disconnect()
  say(true, 'Conta desconectada.')
}

const state = ref<'loading' | 'ready' | 'error'>('loading')
onMounted(async () => {
  try {
    await Promise.all([loadInvoices(), loadPlans(), adminBillingApi.settings().then((s) => (provider.value = s))])
    state.value = 'ready'
  } catch {
    state.value = 'error'
  }
})

const FILTERS = [
  { value: 'open', label: 'Em aberto' },
  { value: 'overdue', label: 'Atrasadas' },
  { value: 'paid', label: 'Pagas' },
  { value: 'cancelled', label: 'Canceladas' },
] as const
</script>

<template>
  <h1 class="mb-2 text-2xl font-bold">Cobrança</h1>
  <p class="mb-6 max-w-[65ch] text-muted">Mensalidades dos restaurantes, pagas por Pix na conta Mercado Pago da SerTec.</p>

  <div role="tablist" aria-label="Cobrança" class="mb-6 flex gap-1 border-b border-line">
    <button
      v-for="t in [{ k: 'invoices', l: 'Faturas' }, { k: 'plans', l: 'Planos' }, { k: 'provider', l: 'Mercado Pago' }] as const" :key="t.k" type="button" role="tab" :aria-selected="tab === t.k"
      class="-mb-px border-b-2 px-4 py-2 text-sm font-semibold" :class="tab === t.k ? 'border-brand-600 text-brand-700' : 'border-transparent text-muted hover:text-ink'" @click="tab = t.k; message = null"
    >
      {{ t.l }}
    </button>
  </div>

  <p v-if="message" role="status" class="mb-4 max-w-3xl rounded-(--radius-control) p-3 text-sm" :class="message.ok ? 'bg-brand-50' : 'bg-danger-soft text-danger'">{{ message.text }}</p>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'error'" state="error" />

  <!-- Faturas -->
  <section v-else-if="tab === 'invoices'" aria-label="Faturas">
    <dl v-if="totals" class="mb-5 grid max-w-3xl grid-cols-3 gap-3">
      <div class="rounded-(--radius-card) border border-line bg-surface p-4"><dt class="text-sm text-muted">Em aberto</dt><dd class="text-xl font-bold tabular-nums">{{ formatMoney(totals.open_total) }}</dd></div>
      <div class="rounded-(--radius-card) border border-line bg-surface p-4"><dt class="text-sm text-muted">Atrasado</dt><dd class="text-xl font-bold text-danger tabular-nums">{{ formatMoney(totals.overdue_total) }}</dd></div>
      <div class="rounded-(--radius-card) border border-line bg-surface p-4"><dt class="text-sm text-muted">Recebido no mês</dt><dd class="text-xl font-bold text-success tabular-nums">{{ formatMoney(totals.paid_month) }}</dd></div>
    </dl>
    <label class="mb-4 inline-flex items-center gap-2 text-sm">Mostrar
      <select v-model="filter" class="rounded-lg border border-line bg-surface px-3 py-2">
        <option v-for="f in FILTERS" :key="f.value" :value="f.value">{{ f.label }}</option>
      </select>
    </label>
    <SPageState v-if="invState === 'loading'" state="loading" />
    <p v-else-if="!invoices.length" class="text-sm text-muted">Nenhuma fatura neste filtro.</p>
    <div v-else class="overflow-x-auto rounded-(--radius-card) border border-line bg-surface">
      <table class="w-full text-sm">
        <thead class="bg-canvas text-left text-muted">
          <tr><th class="px-4 py-2 font-medium">Estabelecimento</th><th class="px-4 py-2 font-medium">Período</th><th class="px-4 py-2 font-medium">Vencimento</th><th class="px-4 py-2 font-medium">Valor</th><th class="px-4 py-2 font-medium">Situação</th><th /></tr>
        </thead>
        <tbody class="divide-y divide-line">
          <tr v-for="i in invoices" :key="i.id">
            <td class="px-4 py-2.5"><RouterLink :to="`/estabelecimentos/${i.tenant.id}`" class="font-semibold hover:underline">{{ i.tenant.name }}</RouterLink></td>
            <td class="px-4 py-2.5">{{ formatDay(i.period_start) }} a {{ formatDay(i.period_end) }}</td>
            <td class="px-4 py-2.5" :class="i.overdue ? 'font-semibold text-danger' : ''">{{ formatDay(i.due_date) }}</td>
            <td class="px-4 py-2.5 tabular-nums">{{ formatMoney(i.amount) }}</td>
            <td class="px-4 py-2.5">
              <template v-if="i.status === 'paid'">Paga ({{ i.paid_via === 'pix' ? 'Pix' : 'manual' }})</template>
              <template v-else-if="i.status === 'cancelled'">Cancelada</template>
              <template v-else>{{ i.overdue ? 'Atrasada' : 'Em aberto' }}</template>
            </td>
            <td class="px-4 py-2.5 text-right whitespace-nowrap">
              <template v-if="superadmin && i.status === 'open'">
                <SButton variant="secondary" @click="ask(i, 'pay')">Dar baixa</SButton>
                <SButton variant="ghost" @click="ask(i, 'cancel')">Cancelar</SButton>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <form v-if="pending" class="mt-4 grid max-w-xl gap-3 rounded-(--radius-card) border border-brand-100 bg-surface p-4" aria-label="Confirmar" @submit.prevent="confirmPending">
      <p class="text-sm">
        {{ pending.action === 'pay' ? 'Dar baixa manual' : 'Cancelar a fatura' }} de <strong>{{ formatMoney(pending.invoice.amount) }}</strong> de {{ pending.invoice.tenant.name }}?
      </p>
      <STextField v-model="pending.note" :label="pending.action === 'pay' ? 'Observação' : 'Motivo'" />
      <div class="flex gap-3">
        <SButton type="submit">{{ pending.action === 'pay' ? 'Confirmar pagamento' : 'Cancelar fatura' }}</SButton>
        <SButton variant="ghost" @click="pending = null">Voltar</SButton>
      </div>
    </form>
  </section>

  <!-- Planos -->
  <section v-else-if="tab === 'plans'" aria-label="Planos" class="grid max-w-4xl gap-4">
    <div v-for="p in plans" :key="p.slug" class="rounded-(--radius-card) border border-line bg-surface p-5">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-lg font-semibold">{{ p.name }} <span v-if="!p.active" class="text-sm font-normal text-muted">(inativo)</span></h2>
          <p class="text-sm text-muted">{{ p.description ?? p.slug }} · {{ p.tenants }} estabelecimento(s)</p>
          <p class="mt-2 text-sm">
            <template v-for="per in meta?.periods ?? []" :key="per.months">
              <span class="mr-4">{{ per.label }}: <strong>{{ p.prices[String(per.months)] ? formatMoney(p.prices[String(per.months)]!) : '—' }}</strong></span>
            </template>
          </p>
        </div>
        <SButton v-if="superadmin" variant="secondary" @click="edit(p)">Editar</SButton>
      </div>
    </div>
    <div v-if="superadmin && editing === null"><SButton variant="secondary" @click="edit(null)">Novo plano</SButton></div>

    <form v-if="editing" class="grid gap-5 rounded-(--radius-card) border border-brand-100 bg-surface p-5" aria-label="Editar plano" @submit.prevent="savePlan">
      <h2 class="text-lg font-semibold">{{ editing === 'new' ? 'Novo plano' : `Editar ${form.name}` }}</h2>
      <div class="grid gap-4 sm:grid-cols-2">
        <STextField v-if="editing === 'new'" v-model="form.slug" label="Identificador" placeholder="plano-completo" :error="errors.slug?.[0]" />
        <STextField v-model="form.name" label="Nome do plano" :error="errors.name?.[0]" />
        <STextField v-model="form.description" label="Descrição curta" :error="errors.description?.[0]" />
      </div>
      <fieldset>
        <legend class="mb-2 text-sm font-medium">Preço por período (deixe vazio se não oferece)</legend>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <STextField v-for="per in meta?.periods ?? []" :key="per.months" v-model="form.prices[String(per.months)]!" :label="`${per.label} (R$)`" inputmode="decimal" placeholder="0,00" :error="errors[`prices.${per.months}`]?.[0]" />
        </div>
      </fieldset>
      <fieldset>
        <legend class="mb-2 text-sm font-medium">Recursos incluídos</legend>
        <div class="grid gap-2 sm:grid-cols-3">
          <label v-for="f in meta?.features ?? []" :key="f.key" class="flex items-center gap-2 text-sm">
            <input v-model="form.features[f.key]" type="checkbox" class="size-4"> {{ f.label }}
          </label>
        </div>
      </fieldset>
      <label class="flex items-center gap-2 text-sm"><input v-model="form.active" type="checkbox" class="size-4"> Plano ativo (pode ser escolhido)</label>
      <div class="flex gap-3"><SButton type="submit">Salvar plano</SButton><SButton variant="ghost" @click="editing = null">Cancelar</SButton></div>
    </form>
  </section>

  <!-- Mercado Pago da SerTec -->
  <section v-else-if="provider" aria-label="Mercado Pago da SerTec" class="grid max-w-2xl gap-4 rounded-(--radius-card) border border-line bg-surface p-5">
    <h2 class="text-lg font-semibold">Conta que recebe as mensalidades</h2>
    <dl class="grid grid-cols-[9rem_1fr] gap-x-4 gap-y-2 text-sm">
      <dt class="text-muted">Situação</dt>
      <dd :class="provider.connected ? 'font-semibold text-success' : 'font-semibold text-danger'">{{ provider.connected ? (provider.mode === 'test' ? 'Conectada (teste)' : 'Conectada') : 'Não conectada' }}</dd>
      <template v-if="provider.connected">
        <dt class="text-muted">Conta</dt><dd>{{ provider.account_label }} ({{ provider.account_id }})</dd>
        <dt class="text-muted">Token</dt><dd>…{{ provider.token_hint }}</dd>
        <dt class="text-muted">Assinatura secreta</dt><dd>{{ provider.has_webhook_secret ? 'Configurada' : 'Não configurada' }}</dd>
      </template>
      <dt class="text-muted">Webhook</dt><dd class="break-all"><code>{{ provider.webhook_url }}</code></dd>
    </dl>
    <SAlert v-if="provider.connected && !provider.has_webhook_secret">Cadastre o webhook acima no Mercado Pago (evento Pagamentos) e cole aqui a assinatura secreta. Sem ela, a confirmação depende da conferência automática a cada hora.</SAlert>
    <form v-if="superadmin" class="grid gap-4" aria-label="Conectar conta" @submit.prevent="saveProvider">
      <STextField v-model="mp.token" label="Access Token de produção" type="password" autocomplete="off" :placeholder="provider.connected ? 'Deixe vazio para manter o atual' : 'APP_USR-…'" />
      <STextField v-model="mp.secret" label="Assinatura secreta do webhook" type="password" autocomplete="off" :placeholder="provider.has_webhook_secret ? 'Deixe vazio para manter a atual' : ''" />
      <div class="flex gap-3">
        <SButton type="submit" :loading="savingMp">{{ provider.connected ? 'Atualizar' : 'Conectar conta' }}</SButton>
        <SButton v-if="provider.connected" variant="ghost" @click="disconnect">{{ confirmDisconnect ? 'Confirmar: restaurantes ficam sem Pix' : 'Desconectar' }}</SButton>
      </div>
    </form>
  </section>
</template>
