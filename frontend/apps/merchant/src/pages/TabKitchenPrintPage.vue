<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { formatDateTime, tabsApi, type TabDetail } from '@sertec/api-client'
import { SPageState } from '@sertec/ui'
import { me } from '../session'
import { readPref, writePref } from '../storage'

/** Pedido da cozinha de uma mesa: só os itens do lote enviado (sem preços). */
const props = defineProps<{ id: string }>()
const route = useRoute()
const router = useRouter()
const tab = ref<TabDetail | null>(null)
const failed = ref(false)
const width = ref<'58' | '80'>(readPref('print:width') === '58' ? '58' : '80')
const tz = computed(() => me.value?.tenant.timezone ?? 'America/Sao_Paulo')
const batch = computed(() => Number(route.query.lote) || tab.value?.kitchen_batches || 0)
const items = computed(() => tab.value?.items.filter((i) => i.kitchen_batch === batch.value) ?? [])
const sentAt = computed(() => items.value[0]?.sent_at ?? null)

function setWidth(w: '58' | '80') {
  width.value = w
  writePref('print:width', w)
}
async function print() {
  await nextTick()
  window.print()
}
function back() {
  if (route.query.volta === 'pdv') void router.push({ name: 'pos', query: { mesa: props.id } })
  else void router.push({ name: 'tabs' })
}
onMounted(async () => {
  try {
    tab.value = await tabsApi.get(props.id)
    if (items.value.length) await print()
  } catch {
    failed.value = true
  }
})
</script>

<template>
  <div class="min-h-dvh bg-canvas p-4 print:bg-white print:p-0">
    <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
      <button type="button" class="rounded-lg border border-line bg-surface px-3 py-2 text-sm font-medium" @click="back">‹ Voltar</button>
      <button type="button" class="rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-brand-contrast" @click="print">Imprimir</button>
      <span class="ml-2 text-sm text-muted">Papel:</span>
      <button v-for="w in (['80', '58'] as const)" :key="w" type="button" class="rounded-lg border px-3 py-2 text-sm" :class="width === w ? 'border-ink bg-ink text-white' : 'border-line bg-surface'" :aria-pressed="width === w" @click="setWidth(w)">{{ w }} mm</button>
    </div>

    <SPageState v-if="failed" state="error" title="Conta não encontrada" />
    <SPageState v-else-if="!tab" state="loading" />
    <SPageState v-else-if="!items.length" state="empty" title="Nada enviado à cozinha ainda" />

    <article v-else class="ticket mx-auto bg-white font-mono text-black shadow print:shadow-none" :class="width === '58' ? 'w-[58mm] text-[12px]' : 'w-[80mm] text-[13px]'" aria-label="Pedido da cozinha">
      <header class="border-b border-dashed border-black pb-2 text-center">
        <p class="text-[2em] leading-none font-bold">{{ tab.label }}</p>
        <p class="mt-1 font-bold uppercase">Cozinha · envio {{ batch }}</p>
        <p v-if="sentAt">{{ formatDateTime(sentAt, tz) }}</p>
        <p v-if="tab.customer_name">{{ tab.customer_name }}</p>
      </header>
      <ul class="py-2">
        <li v-for="i in items" :key="i.id" class="border-b border-dotted border-black py-1.5 last:border-0" :class="i.voided_at && 'line-through'">
          <p class="text-[1.2em] font-bold">{{ i.quantity }}× {{ i.name }}</p>
          <p v-if="i.detail && !i.custom">{{ i.detail }}</p>
          <p v-if="i.notes" class="font-bold">» {{ i.notes }}</p>
          <p v-if="i.voided_at" class="font-bold no-underline">CANCELADO: {{ i.void_reason }}</p>
        </li>
      </ul>
      <footer class="border-t border-dashed border-black pt-2 text-center text-[0.9em]">
        <p>{{ me?.tenant.name }}</p>
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
