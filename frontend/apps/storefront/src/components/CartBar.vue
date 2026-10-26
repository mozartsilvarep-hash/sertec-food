<script setup lang="ts">
import { formatMoney } from '@sertec/api-client'
import { cart } from '../shop'
</script>

<template>
  <div v-if="cart && cart.count > 0" class="fixed inset-x-0 bottom-0 z-20 border-t border-line bg-surface/95 px-4 py-3 backdrop-blur">
    <RouterLink
      to="/carrinho"
      class="mx-auto flex max-w-2xl items-center justify-between gap-3 rounded-full bg-brand-600 px-5 py-3 font-semibold text-brand-contrast hover:bg-brand-700"
    >
      <span>Ver carrinho <span class="font-normal opacity-85">· {{ cart.count }} {{ cart.count === 1 ? 'item' : 'itens' }}</span></span>
      <span class="tabular-nums">{{ formatMoney(cart.net_subtotal ?? cart.subtotal) }}</span>
    </RouterLink>
  </div>
</template>
