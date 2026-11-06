<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { describeError, formatMoney, tableApi } from '@sertec/api-client'
import { SButton, SPageState, SStepper } from '@sertec/ui'
import BackHeader from '../components/BackHeader.vue'
import { cart, forgetCart, loadCart, rememberTableRequest, savedCustomer, showToast, table, updateLine } from '../shop'

const router = useRouter()
const busy = ref<string | null>(null)

async function setQty(id: string, quantity: number) {
  busy.value = id
  try {
    await updateLine(id, quantity)
    if (quantity === 0) showToast('Item removido')
  } finally {
    busy.value = null
  }
}

onMounted(() => loadCart())

/* Modo mesa: envia para o garçom confirmar (sem entrega nem pagamento agora). */
const tableForm = ref({ name: savedCustomer()?.name ?? '', notes: '' })
const sending = ref(false)
const tableError = ref<string | null>(null)
async function sendToTable() {
  if (!table.value || !cart.value || sending.value) return
  sending.value = true
  tableError.value = null
  try {
    const r = await tableApi.send(table.value.token, {
      type: 'order', cart_token: cart.value.token, customer_name: tableForm.value.name.trim() || null, notes: tableForm.value.notes.trim() || null,
    })
    if (r.view_token) rememberTableRequest(r.id, r.view_token)
    forgetCart()
    await router.push({ name: 'table-request', params: { id: r.id } })
  } catch (e) {
    tableError.value = describeError(e).message
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <BackHeader title="Seu carrinho" />
  <main class="mx-auto max-w-2xl px-4 pt-2 pb-32">
    <SPageState v-if="!cart || cart.items.length === 0" state="empty" title="Seu carrinho está vazio" message="Escolha algo gostoso no cardápio." />

    <template v-else>
      <p v-if="!cart.valid" role="alert" class="my-4 rounded-(--radius-control) bg-danger-soft px-4 py-3 text-sm text-danger">
        Alguns itens mudaram desde que você os adicionou. Remova-os para continuar.
      </p>

      <ul class="divide-y divide-line">
        <li v-for="line in cart.items" :key="line.id" class="py-4" :class="!line.valid && 'opacity-70'">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="font-semibold">{{ line.product_name }}<span v-if="line.variant_name && line.variant_name !== 'Única' && line.variant_name !== 'Único'" class="font-normal text-muted"> · {{ line.variant_name }}</span></p>
              <p v-if="line.options.length" class="mt-0.5 text-sm text-muted">
                {{ line.options.map((o) => (o.quantity > 1 ? `${o.quantity}× ${o.option_name}` : o.option_name)).join(', ') }}
              </p>
              <p v-if="line.notes" class="mt-0.5 text-sm text-muted">Obs.: {{ line.notes }}</p>
              <p v-if="line.problem" class="mt-1 text-sm font-medium text-danger">{{ line.problem.message }}</p>
            </div>
            <p class="shrink-0 text-right font-semibold tabular-nums">
              {{ line.line_total ? formatMoney(line.line_total) : '—' }}
              <span v-if="line.promotion_label" class="block text-xs font-bold text-success">{{ line.promotion_label }}</span>
            </p>
          </div>
          <div class="mt-3 flex items-center justify-between">
            <SStepper :model-value="line.quantity" :label="line.product_name" :max="50" @update:model-value="setQty(line.id, $event)" />
            <SButton variant="ghost" :loading="busy === line.id" @click="setQty(line.id, 0)">Remover</SButton>
          </div>
        </li>
      </ul>

      <div v-if="cart.discounts?.length" class="mt-4 grid gap-1 border-t border-line pt-4 text-sm">
        <p class="flex justify-between"><span class="text-muted">Itens</span><span class="tabular-nums">{{ formatMoney(cart.subtotal) }}</span></p>
        <p v-for="d in cart.discounts" :key="d.label" class="flex justify-between text-success">
          <span>{{ d.label }}</span><span class="tabular-nums">− {{ formatMoney(d.amount) }}</span>
        </p>
      </div>
      <div class="mt-4 flex items-baseline justify-between border-t border-line pt-4">
        <span class="text-muted">Subtotal</span>
        <span class="text-lg font-bold tabular-nums">{{ formatMoney(cart.net_subtotal ?? cart.subtotal) }}</span>
      </div>
      <p v-if="!table" class="mt-1 text-sm text-muted">Taxa de entrega calculada no próximo passo.</p>
      <div v-else class="mt-5 grid gap-3 rounded-2xl border border-brand-100 bg-brand-50 p-4">
        <p class="text-sm"><strong class="text-brand-700">{{ table.label }}</strong> · o garçom confirma o pedido e você paga a conta no final.</p>
        <label class="grid gap-1 text-sm font-medium">Seu nome (opcional)
          <input v-model="tableForm.name" maxlength="60" autocomplete="given-name" class="min-h-11 rounded-lg border border-line bg-surface px-3">
        </label>
        <label class="grid gap-1 text-sm font-medium">Observação (opcional)
          <input v-model="tableForm.notes" maxlength="200" placeholder="Ex.: sem cebola, trazer junto" class="min-h-11 rounded-lg border border-line bg-surface px-3">
        </label>
        <p v-if="tableError" role="alert" class="text-sm text-danger">{{ tableError }}</p>
      </div>
    </template>
  </main>

  <div v-if="cart && cart.items.length" class="fixed inset-x-0 bottom-0 border-t border-line bg-surface px-4 py-3">
    <div class="mx-auto max-w-2xl">
      <SButton v-if="table" block :loading="sending" :disabled="!cart.valid" @click="sendToTable">Enviar pedido para a {{ table.label }}</SButton>
      <SButton v-else block :disabled="!cart.valid" @click="router.push('/finalizar')">Continuar</SButton>
    </div>
  </div>
</template>
