<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ApiError } from '@sertec/api-client'
import { SPageState } from '@sertec/ui'
import { loadStore, store, toast } from './shop'

const state = ref<'loading' | 'ready' | 'error' | 'offline' | 'unavailable'>('loading')
/** Restaurante sem o módulo Cardápio digital: só usa o PDV/salão. */
const noOnline = ref(false)

async function load() {
  state.value = 'loading'
  try {
    await loadStore()
    state.value = 'ready'
  } catch (error) {
    if (error instanceof ApiError && error.code === 'network_error') state.value = 'offline'
    else if (error instanceof ApiError && (error.status === 403 || error.status === 404)) {
      noOnline.value = error.code === 'feature_unavailable'
      state.value = 'unavailable'
    }
    else state.value = 'error'
  }
}

onMounted(load)
</script>

<template>
  <SPageState
    v-if="state !== 'ready' || !store" :state="state === 'ready' ? 'loading' : state" class="min-h-dvh"
    :title="noOnline ? 'Cardápio online indisponível' : undefined"
    :message="noOnline ? 'Este estabelecimento não recebe pedidos pelo site. Faça seu pedido no local.' : undefined"
    @retry="load"
  />
  <RouterView v-else />

  <div
    v-if="toast"
    role="status"
    class="fixed inset-x-4 bottom-24 z-30 mx-auto w-fit max-w-sm rounded-full bg-ink px-4 py-2.5 text-sm font-medium text-white shadow-lg"
  >
    {{ toast }}
  </div>
</template>
