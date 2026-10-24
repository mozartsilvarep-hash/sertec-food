<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  ApiError, publicCatalogApi, type BranchStatus, type ProductSummary, type PublicMenu, type StorefrontInfo,
} from '@sertec/api-client'
import { applyBrand, deriveBrand, SPageState } from '@sertec/ui'
import ProductRow from './components/ProductRow.vue'
import ProductSheet from './components/ProductSheet.vue'
import StoreHero from './components/StoreHero.vue'
import { readPref, writePref } from './storage'

const store = ref<StorefrontInfo | null>(null)
const menus = ref<PublicMenu[]>([])
const branchId = ref<string | null>(null)
const state = ref<'loading' | 'ready' | 'error' | 'offline' | 'unavailable'>('loading')
const search = ref('')
const openProduct = ref<string | null>(null)

const branch = computed<BranchStatus | null>(() => store.value?.branches.find((b) => b.id === branchId.value) ?? null)

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

function fail(error: unknown) {
  if (error instanceof ApiError && error.code === 'network_error') state.value = 'offline'
  else if (error instanceof ApiError && (error.status === 403 || error.status === 404)) state.value = 'unavailable'
  else state.value = 'error'
}

async function loadMenus() {
  const data = await publicCatalogApi.menus(branchId.value ?? undefined)
  menus.value = data.menus
  branchId.value = data.branch_id
}

async function load() {
  state.value = 'loading'
  try {
    store.value = await publicCatalogApi.storefront()
    document.title = `${store.value.name} · Cardápio`
    if (store.value.brand_color) applyBrand(deriveBrand(store.value.brand_color))

    const saved = readPref(`branch:${store.value.slug}`)
    branchId.value = store.value.branches.some((b) => b.id === saved) ? saved : (store.value.branches[0]?.id ?? null)
    await loadMenus()
    state.value = 'ready'
  } catch (error) {
    fail(error)
  }
}

async function changeBranch(id: string) {
  branchId.value = id
  if (store.value) writePref(`branch:${store.value.slug}`, id)
  try {
    await loadMenus()
  } catch (error) {
    fail(error)
  }
}

function open(product: ProductSummary) {
  if (product.available) openProduct.value = product.id
}

onMounted(load)
</script>

<template>
  <SPageState v-if="state !== 'ready'" :state="state" class="min-h-dvh" @retry="load" />

  <template v-else-if="store">
    <StoreHero :store="store" :branch="branch" @change-branch="changeBranch" />

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

    <main class="mx-auto max-w-2xl px-4 pb-24 sm:px-6">
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

      <SPageState
        v-if="categories.length === 0"
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
  </template>
</template>
