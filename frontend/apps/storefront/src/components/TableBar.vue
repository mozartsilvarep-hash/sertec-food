<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { describeError, tableApi } from '@sertec/api-client'
import { leaveTable, rememberTableRequest, showToast, table } from '../shop'

/** Faixa do modo mesa: lembra para onde vai o pedido e chama o garçom. */
const router = useRouter()
const busy = ref(false)

async function call(type: 'waiter' | 'bill') {
  if (!table.value || busy.value) return
  busy.value = true
  try {
    const r = await tableApi.send(table.value.token, { type })
    if (r.view_token) rememberTableRequest(r.id, r.view_token)
    showToast(type === 'bill' ? 'Pedimos a conta. O garçom já vem.' : 'O garçom foi chamado.')
  } catch (e) {
    showToast(describeError(e).message)
  } finally {
    busy.value = false
  }
}

function leave() {
  leaveTable()
  void router.push({ name: 'menu' })
}
</script>

<template>
  <section v-if="table" class="border-b border-brand-100 bg-brand-50 px-4 py-3" aria-label="Pedido na mesa">
    <div class="mx-auto flex max-w-2xl flex-wrap items-center gap-x-3 gap-y-2 lg:max-w-5xl">
      <p class="min-w-0 flex-1 text-sm">
        <strong class="text-brand-700">{{ table.label }}</strong> · escolha e envie: o garçom confirma e leva até você.
      </p>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="min-h-10 rounded-full border border-brand-600 bg-surface px-3 text-sm font-semibold text-brand-700" :disabled="busy" @click="call('waiter')">Chamar garçom</button>
        <button type="button" class="min-h-10 rounded-full border border-brand-600 bg-surface px-3 text-sm font-semibold text-brand-700" :disabled="busy" @click="call('bill')">Pedir a conta</button>
        <button type="button" class="min-h-10 px-2 text-xs text-muted underline" @click="leave">Não estou na mesa</button>
      </div>
    </div>
  </section>
</template>
