<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import QRCode from 'qrcode'
import { diningTablesApi } from '@sertec/api-client'
import { SPageState } from '@sertec/ui'
import { me } from '../session'

/** Folha A4 com o QR Code de cada mesa (recortar e colar na mesa). */
const route = useRoute()
const cards = ref<{ id: string; label: string; svg: string }[]>([])
const state = ref<'loading' | 'ready' | 'error'>('loading')
const print = () => window.print()
const only = computed(() => (typeof route.query.mesa === 'string' ? route.query.mesa : null))

onMounted(async () => {
  try {
    const rows = (await diningTablesApi.list(typeof route.query.branch === 'string' ? route.query.branch : null))
      .filter((t) => t.active && t.qr_url && (!only.value || t.id === only.value))
    cards.value = await Promise.all(rows.map(async (t) => ({
      id: t.id, label: t.label, svg: await QRCode.toString(t.qr_url!, { type: 'svg', margin: 1, errorCorrectionLevel: 'M' }),
    })))
    state.value = 'ready'
  } catch {
    state.value = 'error'
  }
})
</script>

<template>
  <div class="min-h-dvh bg-canvas p-4 print:bg-white print:p-0">
    <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
      <button type="button" class="rounded-lg border border-line bg-surface px-3 py-2 text-sm font-medium" @click="$router.back()">‹ Voltar</button>
      <button type="button" class="rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-brand-contrast" :disabled="state !== 'ready'" @click="print">Imprimir</button>
      <span class="text-sm text-muted">Dica: imprima em papel adesivo ou plastifique.</span>
    </div>
    <SPageState v-if="state === 'loading'" state="loading" />
    <SPageState v-else-if="state === 'error'" state="error" />
    <SPageState v-else-if="!cards.length" state="empty" title="Nenhuma mesa ativa" />
    <ul v-else class="mx-auto grid max-w-[210mm] grid-cols-2 gap-4 bg-white print:gap-0 sm:grid-cols-3" aria-label="QR Codes das mesas">
      <li v-for="c in cards" :key="c.id" class="qr-card grid justify-items-center gap-1 rounded-xl border border-dashed border-black/40 p-4 text-center text-black">
        <p class="text-xs font-semibold tracking-wide uppercase">{{ me?.tenant.name }}</p>
        <p class="text-2xl font-bold">{{ c.label }}</p>
        <!-- eslint-disable-next-line vue/no-v-html -- SVG gerado localmente pela biblioteca de QR -->
        <div class="w-40" aria-hidden="true" v-html="c.svg" />
        <p class="text-sm font-semibold">Aponte a câmera e faça seu pedido</p>
        <p class="text-[10px] text-black/60">O garçom confirma antes de ir para a cozinha</p>
      </li>
    </ul>
  </div>
</template>

<style>
@media print {
  @page { margin: 10mm; size: A4; }
  .qr-card { break-inside: avoid; border-radius: 0; }
}
</style>
