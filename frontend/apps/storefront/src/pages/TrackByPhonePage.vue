<script setup lang="ts">
import { computed, ref } from 'vue'
import { ApiError, formatMoney, formatTime, orderApi, type PhoneOrder } from '@sertec/api-client'
import { SAlert, SButton, STextField } from '@sertec/ui'
import BackHeader from '../components/BackHeader.vue'
import { lastOrder, savedCustomer, store } from '../shop'

// "Acompanhar pedido" pelo celular: só número, horário, situação e total das
// últimas 24 h (os detalhes ficam no link do pedido, guardado neste aparelho).
const phone = ref(savedCustomer()?.phone ?? '')
const orders = ref<PhoneOrder[] | null>(null)
const busy = ref(false)
const error = ref<string | null>(null)
const recent = lastOrder()
const tz = computed(() => store.value?.timezone ?? 'America/Sao_Paulo')
const FINAL = ['completed', 'rejected', 'cancelled']

async function search() {
  busy.value = true
  error.value = null
  try {
    orders.value = await orderApi.byPhone(phone.value)
  } catch (e) {
    orders.value = null
    if (e instanceof ApiError) {
      error.value = e.fieldErrors.phone?.[0] ?? e.message
    } else {
      error.value = 'Não foi possível consultar agora. Tente de novo.'
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BackHeader title="Acompanhar pedido" back="/" />
  <main class="mx-auto grid max-w-2xl gap-6 px-4 pt-6 pb-16">
    <RouterLink v-if="recent" :to="`/pedido/${recent}`" class="block rounded-(--radius-card) border border-brand-600 bg-brand-50 p-4 font-semibold text-brand-700">
      Ver meu último pedido feito neste aparelho ›
    </RouterLink>

    <form class="grid gap-3" @submit.prevent="search">
      <STextField v-model="phone" label="Celular usado no pedido" type="tel" inputmode="tel" placeholder="(79) 99999-0000" autocomplete="tel" />
      <SButton type="submit" :loading="busy">Ver meus pedidos</SButton>
      <p class="text-sm text-muted">Mostramos os pedidos das últimas 24 horas.</p>
    </form>

    <SAlert v-if="error">{{ error }}</SAlert>

    <section v-if="orders" aria-label="Pedidos encontrados" class="grid gap-3">
      <p v-if="orders.length === 0" class="text-muted">Nenhum pedido nas últimas 24 horas com este celular.</p>
      <article v-for="o in orders" :key="`${o.branch}-${o.number}`" class="grid gap-1 rounded-(--radius-card) border border-line bg-surface p-4">
        <p class="flex items-baseline justify-between gap-3">
          <span class="text-lg font-bold">Pedido nº {{ o.number }}</span>
          <span class="text-sm text-muted">{{ formatTime(o.placed_at, tz) }}</span>
        </p>
        <p class="font-semibold" :class="FINAL.includes(o.status) ? 'text-muted' : 'text-brand-700'">{{ o.status_label }}</p>
        <p class="text-sm text-muted">{{ o.fulfillment_type === 'delivery' ? 'Entrega' : 'Retirada' }} · {{ formatMoney(o.total) }} · {{ o.branch }}</p>
      </article>
    </section>
  </main>
</template>
