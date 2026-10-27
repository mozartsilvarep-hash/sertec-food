<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { cashApi, formatDateTime, formatMoney, type CashSessionReport } from '@sertec/api-client'
import { SPageState } from '@sertec/ui'
import { me } from '../session'
import { readPref, writePref } from '../storage'

/** Fechamento (ou parcial) do caixa para impressora térmica de 80 ou 58 mm. */
const props = defineProps<{ id: string }>()
const router = useRouter()
const report = ref<CashSessionReport | null>(null)
const failed = ref(false)
const width = ref<'58' | '80'>(readPref('print:width') === '58' ? '58' : '80')
const tz = computed(() => me.value?.tenant.timezone ?? 'America/Sao_Paulo')
const printedAt = new Date().toISOString()

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
    report.value = await cashApi.sessionReport(props.id)
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

    <SPageState v-if="failed" state="error" title="Caixa não encontrado" />
    <SPageState v-else-if="!report" state="loading" />

    <article v-else class="ticket mx-auto bg-white font-mono text-black shadow print:shadow-none" :class="width === '58' ? 'w-[58mm] text-[11px]' : 'w-[80mm] text-[12px]'" aria-label="Fechamento do caixa">
      <header class="border-b border-dashed border-black pb-2 text-center">
        <p class="text-[1.15em] font-bold">{{ me?.tenant.name }}</p>
        <p>{{ report.branch.name }}</p>
        <p class="mt-1 font-bold uppercase">{{ report.open ? 'Parcial do caixa' : 'Fechamento do caixa' }}</p>
        <p>Abertura: {{ formatDateTime(report.opened_at, tz) }}<template v-if="report.opened_by"> ({{ report.opened_by }})</template></p>
        <p v-if="report.closed_at">Fechamento: {{ formatDateTime(report.closed_at, tz) }}<template v-if="report.closed_by"> ({{ report.closed_by }})</template></p>
      </header>

      <section class="border-b border-dashed border-black py-2">
        <p class="flex justify-between"><span>Vendas ({{ report.sales_count }})</span><span>{{ formatMoney(report.sales) }}</span></p>
        <p class="flex justify-between"><span>Taxas cartão/Pix</span><span>− {{ formatMoney(report.fees) }}</span></p>
      </section>

      <section class="border-b border-dashed border-black py-2">
        <p class="font-bold">Por forma</p>
        <p v-for="m in report.by_method ?? []" :key="m.method" class="flex justify-between"><span>{{ m.label }}</span><span>{{ formatMoney(m.in) }}<template v-if="Number(m.out)"> / −{{ formatMoney(m.out) }}</template></span></p>
      </section>

      <section class="border-b border-dashed border-black py-2">
        <p class="font-bold">Movimentos</p>
        <p v-for="c in report.by_category" :key="c.category" class="flex justify-between gap-2"><span class="min-w-0">{{ c.label }} ({{ c.count }})</span><span class="shrink-0">{{ c.type === 'out' ? '−' : '' }}{{ formatMoney(c.total) }}</span></p>
        <p v-if="report.voided_count">Estornados: {{ report.voided_count }}</p>
      </section>

      <section class="border-b border-dashed border-black py-2">
        <p class="font-bold">Por operador</p>
        <p v-for="o in report.by_operator" :key="o.name" class="flex justify-between gap-2"><span class="min-w-0 truncate">{{ o.name }} ({{ o.count }})</span><span class="shrink-0">{{ formatMoney(o.in) }}</span></p>
      </section>

      <section class="py-2">
        <p class="flex justify-between"><span>Troco inicial</span><span>{{ formatMoney(report.opening_amount) }}</span></p>
        <p class="flex justify-between font-bold"><span>Dinheiro esperado</span><span>{{ formatMoney(report.expected_amount) }}</span></p>
        <template v-if="report.counted_amount !== null">
          <p class="flex justify-between"><span>Contado</span><span>{{ formatMoney(report.counted_amount) }}</span></p>
          <p class="flex justify-between text-[1.15em] font-bold"><span>{{ Number(report.difference) === 0 ? 'Conferido' : Number(report.difference) > 0 ? 'Sobra' : 'Falta' }}</span><span>{{ formatMoney(report.difference) }}</span></p>
        </template>
        <p v-if="report.notes" class="mt-1">Obs.: {{ report.notes }}</p>
      </section>

      <footer class="border-t border-dashed border-black pt-2 text-center text-[0.9em]">
        <p>Impresso em {{ formatDateTime(printedAt, tz) }}</p>
        <p class="mt-4">_________________________</p>
        <p>Assinatura do responsável</p>
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
