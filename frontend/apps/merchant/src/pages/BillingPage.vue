<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ApiError, describeError, formatDay, formatMoney, merchantBillingApi, type Invoice, type TenantBilling } from '@sertec/api-client'
import { SAlert, SButton, SPageState } from '@sertec/ui'
import { notify } from '../notify'
import { loadSession } from '../session'

const data = ref<(TenantBilling & { pix_available: boolean }) | null>(null)
const state = ref<'loading' | 'ready' | 'error' | 'offline'>('loading')
const paying = ref<Invoice | null>(null)
const generating = ref(false)
const error = ref<string | null>(null)
let timer: ReturnType<typeof setInterval> | null = null

const open = computed(() => data.value?.invoices.filter((i) => i.status === 'open') ?? [])
const history = computed(() => data.value?.invoices.filter((i) => i.status !== 'open') ?? [])
const sub = computed(() => data.value?.subscription ?? null)

const PER: Record<number, string> = { 1: 'por mês', 3: 'por trimestre', 6: 'por semestre', 12: 'por ano' }
const STATUS: Record<string, string> = { active: 'Em dia', past_due: 'Em atraso', trialing: 'Período de teste', cancelled: 'Cancelada' }

async function load() {
  state.value = 'loading'
  try {
    data.value = await merchantBillingApi.show()
    paying.value = open.value.find((i) => i.pix) ?? null
    if (paying.value) watchPayment()
    state.value = 'ready'
  } catch (e) {
    state.value = e instanceof ApiError && e.code === 'network_error' ? 'offline' : 'error'
  }
}

async function pay(invoice: Invoice) {
  generating.value = true
  error.value = null
  try {
    paying.value = await merchantBillingApi.pix(invoice.id)
    watchPayment()
  } catch (e) {
    error.value = describeError(e).message
  } finally {
    generating.value = false
  }
}

function watchPayment() {
  stop()
  timer = setInterval(async () => {
    if (!paying.value) return stop()
    try {
      const fresh = await merchantBillingApi.status(paying.value.id)
      if (fresh.status === 'paid') {
        stop()
        paying.value = null
        notify('Pagamento confirmado. Obrigado!')
        await Promise.all([load(), loadSession()])
      }
    } catch {
      // tenta de novo no próximo ciclo
    }
  }, 5000)
}

function stop() {
  if (timer) clearInterval(timer)
  timer = null
}

async function copy(code: string) {
  try {
    await navigator.clipboard.writeText(code)
    notify('Código Pix copiado')
  } catch {
    notify('Não foi possível copiar. Selecione o código e copie manualmente.', 'error')
  }
}

function period(i: Invoice) {
  return `${formatDay(i.period_start)} a ${formatDay(i.period_end)}`
}

onMounted(load)
onBeforeUnmount(stop)
</script>

