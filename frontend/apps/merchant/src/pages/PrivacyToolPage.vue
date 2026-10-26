<script setup lang="ts">
import { ref } from 'vue'
import { describeError, formatMoney, request } from '@sertec/api-client'
import { SAlert, SButton, STextField } from '@sertec/ui'
import { notify } from '../notify'

/** Atendimento a pedidos de clientes sobre os próprios dados (LGPD). */
interface Report {
  customer: { name: string; phone: string; email: string | null; created_at: string | null; last_order_at: string | null } | null
  orders: { number: number; placed_at: string; status: string; name: string; address: Record<string, string> | null; total: string }[]
}

const phone = ref('')
const report = ref<Report | null>(null)
const error = ref<string | null>(null)
const busy = ref(false)
const confirm = ref(false)

async function search() {
  busy.value = true
  error.value = null
  confirm.value = false
  try {
    report.value = (await request<{ data: Report }>(`/merchant/privacy/customer?phone=${encodeURIComponent(phone.value)}`)).data
  } catch (e) {
    error.value = describeError(e).message
    report.value = null
  } finally {
    busy.value = false
  }
}

async function erase() {
  if (!confirm.value) {
    confirm.value = true
    return
  }
  busy.value = true
  try {
    const r = (await request<{ data: { orders_anonymized: number } }>('/merchant/privacy/customer/erase', { method: 'POST', body: { phone: phone.value } })).data
    notify(`Dados removidos de ${r.orders_anonymized} pedido(s)`)
    report.value = null
    phone.value = ''
  } catch (e) {
    error.value = describeError(e).message
  } finally {
    busy.value = false
    confirm.value = false
  }
}
</script>

<template>
  <h1 class="mb-2 font-display text-2xl font-bold">Privacidade (LGPD)</h1>
  <p class="mb-6 max-w-2xl text-muted">Quando um cliente pedir para ver ou apagar os dados dele, busque pelo celular. Apagar remove nome, telefone, e-mail, endereço e observações; os valores dos pedidos continuam para a contabilidade. Pedidos antigos são apagados automaticamente após 24 meses.</p>

  <form class="mb-6 flex max-w-md items-end gap-2" @submit.prevent="search">
    <div class="flex-1"><STextField v-model="phone" label="Celular do cliente" type="tel" /></div>
    <SButton type="submit" :loading="busy && !confirm">Buscar</SButton>
  </form>
  <SAlert v-if="error" class="mb-4 max-w-2xl">{{ error }}</SAlert>

  <section v-if="report" class="max-w-2xl rounded-(--radius-card) border border-line bg-surface p-5" aria-label="Dados encontrados">
    <p v-if="!report.customer && report.orders.length === 0" class="text-muted">Nenhum dado encontrado para este celular.</p>
    <template v-else>
      <dl v-if="report.customer" class="grid grid-cols-[8rem_1fr] gap-y-1 text-sm">
        <dt class="text-muted">Nome</dt><dd>{{ report.customer.name }}</dd>
        <dt class="text-muted">Celular</dt><dd>{{ report.customer.phone }}</dd>
        <dt class="text-muted">E-mail</dt><dd>{{ report.customer.email ?? '—' }}</dd>
      </dl>
      <h2 class="mt-4 mb-2 font-semibold">{{ report.orders.length }} pedido(s)</h2>
      <ul class="divide-y divide-line text-sm">
        <li v-for="o in report.orders" :key="o.number" class="flex justify-between gap-3 py-1.5">
          <span>#{{ o.number }} · {{ new Date(o.placed_at).toLocaleDateString('pt-BR') }}<template v-if="o.address?.street"> · {{ o.address.street }}, {{ o.address.number }}</template></span>
          <span class="tabular-nums">{{ formatMoney(o.total) }}</span>
        </li>
      </ul>
      <div class="mt-4 flex items-center gap-3">
        <SButton :variant="confirm ? 'primary' : 'secondary'" :loading="busy && confirm" @click="erase">{{ confirm ? 'Confirmar: apagar dados pessoais' : 'Apagar dados pessoais' }}</SButton>
        <SButton v-if="confirm" variant="ghost" @click="confirm = false">Cancelar</SButton>
      </div>
    </template>
  </section>
</template>
