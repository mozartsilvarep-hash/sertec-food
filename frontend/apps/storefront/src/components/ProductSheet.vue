<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import {
  ApiError, flavorAvailable, formatMoney, previewPrice, publicCatalogApi,
  type PublicOptionGroup, type PublicProduct,
} from '@sertec/api-client'
import { SButton, SPageState, SSheet, SStepper } from '@sertec/ui'
import { addToCart, branch, showToast } from '../shop'

const props = defineProps<{ productId: string | null; branchId: string | null }>()
const emit = defineEmits<{ close: [] }>()

const product = ref<PublicProduct | null>(null)
const state = ref<'loading' | 'ready' | 'error' | 'offline'>('loading')
const variantId = ref('')
const picks = reactive<Record<string, number>>({})
const quantity = ref(1)
const notes = ref('')
const adding = ref(false)
const addError = ref<string | null>(null)

async function load(id: string) {
  state.value = 'loading'
  product.value = null
  for (const key of Object.keys(picks)) delete picks[key]
  quantity.value = 1
  notes.value = ''
  addError.value = null
  try {
    product.value = await publicCatalogApi.product(id, props.branchId ?? undefined)
    variantId.value = product.value.variants[0]?.id ?? ''
    state.value = 'ready'
  } catch (error) {
    state.value = error instanceof ApiError && error.code === 'network_error' ? 'offline' : 'error'
  }
}

watch(() => props.productId, (id) => { if (id) void load(id) }, { immediate: true })

// Ao trocar de tamanho, desmarca sabores que não existem nele.
watch(variantId, (id) => {
  for (const group of product.value?.option_groups ?? []) {
    if (group.type !== 'fraction') continue
    for (const option of group.options) {
      if (!flavorAvailable(option, id)) delete picks[option.id]
    }
  }
})

const preview = computed(() => (product.value ? previewPrice(product.value, { variantId: variantId.value, options: picks }) : null))

function groupHint(group: PublicOptionGroup): string {
  if (group.type === 'fraction') return group.max_select > 1 ? `Escolha até ${group.max_select} sabores` : 'Escolha 1 sabor'
  const parts: string[] = []
  if (group.min_select > 0) parts.push(group.min_select === group.max_select ? `Escolha ${group.min_select}` : `Mínimo ${group.min_select}`)
  else parts.push(`Até ${group.max_select}`)
  if (group.free_quantity > 0) parts.push(`${group.free_quantity} grátis`)
  return parts.join(', ')
}

function selectedIn(group: PublicOptionGroup): number {
  return group.options.reduce((total, o) => total + (picks[o.id] ?? 0), 0)
}

function toggleSingle(group: PublicOptionGroup, optionId: string) {
  const isRadio = group.max_select === 1
  if (picks[optionId]) {
    delete picks[optionId]
    return
  }
  if (isRadio) for (const o of group.options) delete picks[o.id]
  else if (selectedIn(group) >= group.max_select) return
  picks[optionId] = 1
}

function setQty(group: PublicOptionGroup, optionId: string, qty: number) {
  const others = selectedIn(group) - (picks[optionId] ?? 0)
  const capped = Math.min(qty, group.max_select - others)
  if (capped <= 0) delete picks[optionId]
  else picks[optionId] = capped
}

const pendingGroup = computed(() => {
  const index = preview.value?.groups.findIndex((g) => !g.ok) ?? -1
  return index >= 0 ? product.value?.option_groups[index]?.name : null
})

async function add() {
  if (!product.value || !preview.value?.valid) return
  adding.value = true
  addError.value = null
  try {
    await addToCart({
      product_id: product.value.id,
      variant_id: variantId.value,
      options: Object.entries(picks).filter(([, q]) => q > 0).map(([option, q]) => ({ option, quantity: q })),
      quantity: quantity.value,
      notes: notes.value.trim() || null,
    })
    showToast(`${product.value.name} adicionado`)
    emit('close')
  } catch (error) {
    addError.value = error instanceof ApiError ? error.message : 'Não foi possível adicionar. Tente de novo.'
  } finally {
    adding.value = false
  }
}

function flavorPrice(option: PublicProduct['option_groups'][number]['options'][number]): string | null {
  const p = option.prices?.[variantId.value]
  return p ? formatMoney(p) : null
}
</script>

