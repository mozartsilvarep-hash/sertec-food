<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { describeError, diningTablesApi, type DiningTableRow } from '@sertec/api-client'
import { SButton } from '@sertec/ui'
import { notify } from '../notify'

/** Cadastro das mesas e QR Codes (cash.manage). */
const props = defineProps<{ branchId: string }>()
const rows = ref<DiningTableRow[]>([])
const form = reactive({ prefix: 'Mesa', from: 1, to: 10, saving: false, error: '' })
const busy = ref<string | null>(null)

async function load() {
  try {
    rows.value = await diningTablesApi.list(props.branchId || null)
  } catch (e) {
    form.error = describeError(e).message
  }
}
onMounted(load)
watch(() => props.branchId, load)

async function create() {
  form.saving = true
  form.error = ''
  try {
    rows.value = await diningTablesApi.create({ branch: props.branchId || null, prefix: form.prefix.trim() || 'Mesa', from: form.from, to: form.to })
    notify('Mesas criadas')
  } catch (e) {
    const d = describeError(e)
    form.error = d.fields ? Object.values(d.fields)[0]?.[0] ?? d.message : d.message
  } finally {
    form.saving = false
  }
}
async function act(t: DiningTableRow, what: 'qr' | 'toggle' | 'remove') {
  busy.value = t.id
  try {
    if (what === 'remove') {
      await diningTablesApi.remove(t.id)
      rows.value = rows.value.filter((x) => x.id !== t.id)
    } else {
      const updated = await diningTablesApi.update(t.id, what === 'qr' ? { new_qr: true } : { active: !t.active })
      rows.value = rows.value.map((x) => (x.id === t.id ? updated : x))
      if (what === 'qr') notify(`${t.label}: QR novo. Imprima e troque o da mesa.`)
    }
  } catch (e) {
    notify(describeError(e).message, 'error')
  } finally {
    busy.value = null
  }
}
</script>

<template>
  <section class="mt-10 grid gap-4" aria-labelledby="qr-title">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h2 id="qr-title" class="text-lg font-bold">Mesas e QR Codes</h2>
        <p class="max-w-[65ch] text-sm text-muted">Cada mesa ganha um QR Code. O cliente escaneia, escolhe no cardápio e envia; o pedido só vai para a cozinha depois que o garçom confirma.</p>
      </div>
      <RouterLink v-if="rows.length" :to="{ name: 'tables-qr', query: { branch: branchId || undefined } }" class="min-h-11 rounded-lg border border-line bg-surface px-4 py-2.5 text-sm font-semibold hover:bg-canvas">Imprimir todos os QR</RouterLink>
    </div>
    <form class="flex flex-wrap items-end gap-3 rounded-(--radius-card) border border-line bg-surface p-4" aria-label="Criar mesas" @submit.prevent="create">
      <label class="grid gap-1 text-sm font-medium">Nome<input v-model="form.prefix" maxlength="30" class="min-h-11 w-32 rounded-lg border border-line px-3"></label>
      <label class="grid gap-1 text-sm font-medium">De<input v-model.number="form.from" type="number" min="0" max="999" class="min-h-11 w-20 rounded-lg border border-line px-3"></label>
      <label class="grid gap-1 text-sm font-medium">Até<input v-model.number="form.to" type="number" min="0" max="999" class="min-h-11 w-20 rounded-lg border border-line px-3"></label>
      <SButton type="submit" :loading="form.saving">Criar mesas</SButton>
      <p class="w-full text-xs text-muted">Ex.: Mesa de 1 até 10 cria "Mesa 1" … "Mesa 10". As que já existem ficam como estão.</p>
      <p v-if="form.error" role="alert" class="w-full text-sm text-danger">{{ form.error }}</p>
    </form>
    <ul v-if="rows.length" class="divide-y divide-line rounded-(--radius-card) border border-line bg-surface text-sm">
      <li v-for="t in rows" :key="t.id" class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-2.5">
        <span class="min-w-0 flex-1 font-semibold" :class="!t.active && 'text-muted line-through'">{{ t.label }}</span>
        <RouterLink :to="{ name: 'tables-qr', query: { branch: branchId || undefined, mesa: t.id } }" class="min-h-9 rounded-lg px-2 py-1.5 text-brand-700 hover:bg-canvas">Imprimir QR</RouterLink>
        <button type="button" class="min-h-9 rounded-lg px-2 hover:bg-canvas" :disabled="busy === t.id" @click="act(t, 'qr')">Novo QR</button>
        <button type="button" class="min-h-9 rounded-lg px-2 hover:bg-canvas" :disabled="busy === t.id" @click="act(t, 'toggle')">{{ t.active ? 'Desativar' : 'Ativar' }}</button>
        <button type="button" class="min-h-9 rounded-lg px-2 text-danger hover:bg-danger/10" :disabled="busy === t.id" @click="act(t, 'remove')">Remover</button>
      </li>
    </ul>
  </section>
</template>
