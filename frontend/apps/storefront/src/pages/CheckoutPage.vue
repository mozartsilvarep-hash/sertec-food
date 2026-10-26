<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  ApiError, cartApi, centsToString, formatMoney, orderApi, toCents,
  type CheckoutPayload, type CouponPreview, type FulfillmentType, type PaymentMethod, type PublicZone,
} from '@sertec/api-client'
import { SAlert, SButton, STextArea, STextField } from '@sertec/ui'
import BackHeader from '../components/BackHeader.vue'
import { branch, cart, loadCart, rememberOrder, store } from '../shop'

const router = useRouter()
const fulfillment = computed(() => branch.value?.fulfillment)
const zones = computed<PublicZone[]>(() => fulfillment.value?.zones ?? [])
const neighborhoods = computed(() =>
  zones.value.flatMap((z) => z.neighborhoods.map((n) => ({ name: n, zone: z }))).sort((a, b) => a.name.localeCompare(b.name, 'pt-BR')),
)

const form = reactive({
  type: 'delivery' as FulfillmentType,
  neighborhood: '',
  street: '',
  number: '',
  complement: '',
  reference: '',
  name: '',
  phone: '',
  email: '',
  method: 'card_on_delivery' as PaymentMethod,
  needsChange: false,
  changeFor: '',
  notes: '',
})
const errors = ref<Record<string, string[]>>({})
const message = ref<string | null>(null)
const sending = ref(false)
let attempt: { key: string; body: string } | null = null

const methods = computed(() => store.value?.payment_methods ?? [
  { method: 'card_on_delivery' as const, label: 'Cartão na entrega', online: false },
  { method: 'cash' as const, label: 'Dinheiro', online: false },
])
const onlineMethods = computed(() => methods.value.filter((m) => m.online))
const isOnline = computed(() => form.method === 'pix' || form.method === 'online_card')
const ONLINE_TEXT: Record<string, { title: string; hint: string }> = {
  pix: { title: 'Pix', hint: 'Aprovação na hora · QR Code ou copia e cola' },
  online_card: { title: 'Cartão de crédito ou débito', hint: 'Pelo Mercado Pago, ambiente seguro' },
}

const zone = computed(() => neighborhoods.value.find((n) => n.name === form.neighborhood)?.zone ?? null)
const subtotalCents = computed(() => toCents(cart.value?.subtotal ?? '0'))
const feeCents = computed(() => {
  if (form.type !== 'delivery' || !zone.value) return 0
  if (zone.value.free_above && subtotalCents.value >= toCents(zone.value.free_above)) return 0
  return toCents(zone.value.fee)
})
const belowMinimum = computed(() =>
  form.type === 'delivery' && zone.value?.minimum_order ? subtotalCents.value < toCents(zone.value.minimum_order) : false,
)
// Cupom: prévia pelo servidor; o checkout valida tudo de novo.
const couponOpen = ref(false)
const couponCode = ref('')
const coupon = ref<CouponPreview | null>(null)
const couponError = ref<string | null>(null)
const couponBusy = ref(false)
const discountCents = computed(() => {
  if (!coupon.value) return 0
  return coupon.value.free_delivery ? feeCents.value : Math.min(toCents(coupon.value.discount), subtotalCents.value)
})
const totalCents = computed(() => subtotalCents.value + feeCents.value - discountCents.value)

async function applyCoupon() {
  if (!cart.value?.token || !couponCode.value.trim()) return
  couponBusy.value = true
  couponError.value = null
  try {
    coupon.value = await cartApi.coupon(cart.value.token, couponCode.value.trim(), form.phone || null)
  } catch (e) {
    coupon.value = null
    couponError.value = e instanceof ApiError ? e.message : 'Não foi possível validar o cupom.'
  } finally {
    couponBusy.value = false
  }
}

function removeCoupon() {
  coupon.value = null
  couponCode.value = ''
  couponError.value = null
}
const err = (k: string) => errors.value[k]?.[0] ?? null

function payload(): CheckoutPayload {
  const delivery = form.type === 'delivery'
  return {
    cart_token: cart.value?.token ?? '',
    customer: { name: form.name.trim(), phone: form.phone, email: form.email.trim() || null },
    fulfillment: {
      type: form.type,
      zone_id: delivery ? (zone.value?.id ?? null) : null,
      address: delivery ? {
        street: form.street.trim(), number: form.number.trim(), neighborhood: form.neighborhood,
        complement: form.complement.trim() || null, reference: form.reference.trim() || null,
      } : null,
    },
    payment: {
      method: form.method,
      change_for: form.method === 'cash' && form.needsChange && form.changeFor ? centsToString(toCents(form.changeFor.replace(',', '.'))) : null,
    },
    coupon: coupon.value?.code ?? null,
    notes: form.notes.trim() || null,
  }
}

/** Mesma tentativa (mesmos dados) reaproveita a chave: reenvio após falha de rede não duplica o pedido. */
function idempotencyKey(body: string): string {
  if (attempt?.body !== body) attempt = { key: crypto.randomUUID(), body }
  return attempt.key
}

