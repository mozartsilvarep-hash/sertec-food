<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import {
  adminBillingApi, centsToString, describeError, formatDay, formatMoney, toCents,
  type AdminModule, type AdminPlan, type ModuleKey, type PlanMeta, type TenantBilling,
} from '@sertec/api-client'
import { SButton, SPageState, STextField } from '@sertec/ui'

const props = defineProps<{ tenantId: string; superadmin: boolean }>()
const emit = defineEmits<{ changed: [] }>()

const billing = ref<TenantBilling | null>(null)
const plans = ref<AdminPlan[]>([])
const meta = ref<PlanMeta | null>(null)
const modules = ref<AdminModule[]>([])
const state = ref<'loading' | 'ready' | 'error'>('loading')
const form = reactive({ plan: '', months: 1, price: '', paid_until: '', modules: [] as ModuleKey[], auto_block: true })
const errors = ref<Record<string, string[]>>({})
const message = ref<{ ok: boolean; text: string } | null>(null)
const busy = ref(false)

const STATUS: Record<string, string> = { active: 'Em dia', past_due: 'Em atraso', trialing: 'Teste', cancelled: 'Cancelada' }
const selected = computed(() => plans.value.find((p) => p.slug === form.plan) ?? null)
const planPrice = computed(() => selected.value?.prices[String(form.months)] ?? null)
const modulePrice = (m: AdminModule) => m.prices[String(form.months)] ?? null
/** Estimativa: plano (se tiver preço) + módulos marcados no período escolhido. */
const estimate = computed(() => {
  const parts = [planPrice.value, ...modules.value.filter((m) => form.modules.includes(m.key)).map(modulePrice)].filter((v): v is string => !!v && Number(v) > 0)
  return parts.length ? centsToString(parts.reduce((s, v) => s + toCents(v), 0)) : null
})
const unpriced = computed(() => modules.value.filter((m) => form.modules.includes(m.key) && !modulePrice(m)).map((m) => m.name))
function toggle(m: AdminModule, on: boolean) {
  const set = new Set(form.modules)
  if (on) {
    set.add(m.key)
    if (m.requires) set.add(m.requires)
  } else {
    set.delete(m.key)
    // Sem o PDV, mesas não funcionam.
    for (const x of modules.value) if (x.requires === m.key) set.delete(x.key)
  }
  form.modules = modules.value.map((x) => x.key).filter((k) => set.has(k))
}

function fill(b: TenantBilling) {
  billing.value = b
  form.plan = b.subscription?.plan.slug ?? plans.value[0]?.slug ?? ''
  form.months = b.subscription?.billing_months ?? 1
  form.price = b.subscription?.custom_price?.replace('.', ',') ?? ''
  form.paid_until = b.subscription?.paid_until ?? ''
  form.modules = b.subscription ? [...b.subscription.modules] : ['menu']
  form.auto_block = b.subscription?.auto_block ?? true
}

async function load() {
  state.value = 'loading'
  try {
    const [b, p, m] = await Promise.all([adminBillingApi.tenant(props.tenantId), adminBillingApi.plans(), adminBillingApi.modules()])
    plans.value = p.data
    meta.value = p.meta
    modules.value = m.data
    fill(b)
    state.value = 'ready'
  } catch {
    state.value = 'error'
  }
}

async function run(fn: () => Promise<TenantBilling>, ok: string) {
  busy.value = true
  errors.value = {}
  message.value = null
  try {
    fill(await fn())
    message.value = { ok: true, text: ok }
    emit('changed')
  } catch (e) {
    const d = describeError(e)
    errors.value = d.fields ?? {}
    message.value = { ok: false, text: d.message }
  } finally {
    busy.value = false
  }
}

const save = () => run(() => adminBillingApi.updateTenant(props.tenantId, {
  plan: form.plan, billing_months: form.months, price: form.price.trim() || null, paid_until: form.paid_until || null, modules: form.modules, auto_block: form.auto_block,
}), 'Mensalidade atualizada')
const generate = () => run(() => adminBillingApi.createInvoice(props.tenantId), 'Fatura gerada')
const pay = (id: string) => run(() => adminBillingApi.payInvoice(id, 'Baixa manual no painel SerTec'), 'Fatura marcada como paga')

onMounted(load)
</script>

