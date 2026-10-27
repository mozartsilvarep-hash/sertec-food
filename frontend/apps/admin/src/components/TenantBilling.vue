<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { adminBillingApi, describeError, formatDay, formatMoney, type AdminPlan, type PlanMeta, type TenantBilling } from '@sertec/api-client'
import { SButton, SPageState, STextField } from '@sertec/ui'

const props = defineProps<{ tenantId: string; superadmin: boolean }>()
const emit = defineEmits<{ changed: [] }>()

const billing = ref<TenantBilling | null>(null)
const plans = ref<AdminPlan[]>([])
const meta = ref<PlanMeta | null>(null)
const state = ref<'loading' | 'ready' | 'error'>('loading')
const form = reactive({ plan: '', months: 1, price: '', paid_until: '' })
const errors = ref<Record<string, string[]>>({})
const message = ref<{ ok: boolean; text: string } | null>(null)
const busy = ref(false)

const STATUS: Record<string, string> = { active: 'Em dia', past_due: 'Em atraso', trialing: 'Teste', cancelled: 'Cancelada' }
const selected = computed(() => plans.value.find((p) => p.slug === form.plan) ?? null)
const planPrice = computed(() => selected.value?.prices[String(form.months)] ?? null)

function fill(b: TenantBilling) {
  billing.value = b
  form.plan = b.subscription?.plan.slug ?? plans.value[0]?.slug ?? ''
  form.months = b.subscription?.billing_months ?? 1
  form.price = b.subscription?.custom_price?.replace('.', ',') ?? ''
  form.paid_until = b.subscription?.paid_until ?? ''
}

async function load() {
  state.value = 'loading'
  try {
    const [b, p] = await Promise.all([adminBillingApi.tenant(props.tenantId), adminBillingApi.plans()])
    plans.value = p.data
    meta.value = p.meta
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
  plan: form.plan, billing_months: form.months, price: form.price.trim() || null, paid_until: form.paid_until || null,
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
        <strong>{{ billing.subscription.plan.name }}</strong> · {{ billing.subscription.period_label }} ·
        {{ billing.subscription.price === null ? 'sem preço' : Number(billing.subscription.price) > 0 ? formatMoney(billing.subscription.price) : 'cortesia' }} ·
        <span :class="billing.subscription.status === 'past_due' ? 'font-semibold text-danger' : 'text-success'">{{ STATUS[billing.subscription.status] }}</span>
        <template v-if="billing.subscription.paid_until"> · pago até {{ formatDay(billing.subscription.paid_until) }}</template>
      </p>
      <p v-else class="mb-4 text-sm text-muted">Sem plano ativo.</p>

      <p v-if="message" role="status" class="mb-4 rounded-(--radius-control) p-3 text-sm" :class="message.ok ? 'bg-brand-50' : 'bg-danger-soft text-danger'">{{ message.text }}</p>

      <form v-if="superadmin" class="mb-5 grid gap-4 sm:grid-cols-4" aria-label="Mensalidade do estabelecimento" @submit.prevent="save">
        <label class="grid gap-1.5 text-sm font-medium">Plano
          <select v-model="form.plan" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3">
            <option v-for="p in plans" :key="p.slug" :value="p.slug">{{ p.name }}</option>
          </select>
        </label>
        <label class="grid gap-1.5 text-sm font-medium">Período
          <select v-model.number="form.months" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3">
            <option v-for="per in meta?.periods ?? []" :key="per.months" :value="per.months">{{ per.label }}{{ selected?.prices[String(per.months)] ? ` · ${formatMoney(selected.prices[String(per.months)]!)}` : '' }}</option>
          </select>
        </label>
        <STextField v-model="form.price" label="Valor próprio (R$)" inputmode="decimal" :placeholder="planPrice ? `Plano: ${planPrice.replace('.', ',')}` : '0 = cortesia'" :error="errors.price?.[0]" />
        <label class="grid gap-1.5 text-sm font-medium">Pago até
          <input v-model="form.paid_until" type="date" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3">
        </label>
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
