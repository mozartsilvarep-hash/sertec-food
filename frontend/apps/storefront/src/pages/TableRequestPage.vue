<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { tableApi, type TableRequestView } from '@sertec/api-client'
import { SButton, SPageState } from '@sertec/ui'
import { table, tableRequestToken } from '../shop'

/** Acompanhar o pedido feito pela mesa (atualiza sozinho). */
const props = defineProps<{ id: string }>()
const req = ref<TableRequestView | null>(null)
const failed = ref(false)
let timer: ReturnType<typeof setInterval> | undefined

async function load() {
  const token = tableRequestToken(props.id)
  if (!token) {
    failed.value = true
    return
  }
  try {
    req.value = await tableApi.status(props.id, token)
    if (req.value.status !== 'pending') clearInterval(timer)
  } catch {
    failed.value = true
    clearInterval(timer)
  }
}
onMounted(() => {
  void load()
  timer = setInterval(() => { if (!document.hidden) void load() }, 5000)
})
onBeforeUnmount(() => clearInterval(timer))

const view = computed(() => {
  switch (req.value?.status) {
    case 'accepted': return { title: 'Pedido confirmado!', text: 'O garçom confirmou e o pedido já foi para o preparo.', tone: 'text-success' }
    case 'rejected': return { title: 'O garçom não confirmou', text: req.value.reason ? `Motivo: ${req.value.reason}.` : 'Fale com o garçom, por favor.', tone: 'text-danger' }
    case 'expired': return { title: 'Pedido não confirmado', text: 'Ninguém confirmou a tempo. Envie de novo ou chame o garçom.', tone: 'text-danger' }
    default: return { title: 'Aguardando o garçom', text: 'Seu pedido chegou. Assim que o garçom confirmar, ele vai para o preparo.', tone: 'text-ink' }
  }
})
</script>

<template>
  <SPageState v-if="failed" state="error" title="Pedido não encontrado" class="min-h-dvh" />
  <SPageState v-else-if="!req" state="loading" class="min-h-dvh" />
  <main v-else class="mx-auto grid max-w-md gap-4 px-4 py-10 text-center" aria-live="polite">
    <span v-if="req.status === 'pending'" class="mx-auto size-10 animate-spin rounded-full border-4 border-brand-100 border-t-brand-600" aria-hidden="true" />
    <h1 class="text-2xl font-bold" :class="view.tone">{{ view.title }}</h1>
    <p class="text-muted">{{ view.text }}</p>
    <p v-if="req.summary" class="rounded-2xl border border-line bg-surface p-4 text-left text-sm">{{ req.summary }}</p>
    <p v-if="table" class="text-sm text-muted">{{ table.label }}</p>
    <SButton block @click="$router.push({ name: 'menu' })">{{ req.status === 'accepted' ? 'Pedir mais alguma coisa' : 'Voltar ao cardápio' }}</SButton>
  </main>
</template>