<template>
  <section class="rounded-(--radius-card) border border-line bg-surface p-5 lg:col-span-2" aria-labelledby="h-mensalidade">
    <h2 id="h-mensalidade" class="mb-3 font-semibold">Mensalidade</h2>
    <SPageState v-if="state === 'loading'" state="loading" />
    <SPageState v-else-if="state === 'error'" state="error" @retry="load" />
    <template v-else-if="billing">
      <p v-if="billing.subscription" class="mb-4 text-sm">
        <strong>{{ billing.subscription.lines.map((l) => l.label).join(' + ') || billing.subscription.plan.name }}</strong> · {{ billing.subscription.period_label }} ·
        {{ billing.subscription.price === null ? 'sem preço' : Number(billing.subscription.price) > 0 ? formatMoney(billing.subscription.price) : 'cortesia' }} ·
        <span :class="billing.subscription.status === 'past_due' ? 'font-semibold text-danger' : 'text-success'">{{ STATUS[billing.subscription.status] }}</span>
        <template v-if="billing.subscription.paid_until"> · pago até {{ formatDay(billing.subscription.paid_until) }}</template>
      </p>
      <p v-if="billing.subscription?.blocked_at" class="mb-4 rounded-(--radius-control) bg-danger-soft p-3 text-sm font-semibold text-danger" role="alert">
        Bloqueado por atraso desde {{ new Date(billing.subscription.blocked_at).toLocaleDateString('pt-BR') }}. Libera sozinho quando a fatura for paga (ou dê baixa / cancele abaixo).
      </p>
      <p v-else-if="billing.subscription?.block_date" class="mb-4 text-sm text-danger">Se não pagar, bloqueia em {{ formatDay(billing.subscription.block_date) }}.</p>
      <p v-else class="mb-4 text-sm text-muted">Sem plano ativo.</p>

      <p v-if="message" role="status" class="mb-4 rounded-(--radius-control) p-3 text-sm" :class="message.ok ? 'bg-brand-50' : 'bg-danger-soft text-danger'">{{ message.text }}</p>

      <form v-if="superadmin" class="mb-5 grid gap-4 sm:grid-cols-4" aria-label="Mensalidade do estabelecimento" @submit.prevent="save">
        <fieldset class="min-w-0 sm:col-span-4">
          <legend class="mb-2 text-sm font-medium">Módulos contratados</legend>
          <div class="grid gap-2 sm:grid-cols-2">
            <label v-for="m in modules" :key="m.key" class="flex min-w-0 items-start gap-3 rounded-(--radius-control) border p-3 text-sm" :class="form.modules.includes(m.key) ? 'border-brand-600 bg-brand-50' : 'border-line'">
              <input type="checkbox" class="mt-0.5 size-4 shrink-0" :aria-label="m.name" :checked="form.modules.includes(m.key)" @change="toggle(m, ($event.target as HTMLInputElement).checked)">
              <span class="min-w-0">
                <span class="block font-semibold">{{ m.name }}</span>
                <span class="block text-xs text-muted">{{ modulePrice(m) ? formatMoney(modulePrice(m)!) : 'sem preço neste período' }}<template v-if="m.requires"> · inclui {{ modules.find((x) => x.key === m.requires)?.name }}</template></span>
              </span>
            </label>
          </div>
        </fieldset>
        <label class="grid gap-1.5 text-sm font-medium">Ciclo
          <select v-model.number="form.months" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3">
            <option v-for="per in meta?.periods ?? []" :key="per.months" :value="per.months">{{ per.label }}</option>
          </select>
        </label>
        <label class="grid gap-1.5 text-sm font-medium">Plano (recursos extras)
          <select v-model="form.plan" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3">
            <option v-for="p in plans" :key="p.slug" :value="p.slug">{{ p.name }}{{ p.prices[String(form.months)] ? ` · ${formatMoney(p.prices[String(form.months)]!)}` : '' }}</option>
          </select>
        </label>
        <STextField v-model="form.price" label="Valor próprio (R$)" inputmode="decimal" :placeholder="estimate ? `Soma: ${estimate.replace('.', ',')}` : '0 = cortesia'" :error="errors.price?.[0]" />
        <label class="grid gap-1.5 text-sm font-medium">Pago até
          <input v-model="form.paid_until" type="date" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3">
        </label>
        <label class="flex items-center gap-2 text-sm sm:col-span-4">
          <input v-model="form.auto_block" type="checkbox" class="size-4"> Bloquear sozinho 7 dias depois do vencimento (avisa por e-mail antes)
        </label>
        <p class="text-sm sm:col-span-4" role="status">
          {{ form.price.trim() ? 'Valor próprio' : 'Total' }} ({{ (meta?.periods.find((p) => p.months === form.months)?.label ?? '').toLowerCase() }}):
          <strong class="tabular-nums">{{ form.price.trim() ? formatMoney(form.price.replace(',', '.')) : estimate ? formatMoney(estimate) : 'sem preço' }}</strong>
          <span v-if="unpriced.length && !form.price.trim()" class="block text-xs text-danger">Sem preço neste período (entra sem custo): {{ unpriced.join(', ') }}. Defina em Cobrança › Módulos e preços.</span>
        </p>
        <div class="flex flex-wrap gap-3 sm:col-span-4">
          <SButton type="submit" :loading="busy">Salvar mensalidade</SButton>
          <SButton v-if="billing.subscription" variant="secondary" :disabled="busy" @click="generate">Gerar cobrança agora</SButton>
        </div>
      </form>

      <p v-if="!billing.invoices.length" class="text-sm text-muted">Nenhuma fatura ainda.</p>
      <ul v-else class="divide-y divide-line text-sm" aria-label="Faturas do estabelecimento">
        <li v-for="i in billing.invoices" :key="i.id" class="flex flex-wrap items-center justify-between gap-3 py-2.5">
          <span>
            {{ formatDay(i.period_start) }} a {{ formatDay(i.period_end) }} · <strong class="tabular-nums">{{ formatMoney(i.amount) }}</strong> ·
            <template v-if="i.status === 'paid'"><span class="text-success">paga {{ i.paid_via === 'pix' ? 'por Pix' : 'manualmente' }}</span></template>
            <template v-else-if="i.status === 'cancelled'"><span class="text-muted">cancelada</span></template>
            <template v-else><span :class="i.overdue ? 'font-semibold text-danger' : ''">vence {{ formatDay(i.due_date) }}</span></template>
          </span>
          <SButton v-if="superadmin && i.status === 'open'" variant="secondary" :disabled="busy" @click="pay(i.id)">Dar baixa</SButton>
        </li>
      </ul>
    </template>
  </section>
</template>