<template>
  <h1 class="mb-6 font-display text-2xl font-bold">Assinatura</h1>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'offline'" state="offline" @retry="load" />
  <SPageState v-else-if="state === 'error'" state="error" @retry="load" />
  <div v-else-if="data" class="grid max-w-3xl gap-8">
    <section aria-labelledby="plano" class="rounded-2xl border border-line bg-surface p-5">
      <h2 id="plano" class="sr-only">Seu plano</h2>
      <template v-if="sub">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <p class="text-sm text-muted">Plano</p>
            <p class="font-display text-2xl font-bold">{{ sub.plan.name }}</p>
            <p class="mt-1 text-sm text-muted">
              {{ sub.period_label }}
              <template v-if="sub.price && Number(sub.price) > 0"> · {{ formatMoney(sub.price) }} {{ PER[sub.billing_months] ?? 'por período' }}</template>
              <template v-else-if="sub.price"> · cortesia SerTec</template>
            </p>
          </div>
          <p
            class="rounded-full px-3 py-1 text-sm font-semibold"
            :class="sub.status === 'past_due' ? 'bg-danger-soft text-danger' : 'bg-success/10 text-success'"
          >
            {{ STATUS[sub.status] ?? sub.status }}
          </p>
        </div>
        <p v-if="sub.paid_until" class="mt-4 text-sm">Pago até <strong>{{ formatDay(sub.paid_until) }}</strong>.</p>
      </template>
      <p v-else class="text-sm text-muted">Nenhum plano ativo. Fale com a equipe SerTec.</p>
    </section>

    <section v-if="open.length" aria-labelledby="aberto" class="grid gap-4">
      <h2 id="aberto" class="font-semibold">A pagar</h2>
      <SAlert v-if="error">{{ error }}</SAlert>
      <article
        v-for="i in open"
        :key="i.id"
        class="rounded-2xl border p-5"
        :class="i.overdue ? 'border-danger' : 'border-line'"
        :aria-label="`Fatura de ${formatMoney(i.amount)}`"
      >
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <p class="text-2xl font-bold tabular-nums">{{ formatMoney(i.amount) }}</p>
            <p class="text-sm text-muted">{{ i.plan_name }} · {{ period(i) }}</p>
            <p class="mt-1 text-sm font-semibold" :class="i.overdue ? 'text-danger' : ''">
              {{ i.overdue ? 'Venceu em' : 'Vence em' }} {{ formatDay(i.due_date) }}
            </p>
          </div>
          <SButton v-if="paying?.id !== i.id && data.pix_available" :loading="generating" @click="pay(i)">Pagar com Pix</SButton>
        </div>
        <p v-if="!data.pix_available" class="mt-3 text-sm text-muted">O pagamento por Pix ainda não está disponível. Fale com a equipe SerTec.</p>

        <div v-if="paying?.id === i.id && paying.pix" class="mt-5 grid gap-4 border-t border-line pt-5 sm:grid-cols-[12rem_1fr]" role="region" aria-label="Pix da mensalidade">
          <img v-if="paying.pix.qr_base64" :src="`data:image/png;base64,${paying.pix.qr_base64}`" alt="QR Code do Pix" class="size-48 rounded-lg border border-line bg-white p-2">
          <div class="grid content-start gap-3">
            <p class="text-sm">Abra o app do banco, escolha <strong>Pix copia e cola</strong> ou leia o QR Code. A confirmação aparece aqui sozinha.</p>
            <label class="grid gap-1.5 text-sm font-medium">
              Código Pix
              <textarea :value="paying.pix.code" readonly rows="3" class="rounded-(--radius-control) border border-line bg-canvas p-2 font-mono text-xs break-all" />
            </label>
            <div><SButton variant="secondary" @click="copy(paying.pix.code)">Copiar código</SButton></div>
            <p class="text-sm text-muted" aria-live="polite">Aguardando o pagamento…</p>
          </div>
        </div>
      </article>
    </section>
    <p v-else-if="sub" class="text-sm text-muted">Nenhuma fatura em aberto.</p>

    <section v-if="history.length" aria-labelledby="historico">
      <h2 id="historico" class="mb-3 font-semibold">Histórico</h2>
      <div class="relative overflow-x-auto rounded-xl border border-line">
        <table class="w-full text-sm">
          <thead class="bg-canvas text-left text-muted">
            <tr><th class="px-4 py-2 font-medium">Período</th><th class="px-4 py-2 font-medium">Valor</th><th class="px-4 py-2 font-medium">Situação</th></tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="i in history" :key="i.id">
              <td class="px-4 py-2.5">{{ period(i) }}</td>
              <td class="px-4 py-2.5 tabular-nums">{{ formatMoney(i.amount) }}</td>
              <td class="px-4 py-2.5">
                <template v-if="i.status === 'paid'">Paga em {{ new Date(i.paid_at!).toLocaleDateString('pt-BR') }}{{ i.paid_via === 'pix' ? ' (Pix)' : '' }}</template>
                <template v-else>Cancelada</template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
