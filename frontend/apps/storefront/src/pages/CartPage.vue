<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { formatMoney } from '@sertec/api-client'
import { SButton, SPageState, SStepper } from '@sertec/ui'
import BackHeader from '../components/BackHeader.vue'
import { cart, loadCart, showToast, updateLine } from '../shop'

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

      <div class="mt-4 flex items-baseline justify-between border-t border-line pt-4">
        <span class="text-muted">Subtotal</span>
        <span class="text-lg font-bold tabular-nums">{{ formatMoney(cart.subtotal) }}</span>
      </div>
      <p class="mt-1 text-sm text-muted">Taxa de entrega calculada no próximo passo.</p>
    </template>
  </main>

  <div v-if="cart && cart.items.length" class="fixed inset-x-0 bottom-0 border-t border-line bg-surface px-4 py-3">
    <div class="mx-auto max-w-2xl">
      <SButton block :disabled="!cart.valid" @click="router.push('/finalizar')">Continuar</SButton>
    </div>
  </div>
</template>
