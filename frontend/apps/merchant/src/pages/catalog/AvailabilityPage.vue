<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ApiError, catalogApi, describeError, merchantApi, type Branch, type Product } from '@sertec/api-client'
import { SPageState, SSwitch } from '@sertec/ui'
import BranchPicker from '../../components/BranchPicker.vue'
import { loadAllProducts } from '../../catalogStore'
import { notify } from '../../notify'

const branches = ref<Branch[]>([])
const branchId = ref('')
const products = ref<Product[]>([])
const soldOut = ref<Set<string>>(new Set())
const state = ref<'loading' | 'ready' | 'error' | 'offline'>('loading')
const search = ref('')

const visible = computed(() => {
  const term = search.value.toLowerCase().trim()
  return products.value.filter((p) => p.active && (!term || p.name.toLowerCase().includes(term)))
})

async function loadSoldOut() {
  if (!branchId.value) return
  const data = await catalogApi.soldOut(branchId.value)
  soldOut.value = new Set(data.sold_out_product_ids)
}

async function load() {
  state.value = 'loading'
  try {
    ;[branches.value, products.value] = await Promise.all([merchantApi.branches(), loadAllProducts()])
    branchId.value = branches.value[0]?.id ?? ''
    await loadSoldOut()
    state.value = 'ready'
  } catch (error) {
    state.value = error instanceof ApiError && error.code === 'network_error' ? 'offline' : 'error'
  }
}

async function setAvailable(product: Product, available: boolean) {
  const previous = new Set(soldOut.value)
  const next = new Set(soldOut.value)
  if (available) next.delete(product.id)
  else next.add(product.id)
  soldOut.value = next // otimista: a cozinha precisa de resposta imediata
  try {
    await catalogApi.setAvailability(branchId.value, product.id, available)
    notify(available ? `${product.name} disponível` : `${product.name} marcado como esgotado`)
  } catch (error) {
    soldOut.value = previous
    notify(describeError(error).message, 'error')
  }
}

watch(branchId, (id, old) => { if (old) void loadSoldOut() })
onMounted(load)
</script>

<template>
  <h1 class="mb-2 font-display text-2xl font-bold">Esgotados</h1>
  <p class="mb-6 max-w-[65ch] text-sm text-muted">Desligue o produto quando acabar. Ele continua visível no cardápio como "Esgotado hoje" e não pode ser pedido.</p>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'offline'" state="offline" @retry="load" />
  <SPageState v-else-if="state === 'error'" state="error" @retry="load" />
  <template v-else>
    <BranchPicker v-model="branchId" :branches="branches" />
    <label class="mb-4 block max-w-sm">
      <span class="sr-only">Buscar produto</span>
      <input v-model="search" type="search" placeholder="Buscar produto" class="min-h-11 w-full rounded-full border border-line bg-surface px-4">
    </label>
    <SPageState v-if="visible.length === 0" state="empty" title="Nenhum produto ativo" />
    <ul v-else class="divide-y divide-line rounded-(--radius-card) border border-line bg-surface">
      <li v-for="p in visible" :key="p.id" class="flex items-center justify-between gap-4 px-4 py-3">
        <span :class="soldOut.has(p.id) && 'text-muted'">{{ p.name }}</span>
        <SSwitch
          :model-value="!soldOut.has(p.id)"
          :label="soldOut.has(p.id) ? 'Esgotado' : 'Disponível'"
          @update:model-value="setAvailable(p, $event)"
        />
      </li>
    </ul>
  </template>
</template>
