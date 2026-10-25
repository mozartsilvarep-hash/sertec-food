<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { formatMoney, type PaymentMethod, type PublicPayment } from '@sertec/api-client'
import { SButton } from '@sertec/ui'

/**
 * Cobrança online do pedido. O status pago NUNCA vem daqui: "Já paguei" só
 * pede ao servidor para consultar o Mercado Pago.
 */
const props = defineProps<{ payment: PublicPayment; method: PaymentMethod; busy: boolean }>()
const emit = defineEmits<{ refresh: [] }>()

const now = ref(Date.now())
const copied = ref(false)
let clock: ReturnType<typeof setInterval> | undefined

const remaining = computed(() => Math.max(0, Math.floor((new Date(props.payment.expires_at).getTime() - now.value) / 1000)))
const countdown = computed(() => {
  const m = Math.floor(remaining.value / 60)
  const s = remaining.value % 60
  return `${m}:${String(s).padStart(2, '0')}`
})
const expired = computed(() => remaining.value === 0 || !props.payment.payable)

async function copy() {
  if (!props.payment.pix) return
  try {
    await navigator.clipboard.writeText(props.payment.pix.qr_code)
  } catch {
    // Navegadores sem permissão: o código continua selecionável no campo.
    document.querySelector<HTMLTextAreaElement>('#pix-code')?.select()
  }
  copied.value = true
  setTimeout(() => (copied.value = false), 2500)
}

onMounted(() => (clock = setInterval(() => (now.value = Date.now()), 1000)))
onBeforeUnmount(() => clearInterval(clock))
</script>

<template>
  <section class="mt-6 rounded-(--radius-card) border-2 border-brand-600 bg-surface p-5" aria-labelledby="pay-title">
    <div class="flex items-baseline justify-between gap-3">
      <h3 id="pay-title" class="font-display text-xl font-bold">
        {{ method === 'pix' ? 'Pague com Pix' : 'Pague com cartão' }}
      </h3>
      <p class="font-display text-xl font-bold tabular-nums">{{ formatMoney(payment.amount) }}</p>
    </div>

    <p v-if="expired" class="mt-3 text-danger">O prazo para pagar acabou.</p>

    <template v-else-if="payment.needs_start">
      <p class="mt-3 text-muted">Não conseguimos gerar a cobrança agora.</p>
      <SButton class="mt-4" block :loading="busy" @click="emit('refresh')">Tentar de novo</SButton>
    </template>

    <template v-else-if="method === 'pix' && payment.pix">
      <p class="mt-1 text-sm text-muted" aria-live="off">
        Expira em <strong class="font-semibold text-ink tabular-nums">{{ countdown }}</strong>. O restaurante recebe o pedido assim que o Pix cair.
      </p>
      <img
        v-if="payment.pix.qr_code_base64"
        :src="`data:image/png;base64,${payment.pix.qr_code_base64}`"
        alt="QR Code do Pix"
        class="mx-auto mt-4 size-56 rounded-lg border border-line bg-white p-2 [image-rendering:pixelated]"
        width="224" height="224"
      >
      <label for="pix-code" class="mt-4 block text-sm font-medium">Pix copia e cola</label>
      <textarea
        id="pix-code"
        readonly
        rows="3"
        class="mt-1.5 w-full resize-none rounded-(--radius-control) border border-line bg-canvas p-3 font-mono text-xs break-all"
        :value="payment.pix.qr_code"
        @focus="($event.target as HTMLTextAreaElement).select()"
      />
      <SButton class="mt-3" block @click="copy">{{ copied ? 'Código copiado!' : 'Copiar código Pix' }}</SButton>
      <span class="sr-only" aria-live="polite">{{ copied ? 'Código Pix copiado' : '' }}</span>
      <ol class="mt-4 grid gap-1 text-sm text-muted">
        <li>1. Abra o app do seu banco e escolha Pix copia e cola (ou ler QR Code).</li>
        <li>2. Cole o código e confirme o pagamento.</li>
        <li>3. Volte aqui: esta página atualiza sozinha.</li>
      </ol>
    </template>

    <template v-else-if="payment.checkout_url">
      <p v-if="payment.rejected" class="mt-3 text-danger">O pagamento não foi aprovado. Tente outro cartão.</p>
      <p v-else class="mt-1 text-sm text-muted">
        Você será levado ao ambiente seguro do Mercado Pago. Prazo: <strong class="font-semibold text-ink tabular-nums">{{ countdown }}</strong>.
      </p>
      <a
        :href="payment.checkout_url"
        class="mt-4 flex min-h-12 items-center justify-center rounded-(--radius-control) bg-brand-600 px-5 font-semibold text-brand-contrast hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
      >{{ payment.rejected ? 'Tentar outro cartão' : 'Pagar com cartão' }}</a>
    </template>

    <button
      v-if="!expired && !payment.needs_start"
      type="button"
      class="mt-3 min-h-11 w-full rounded-(--radius-control) font-semibold text-brand-700 hover:bg-brand-50 disabled:opacity-60"
      :disabled="busy"
      @click="emit('refresh')"
    >
      {{ busy ? 'Conferindo…' : 'Já paguei' }}
    </button>
  </section>
</template>