<template>
  <SSheet :open="productId !== null" :label="product?.name ?? 'Produto'" @close="emit('close')">
    <SPageState v-if="state === 'loading'" state="loading" />
    <SPageState v-else-if="state === 'offline'" state="offline" @retry="productId && load(productId)" />
    <SPageState v-else-if="state === 'error' || !product" state="error" title="Não conseguimos abrir este produto" @retry="productId && load(productId)" />

    <template v-else>
      <div class="overflow-y-auto overscroll-contain">
        <img v-if="product.image_url" :src="product.image_url" alt="" class="aspect-[4/3] w-full object-cover">
        <div class="px-5 pt-5 pb-2">
          <div class="flex items-start justify-between gap-3">
            <h2 class="font-display text-2xl leading-tight font-bold">{{ product.name }}</h2>
            <button type="button" class="-mt-1 -mr-2 grid size-10 shrink-0 place-items-center rounded-full text-2xl text-muted hover:bg-canvas" aria-label="Fechar" @click="emit('close')">
              ×
            </button>
          </div>
          <p v-if="product.description" class="mt-2 text-sm/relaxed text-muted">{{ product.description }}</p>
        </div>

        <fieldset v-if="product.variants.length > 1" class="px-5 py-4">
          <legend class="mb-3 font-semibold">Tamanho</legend>
          <div class="flex flex-wrap gap-2">
            <label
              v-for="v in product.variants"
              :key="v.id"
              class="cursor-pointer rounded-full border px-4 py-2 text-sm font-medium has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50 has-[:checked]:text-brand-700 has-[:focus-visible]:outline-3 has-[:focus-visible]:outline-brand-500"
            >
              <input v-model="variantId" type="radio" name="variant" :value="v.id" class="sr-only">
              {{ v.name }}<template v-if="Number(v.price) > 0"> · {{ formatMoney(v.price) }}</template>
            </label>
          </div>
        </fieldset>

        <fieldset v-for="(group, gi) in product.option_groups" :key="group.id" class="border-t border-line px-5 py-4">
          <legend class="sr-only">{{ group.name }}</legend>
          <div class="mb-2 flex items-baseline justify-between gap-3" aria-hidden="true">
            <p class="font-semibold">{{ group.name }}</p>
            <span
              v-if="group.required"
              class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold"
              :class="preview?.groups[gi]?.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-ink text-white'"
            >{{ preview?.groups[gi]?.ok ? 'Pronto' : 'Obrigatório' }}</span>
          </div>
          <p class="mb-2 text-sm text-muted">{{ groupHint(group) }}<template v-if="group.type === 'fraction' && group.max_select > 1">. Cobramos {{ group.pricing_rule === 'average' ? 'a média dos sabores' : 'o sabor mais caro' }}.</template></p>

          <ul class="divide-y divide-line">
            <li v-for="option in group.options" :key="option.id">
              <template v-if="group.type === 'fraction' || option.max_quantity === 1">
                <label
                  class="flex cursor-pointer items-center gap-3 py-3"
                  :class="!flavorAvailable(option, variantId) && 'cursor-not-allowed opacity-45'"
                >
                  <input
                    :type="group.max_select === 1 ? 'radio' : 'checkbox'"
                    :name="group.id"
                    class="size-5 accent-brand-600"
                    :checked="!!picks[option.id]"
                    :disabled="!flavorAvailable(option, variantId) || (!picks[option.id] && group.max_select > 1 && selectedIn(group) >= group.max_select)"
                    @change="toggleSingle(group, option.id)"
                  >
                  <span class="min-w-0 flex-1">
                    <span class="block text-sm font-medium">{{ option.name }}</span>
                    <span v-if="option.description" class="block text-xs text-muted">{{ option.description }}</span>
                  </span>
                  <span class="text-sm text-muted tabular-nums">
                    <template v-if="group.type === 'fraction'">{{ flavorPrice(option) ?? 'Indisponível neste tamanho' }}</template>
                    <template v-else-if="Number(option.price_delta) > 0">+ {{ formatMoney(option.price_delta) }}</template>
                  </span>
                </label>
              </template>
              <div v-else class="flex items-center gap-3 py-3">
                <span class="min-w-0 flex-1">
                  <span class="block text-sm font-medium">{{ option.name }}</span>
                  <span v-if="Number(option.price_delta) > 0" class="block text-xs text-muted tabular-nums">+ {{ formatMoney(option.price_delta) }}</span>
                </span>
                <SStepper
                  :label="option.name"
                  :max="option.max_quantity"
                  :model-value="picks[option.id] ?? 0"
                  @update:model-value="setQty(group, option.id, $event)"
                />
              </div>
            </li>
          </ul>
        </fieldset>

        <div class="border-t border-line px-5 py-4">
          <label for="obs" class="mb-1.5 block font-semibold">Alguma observação?</label>
          <textarea
            id="obs"
            v-model="notes"
            rows="2"
            maxlength="140"
            placeholder="Ex.: sem cebola, bem passado"
            class="w-full rounded-(--radius-control) border border-line bg-surface px-3 py-2 text-base"
          />
        </div>
      </div>

      <footer class="border-t border-line bg-surface px-5 py-4">
        <p v-if="addError" role="alert" class="mb-2 text-sm text-danger">{{ addError }}</p>
        <p v-else-if="branch && !branch.open && branch.has_hours" class="mb-2 text-sm text-muted">Estamos fechados agora: você pode montar o pedido e enviar quando abrirmos.</p>
        <div class="flex items-center gap-3">
          <SStepper v-model="quantity" label="quantidade" :min="1" :max="50" />
          <SButton block :disabled="!preview?.valid" :loading="adding" class="flex-1" @click="add">
            <template v-if="preview?.valid">Adicionar · {{ formatMoney((preview.unitCents * quantity / 100).toFixed(2)) }}</template>
            <template v-else>Escolha: {{ pendingGroup ?? 'tamanho' }}</template>
          </SButton>
        </div>
      </footer>
    </template>
  </SSheet>
</template>
