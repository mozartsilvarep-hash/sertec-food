<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ApiError, storefrontApi, type Storefront } from '@sertec/api-client'
import { SPageState } from '@sertec/ui'

/**
 * Sprint 0: shell do cardápio. Confirma a resolução do estabelecimento pelo
 * domínio. Catálogo, busca e carrinho entram na Sprint 1.
 */
const store = ref<Storefront | null>(null)
const state = ref<'loading' | 'ready' | 'error' | 'offline' | 'unavailable'>('loading')

async function load() {
  state.value = 'loading'
  try {
    store.value = await storefrontApi.show()
    document.title = `${store.value.name} · Cardápio`
    state.value = 'ready'
  } catch (error) {
    if (error instanceof ApiError && error.code === 'network_error') state.value = 'offline'
    else if (error instanceof ApiError && (error.status === 403 || error.status === 404)) state.value = 'unavailable'
    else state.value = 'error'
  }
}

onMounted(load)
</script>

<template>
  <main class="mx-auto min-h-dvh max-w-2xl">
    <SPageState v-if="state !== 'ready'" :state="state" @retry="load" />
    <template v-else-if="store">
      <header class="bg-brand-600 px-4 pt-10 pb-6 text-brand-contrast">
        <h1 class="text-2xl font-bold">
          {{ store.name }}
        </h1>
      </header>
      <SPageState state="empty" title="Cardápio em preparação" message="Em breve você poderá fazer seu pedido por aqui." />
    </template>
  </main>
</template>
