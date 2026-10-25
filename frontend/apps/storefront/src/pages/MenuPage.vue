<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ApiError, publicCatalogApi, type ProductSummary, type PublicMenu } from '@sertec/api-client'
import { SPageState } from '@sertec/ui'
import CartBar from '../components/CartBar.vue'
import ProductRow from '../components/ProductRow.vue'
import ProductSheet from '../components/ProductSheet.vue'
import StoreHero from '../components/StoreHero.vue'
import { branch, branchId, lastOrder, selectBranch, store } from '../shop'

const menus = ref<PublicMenu[]>([])
const state = ref<'loading' | 'ready' | 'error' | 'offline'>('loading')
const search = ref('')
const openProduct = ref<string | null>(null)
const recentOrder = lastOrder()

const categories = computed(() => {
  const term = normalize(search.value)
  return menus.value
    .flatMap((m) => m.categories)
    .map((c) => ({
      ...c,
      products: term ? c.products.filter((p) => normalize(`${p.name} ${p.description ?? ''}`).includes(term)) : c.products,
    }))
    .filter((c) => c.products.length > 0)
})

function normalize(text: string): string {
  return text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase().trim()
}

async function load() {
  state.value = 'loading'
  try {
    menus.value = (await publicCatalogApi.menus(branchId.value ?? undefined)).menus
    state.value = 'ready'
  } catch (error) {
    state.value = error instanceof ApiError && error.code === 'network_error' ? 'offline' : 'error'
  }
}

function open(product: ProductSummary) {
  if (product.available) openProduct.value = product.id
}

watch(branchId, (id, old) => { if (old && id !== old) void load() })
onMounted(load)
</script>

<template>
  <template v-if="store">
    <StoreHero :store="store" :branch="branch" @change-branch="selectBranch" />

    <RouterLink
      v-if="recentOrder"
      :to="`/pedido/${recentOrder}`"
      class="block border-b border-line bg-brand-50 px-4 py-3 text-center text-sm font-semibold text-brand-700"
    >
      Acompanhar meu último pedido
    </RouterLink>

    <nav
      v-if="categories.length > 1 && !search"
      class="sticky top-0 z-10 border-b border-line bg-surface/95 backdrop-blur"
      aria-label="Categorias"
    >
      <ul class="mx-auto flex max-w-2xl gap-1 overflow-x-auto px-3 py-2 [scrollbar-width:none]">
        <li v-for="c in categories" :key="c.id">
          <a :href="`#cat-${c.id}`" class="block rounded-full px-3 py-1.5 text-sm font-medium whitespace-nowrap text-muted hover:bg-canvas hover:text-ink">
            {{ c.name }}
          </a>
        </li>
      </ul>
    </nav>

    <main class="mx-auto max-w-2xl px-4 pb-28 sm:px-6">
      <div class="py-5">
        <label for="busca" class="sr-only">Buscar no cardápio</label>
        <input
          id="busca"
          v-model="search"
          type="search"
          placeholder="Buscar no cardápio"
          class="min-h-11 w-full rounded-full border border-line bg-surface px-4 text-base placeholder:text-muted"
        >
      </div>

      <SPageState v-if="state === 'loading'" state="loading" />
      <SPageState v-else-if="state === 'offline'" state="offline" @retry="load" />
      <SPageState v-else-if="state === 'error'" state="error" @retry="load" />
      <SPageState
        v-else-if="categories.length === 0"
        state="empty"
        :title="search ? `Nada encontrado para “${search}”` : 'Cardápio em preparação'"
        :message="search ? 'Tente outro nome ou confira as categorias.' : 'Volte em breve para ver os produtos.'"
      />

      <section v-for="c in categories" :id="`cat-${c.id}`" :key="c.id" class="scroll-mt-16 pt-4" :aria-labelledby="`h-${c.id}`">
        <h2 :id="`h-${c.id}`" class="font-display text-2xl font-bold tracking-tight">{{ c.name }}</h2>
        <p v-if="c.description" class="mt-1 text-sm text-muted">{{ c.description }}</p>
        <ul class="divide-y divide-line">
          <ProductRow v-for="p in c.products" :key="p.id" :product="p" @open="open(p)" />
        </ul>
      </section>
    </main>

    <ProductSheet :product-id="openProduct" :branch-id="branchId" @close="openProduct = null" />
    <CartBar />
  </template>
</template>
