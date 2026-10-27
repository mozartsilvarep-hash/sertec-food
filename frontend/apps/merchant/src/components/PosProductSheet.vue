<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ApiError, flavorAvailable, formatMoney, previewPrice, publicCatalogApi, type PublicOptionGroup, type PublicProduct } from '@sertec/api-client'
import { SButton, SPageState, SSheet, SStepper } from '@sertec/ui'

/** Tamanho, sabores e adicionais no PDV — mesmas regras do cardápio (o servidor confere). */
export interface PosPick {
  product_id: string
  variant_id: string
  options: { option: string; quantity: number }[]
  quantity: number
  notes: string | null
  name: string
  detail: string
  unitCents: number
}

const props = defineProps<{ productId: string | null; branchId: string | null }>()
const emit = defineEmits<{ close: []; add: [pick: PosPick] }>()

const product = ref<PublicProduct | null>(null)
const state = ref<'loading' | 'ready' | 'error'>('loading')
const variantId = ref('')
const picks = reactive<Record<string, number>>({})
const quantity = ref(1)
const notes = ref('')

async function load(id: string) {
  state.value = 'loading'
  product.value = null
  for (const k of Object.keys(picks)) delete picks[k]
  quantity.value = 1
  notes.value = ''
  try {
    product.value = await publicCatalogApi.product(id, props.branchId ?? undefined)
    variantId.value = product.value.variants[0]?.id ?? ''
    state.value = 'ready'
  } catch (e) {
    state.value = e instanceof ApiError ? 'error' : 'error'
  }
}
watch(() => props.productId, (id) => { if (id) void load(id) }, { immediate: true })
watch(variantId, (id) => {
  for (const g of product.value?.option_groups ?? []) {
    if (g.type !== 'fraction') continue
    for (const o of g.options) if (!flavorAvailable(o, id)) delete picks[o.id]
  }
})

const preview = computed(() => (product.value ? previewPrice(product.value, { variantId: variantId.value, options: picks }) : null))
const selectedIn = (g: PublicOptionGroup) => g.options.reduce((t, o) => t + (picks[o.id] ?? 0), 0)

function toggle(g: PublicOptionGroup, id: string) {
  if (picks[id]) {
    delete picks[id]
    return
  }
  if (g.max_select === 1) for (const o of g.options) delete picks[o.id]
  else if (selectedIn(g) >= g.max_select) return
  picks[id] = 1
}

function setQty(g: PublicOptionGroup, id: string, q: number) {
  const others = selectedIn(g) - (picks[id] ?? 0)
  const capped = Math.min(q, g.max_select - others)
  if (capped <= 0) delete picks[id]
  else picks[id] = capped
}

function add() {
  const p = product.value
  if (!p || !preview.value?.valid) return
  const variant = p.variants.find((v) => v.id === variantId.value)
  const names = p.option_groups.flatMap((g) => g.options.filter((o) => picks[o.id]).map((o) => (picks[o.id]! > 1 ? `${picks[o.id]}× ${o.name}` : o.name)))
  emit('add', {
    product_id: p.id,
    variant_id: variantId.value,
    options: Object.entries(picks).filter(([, q]) => q > 0).map(([option, q]) => ({ option, quantity: q })),
    quantity: quantity.value,
    notes: notes.value.trim() || null,
    name: p.name,
    detail: [p.variants.length > 1 ? variant?.name : null, ...names].filter(Boolean).join(', '),
    unitCents: preview.value.unitCents,
  })
}
</script>

<template>
  <SSheet :open="productId !== null" :label="product?.name ?? 'Produto'" @close="emit('close')">
    <SPageState v-if="state === 'loading'" state="loading" />
    <SPageState v-else-if="state === 'error' || !product" state="error" title="Não conseguimos abrir este produto" @retry="productId && load(productId)" />
    <template v-else>
      <div class="overflow-y-auto overscroll-contain px-5 pt-5">
        <div class="flex items-start justify-between gap-3">
          <h2 class="text-xl font-bold">{{ product.name }}</h2>
          <button type="button" class="-mt-1 -mr-2 grid size-10 place-items-center rounded-full text-2xl text-muted hover:bg-canvas" aria-label="Fechar" @click="emit('close')">×</button>
        </div>
        <fieldset v-if="product.variants.length > 1" class="py-3">
          <legend class="mb-2 font-semibold">Tamanho</legend>
          <div class="flex flex-wrap gap-2">
            <label v-for="v in product.variants" :key="v.id" class="cursor-pointer rounded-lg border px-4 py-2.5 text-sm font-semibold has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50 has-[:checked]:text-brand-700">
              <input v-model="variantId" type="radio" name="pos-variant" :value="v.id" class="sr-only">{{ v.name }} · {{ formatMoney(v.price) }}
            </label>
          </div>
        </fieldset>
        <fieldset v-for="(g, gi) in product.option_groups" :key="g.id" class="border-t border-line py-3">
          <legend class="sr-only">{{ g.name }}</legend>
          <p class="mb-2 flex items-center justify-between font-semibold" aria-hidden="true">
            {{ g.name }}
            <span v-if="g.required" class="rounded-full px-2 py-0.5 text-xs" :class="preview?.groups[gi]?.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-ink text-white'">{{ preview?.groups[gi]?.ok ? 'ok' : 'obrigatório' }}</span>
          </p>
          <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
            <template v-for="o in g.options" :key="o.id">
              <label v-if="g.type === 'fraction' || o.max_quantity === 1" class="flex cursor-pointer items-center gap-2 rounded-lg border border-line px-3 py-2.5 text-sm has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50" :class="!flavorAvailable(o, variantId) && 'opacity-45'">
                <input :type="g.max_select === 1 ? 'radio' : 'checkbox'" :name="`pos-${g.id}`" class="size-4 accent-brand-600" :checked="!!picks[o.id]" :disabled="!flavorAvailable(o, variantId)" @change="toggle(g, o.id)">
                <span class="min-w-0 flex-1 truncate">{{ o.name }}</span>
                <span v-if="Number(o.price_delta) > 0" class="text-xs text-muted">+{{ formatMoney(o.price_delta) }}</span>
              </label>
              <div v-else class="flex items-center justify-between gap-2 rounded-lg border border-line px-3 py-1.5 text-sm">
                <span class="min-w-0 truncate">{{ o.name }}</span>
                <SStepper :label="o.name" :max="o.max_quantity" :model-value="picks[o.id] ?? 0" @update:model-value="setQty(g, o.id, $event)" />
              </div>
            </template>
          </div>
        </fieldset>
        <label class="grid gap-1.5 border-t border-line py-3 text-sm font-semibold">Observação
          <input v-model="notes" maxlength="140" placeholder="Ex.: sem cebola" class="min-h-11 rounded-lg border border-line px-3 font-normal">
        </label>
      </div>
      <footer class="flex items-center gap-3 border-t border-line bg-surface px-5 py-4">
        <SStepper v-model="quantity" label="quantidade" :min="1" :max="99" />
        <SButton block class="flex-1" :disabled="!preview?.valid" @click="add">
          {{ preview?.valid ? `Adicionar · ${formatMoney(((preview.unitCents * quantity) / 100).toFixed(2))}` : 'Complete as escolhas' }}
        </SButton>
      </footer>
    </template>
  </SSheet>
</template>