async function submit() {
  sending.value = true
  errors.value = {}
  message.value = null
  const data = payload()
  try {
    const result = await orderApi.checkout(data, idempotencyKey(JSON.stringify(data)))
    rememberOrder(result.tracking_token)
    await router.replace(`/pedido/${result.tracking_token}`)
  } catch (error) {
    if (error instanceof ApiError) {
      errors.value = error.fieldErrors
      message.value = Object.keys(error.fieldErrors).length ? 'Confira os campos destacados.' : error.message
      if (error.code.startsWith('coupon_')) {
        couponError.value = error.message
        coupon.value = null
      }
      if (error.code === 'cart_invalid' || error.code === 'cart_not_found') await loadCart()
    } else {
      message.value = 'Não foi possível enviar. Tente novamente.'
    }
  } finally {
    sending.value = false
  }
}

onMounted(async () => {
  await loadCart()
  if (!cart.value || cart.value.items.length === 0) await router.replace('/carrinho')
  if (fulfillment.value && !fulfillment.value.delivery_enabled) form.type = 'pickup'
  if (onlineMethods.value.some((m) => m.method === 'pix')) form.method = 'pix'
})
</script>

<template>
  <BackHeader title="Finalizar pedido" back="/carrinho" />

  <form v-if="cart && branch" class="mx-auto grid max-w-2xl gap-8 px-4 pt-4 pb-36" novalidate @submit.prevent="submit">
    <SAlert v-if="message">{{ message }}</SAlert>

    <fieldset>
      <legend class="mb-3 font-display text-xl font-bold">Como você quer receber?</legend>
      <div class="grid grid-cols-2 gap-2">
        <label
          v-for="opt in [{ v: 'delivery', t: 'Entrega', on: fulfillment?.delivery_enabled }, { v: 'pickup', t: 'Retirar no local', on: fulfillment?.pickup_enabled }]"
          :key="opt.v"
          class="cursor-pointer rounded-(--radius-card) border border-line p-4 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50 has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-50"
        >
          <input v-model="form.type" type="radio" name="type" :value="opt.v" :disabled="!opt.on" class="sr-only">
          <span class="block font-semibold">{{ opt.t }}</span>
          <span class="text-sm text-muted">
            <template v-if="!opt.on">Indisponível</template>
            <template v-else-if="opt.v === 'pickup'">~{{ fulfillment?.pickup_eta_minutes }} min · {{ branch.name }}</template>
            <template v-else>{{ zones.length }} {{ zones.length === 1 ? 'região' : 'regiões' }}</template>
          </span>
        </label>
      </div>
    </fieldset>

    <fieldset v-if="form.type === 'delivery'" class="grid gap-4">
      <legend class="mb-3 font-display text-xl font-bold">Endereço</legend>
      <div class="grid gap-1.5">
        <label for="bairro" class="text-sm font-medium">Bairro</label>
        <select id="bairro" v-model="form.neighborhood" class="min-h-11 rounded-(--radius-control) border bg-surface px-3" :class="err('fulfillment.zone_id') ? 'border-danger' : 'border-line'">
          <option value="" disabled>Escolha o bairro</option>
          <option v-for="n in neighborhoods" :key="n.name" :value="n.name">{{ n.name }} — entrega {{ formatMoney(n.zone.fee) }}</option>
        </select>
        <p class="text-sm text-muted">Não encontrou? Ainda não entregamos no seu bairro; escolha retirar no local.</p>
        <p v-if="zone" class="text-sm text-muted">
          Chega em ~{{ zone.eta_minutes }} min<template v-if="zone.free_above">. Entrega grátis acima de {{ formatMoney(zone.free_above) }}</template>.
        </p>
      </div>
      <div class="grid grid-cols-[1fr_7rem] gap-3">
        <STextField v-model="form.street" label="Rua" autocomplete="address-line1" :error="err('fulfillment.address.street')" />
        <STextField v-model="form.number" label="Número" :error="err('fulfillment.address.number')" />
      </div>
      <STextField v-model="form.complement" label="Complemento (opcional)" autocomplete="address-line2" />
      <STextField v-model="form.reference" label="Ponto de referência (opcional)" />
    </fieldset>

    <fieldset class="grid gap-4">
      <legend class="mb-3 font-display text-xl font-bold">Seus dados</legend>
      <STextField v-model="form.name" label="Nome" autocomplete="name" :error="err('customer.name')" />
      <STextField v-model="form.phone" label="Celular com DDD" type="tel" autocomplete="tel" :error="err('customer.phone')" />
      <p class="-mt-2 text-sm text-muted">Usamos só para falar sobre este pedido.</p>
    </fieldset>

    <fieldset class="grid gap-2">
      <legend class="mb-3 font-display text-xl font-bold">Pagamento</legend>
      <template v-if="onlineMethods.length">
        <p class="text-sm font-medium text-muted">Pagar agora pelo site</p>
        <label
          v-for="m in onlineMethods"
          :key="m.method"
          class="flex cursor-pointer items-center gap-3 rounded-(--radius-control) border border-line p-3 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50"
        >
          <input v-model="form.method" type="radio" :value="m.method" class="accent-brand-600">
          <span>
            <span class="block">{{ ONLINE_TEXT[m.method]?.title ?? m.label }}</span>
            <span class="block text-sm text-muted">{{ ONLINE_TEXT[m.method]?.hint }}</span>
          </span>
        </label>
        <p class="mt-3 text-sm font-medium text-muted">Pagar na {{ form.type === 'delivery' ? 'entrega' : 'retirada' }}</p>
      </template>
      <label class="flex cursor-pointer items-center gap-3 rounded-(--radius-control) border border-line p-3 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
        <input v-model="form.method" type="radio" value="card_on_delivery" class="accent-brand-600"> Cartão (maquininha)
      </label>
      <label class="flex cursor-pointer items-center gap-3 rounded-(--radius-control) border border-line p-3 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
        <input v-model="form.method" type="radio" value="cash" class="accent-brand-600"> Dinheiro
      </label>
      <div v-if="form.method === 'cash'" class="ml-1 grid gap-2 pt-2">
        <label class="inline-flex items-center gap-2 text-sm"><input v-model="form.needsChange" type="checkbox" class="accent-brand-600"> Preciso de troco</label>
        <div v-if="form.needsChange" class="w-40">
          <STextField v-model="form.changeFor" label="Troco para (R$)" :error="err('payment.change_for')" />
        </div>
      </div>
      <div v-if="isOnline" class="grid gap-1.5 pt-2">
        <STextField
          v-model="form.email"
          :label="form.method === 'pix' ? 'E-mail para o comprovante' : 'E-mail para o comprovante (opcional)'"
          type="email"
          autocomplete="email"
          :error="err('customer.email')"
        />
        <p class="text-sm text-muted">O Mercado Pago envia o comprovante para este e-mail. O pedido vai para o restaurante assim que o pagamento for confirmado.</p>
      </div>
    </fieldset>

    <STextArea v-model="form.notes" label="Observações para o restaurante (opcional)" :maxlength="300" />
    <p class="-mt-4 text-sm text-muted">Usamos seus dados só para este pedido. <RouterLink to="/privacidade" class="font-medium text-brand-700 underline">Política de privacidade</RouterLink></p>

    <section aria-label="Cupom de desconto" class="grid gap-2">
      <button v-if="!couponOpen && !coupon" type="button" class="justify-self-start text-sm font-semibold text-brand-700 hover:underline" @click="couponOpen = true">Tem cupom de desconto?</button>
      <div v-else-if="!coupon" class="flex items-end gap-2">
        <div class="flex-1"><STextField v-model="couponCode" label="Cupom" :error="couponError" @keydown.enter.prevent="applyCoupon" /></div>
        <SButton variant="secondary" :loading="couponBusy" @click="applyCoupon">Aplicar</SButton>
      </div>
      <p v-else class="flex items-center justify-between gap-3 rounded-(--radius-control) border border-success bg-success/5 px-3 py-2 text-sm" role="status">
        <span><strong>{{ coupon.code }}</strong><template v-if="coupon.description"> · {{ coupon.description }}</template></span>
        <button type="button" class="font-medium text-muted hover:text-ink" @click="removeCoupon">Remover</button>
      </p>
      <p v-if="coupon && couponError" class="text-sm text-danger">{{ couponError }}</p>
    </section>

    <section aria-label="Resumo" class="grid gap-1 border-t border-line pt-4 text-sm">
      <p class="flex justify-between"><span class="text-muted">Subtotal</span><span class="tabular-nums">{{ formatMoney(cart.subtotal) }}</span></p>
      <p v-if="form.type === 'delivery'" class="flex justify-between"><span class="text-muted">Entrega</span><span class="tabular-nums">{{ zone ? (feeCents === 0 ? 'Grátis' : formatMoney(centsToString(feeCents))) : 'escolha o bairro' }}</span></p>
      <p v-if="coupon && discountCents > 0" class="flex justify-between text-success"><span>{{ coupon.label }}</span><span class="tabular-nums">− {{ formatMoney(centsToString(discountCents)) }}</span></p>
      <p class="mt-1 flex justify-between text-base font-bold"><span>Total</span><span class="tabular-nums">{{ formatMoney(centsToString(totalCents)) }}</span></p>
      <p v-if="belowMinimum && zone" class="text-danger">Pedido mínimo para {{ form.neighborhood }}: {{ formatMoney(zone.minimum_order) }}.</p>
    </section>

    <div class="fixed inset-x-0 bottom-0 border-t border-line bg-surface px-4 py-3">
      <div class="mx-auto max-w-2xl">
        <SButton type="submit" block :loading="sending" :disabled="belowMinimum || (form.type === 'delivery' && !zone)">
          {{ isOnline ? 'Ir para o pagamento' : 'Fazer pedido' }} · {{ formatMoney(centsToString(totalCents)) }}
        </SButton>
      </div>
    </div>
  </form>
</template>
