<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { formatDateTime, formatMoney, formatTotal, merchantOrdersApi, type MerchantOrder } from '@sertec/api-client'
import { SPageState } from '@sertec/ui'
import { me } from '../session'
import { readPref, writePref } from '../storage'

/**
 * Comanda para impressora térmica (58 ou 80 mm). Para imprimir sem a janela
 * de confirmação, use o Chrome com --kiosk-printing e a térmica como padrão.
 */
const props = defineProps<{ id: string; kind?: 'ticket' | 'receipt' }>()
const receipt = computed(() => props.kind === 'receipt')
const FULFILLMENT: Record<string, string> = { delivery: 'Entrega', pickup: 'Retirada', counter: 'Balcão' }
const router = useRouter()
const order = ref<MerchantOrder | null>(null)
const failed = ref(false)
const width = ref<'58' | '80'>(readPref('print:width') === '58' ? '58' : '80')
const tz = computed(() => me.value?.tenant.timezone ?? 'America/Sao_Paulo')

function setWidth(w: '58' | '80') {
  width.value = w
  writePref('print:width', w)
}

async function print() {
  await nextTick()
  window.print()
}

onMounted(async () => {
  try {
    order.value = await merchantOrdersApi.get(props.id)
    await print()
  } catch {
    failed.value = true
  }
})
</script>

<template>
  <div class="min-h-dvh bg-canvas p-4 print:bg-white print:p-0">
    <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
      <button type="button" class="rounded-lg border border-line bg-surface px-3 py-2 text-sm font-medium" @click="router.back()">‹ Voltar</button>
      <button type="button" class="rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-brand-contrast" @click="print">Imprimir</button>
      <span class="ml-2 text-sm text-muted">Papel:</span>
      <button v-for="w in (['80', '58'] as const)" :key="w" type="button" class="rounded-lg border px-3 py-2 text-sm" :class="width === w ? 'border-ink bg-ink text-white' : 'border-line bg-surface'" :aria-pressed="width === w" @click="setWidth(w)">{{ w }} mm</button>
    </div>

    <SPageState v-if="failed" state="error" title="Pedido não encontrado" />
    <SPageState v-else-if="!order" state="loading" />

    <article v-else class="ticket mx-auto bg-white font-mono text-black shadow print:shadow-none" :class="width === '58' ? 'w-[58mm] text-[11px]' : 'w-[80mm] text-[12px]'" :aria-label="receipt ? 'Recibo' : 'Comanda'">
      <header class="border-b border-dashed border-black pb-2 text-center">
        <p class="text-[1.15em] font-bold">{{ me?.tenant.name }}</p>
        <p>{{ order.branch.name }}</p>
        <p class="mt-1 text-[2em] leading-none font-bold">#{{ order.number }}</p>
        <p class="font-bold uppercase">{{ receipt ? 'Recibo de venda' : FULFILLMENT[order.fulfillment_type] }}</p>
        <p>{{ formatDateTime(order.placed_at, tz) }}</p>
      </header>

      <section v-if="!(receipt && order.customer.name === 'Cliente balcão')" class="border-b border-dashed border-black py-2">
        <p class="font-bold">{{ order.customer.name }}</p>
        <p v-if="order.customer.phone">{{ order.customer.phone }}</p>
        <template v-if="order.fulfillment_type === 'delivery' && order.address">
          <p>{{ order.address.street }}, {{ order.address.number }}<template v-if="order.address.complement"> - {{ order.address.complement }}</template></p>
          <p>{{ order.address.neighborhood }}</p>
          <p v-if="order.address.reference">Ref.: {{ order.address.reference }}</p>
        </template>
      </section>

      <section class="border-b border-dashed border-black py-2">
        <div v-for="(item, i) in order.items ?? []" :key="i" class="mb-1.5">
          <p class="flex justify-between gap-2 font-bold"><span class="min-w-0">{{ item.quantity }}x {{ item.name }}<template v-if="item.variant && item.variant !== 'Única' && item.variant !== 'Único'"> ({{ item.variant }})</template></span><span class="shrink-0 whitespace-nowrap">{{ formatMoney(item.line_total) }}</span></p>
          <p v-for="(opt, j) in item.options" :key="j" class="pl-3">- {{ opt.quantity > 1 ? `${opt.quantity}x ` : '' }}{{ opt.name }}</p>
          <p v-if="item.notes" class="pl-3 font-bold">OBS: {{ item.notes }}</p>
        </div>
      </section>

      <section class="border-b border-dashed border-black py-2">
        <p v-for="t in order.totals ?? []" :key="t.code" class="flex justify-between" :class="t.code === 'total' && 'text-[1.15em] font-bold'"><span>{{ t.label }}</span><span>{{ formatTotal(t) }}</span></p>
      </section>

      <section class="py-2">
        <template v-if="order.pos_payments?.length">
          <p v-for="(p, i) in order.pos_payments" :key="i" class="flex justify-between font-bold"><span>{{ p.label }}</span><span>{{ formatMoney(p.amount) }}</span></p>
          <p v-if="order.change_given && Number(order.change_given) > 0" class="flex justify-between"><span>Troco</span><span>{{ formatMoney(order.change_given) }}</span></p>
          <p v-if="order.seller" class="mt-1">Atendente: {{ order.seller }}</p>
        </template>
        <p v-else class="font-bold">Pagamento: {{ order.payment_method_label }}</p>
        <p v-if="order.online_payment?.status === 'approved'" class="font-bold">*** PAGO PELO SITE ***</p>
        <p v-if="order.change_for">Troco para {{ formatMoney(order.change_for) }}</p>
        <p v-if="order.notes" class="mt-1 font-bold">OBS DO PEDIDO: {{ order.notes }}</p>
      </section>
      <footer v-if="receipt" class="border-t border-dashed border-black pt-2 text-center">
        <p>Obrigado pela preferência!</p>
        <p class="mt-1 text-[0.9em]">Não é documento fiscal</p>
      </footer>
    </article>
  </div>
</template>

<style>
@media print {
  @page { margin: 0; size: auto; }
  .ticket { padding: 2mm; }
}
.ticket { padding: 4mm; }
</style>
