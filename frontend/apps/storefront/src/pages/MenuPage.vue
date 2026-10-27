<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError, formatMoney, publicCatalogApi, type ProductSummary, type PublicMenu } from '@sertec/api-client'
import { SPageState } from '@sertec/ui'
import CartBar from '../components/CartBar.vue'
import ProductRow from '../components/ProductRow.vue'
import ProductSheet from '../components/ProductSheet.vue'
import HighlightStrip from '../components/HighlightStrip.vue'
import StoreFooter from '../components/StoreFooter.vue'
import StoreHero from '../components/StoreHero.vue'
import { highlights } from '../identity'
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

const featured = computed(() => (search.value ? [] : highlights(menus.value)))

// Categoria visível no momento: destaca o atalho e o mantém à vista na barra.
// A última seção cujo título já passou da barra fixa; no fim da página, a última.
const active = ref<string | null>(null)
let ticking = false
function updateActive() {
  ticking = false
  const sections = Array.from(document.querySelectorAll<HTMLElement>('section[id^="cat-"]'))
  if (!sections.length) return
  const atBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4
  let current: HTMLElement | null = atBottom ? sections[sections.length - 1]! : null
  if (!current) for (const s of sections) if (s.getBoundingClientRect().top <= 140) current = s
  const id = current ? current.id.slice(4) : null
  if (id === active.value) return
  active.value = id
  if (id) document.getElementById(`chip-${id}`)?.scrollIntoView({ block: 'nearest', inline: 'center' })
}
function onScroll() {
  if (!ticking) {
    ticking = true
    requestAnimationFrame(updateActive)
  }
}
onMounted(() => window.addEventListener('scroll', onScroll, { passive: true }))
onBeforeUnmount(() => window.removeEventListener('scroll', onScroll))

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

// Link profundo (feed de promoções / compartilhamento): /?produto=ID abre o produto.
const route = useRoute()
const router = useRouter()
function openFromLink() {
  const id = typeof route.query.produto === 'string' ? route.query.produto : null
  if (!id) return
  const found = menus.value.flatMap((m) => m.categories).flatMap((c) => c.products).find((p) => p.id === id)
  if (found) open(found)
  void router.replace({ query: {} })
}

watch(branchId, (id, old) => { if (old && id !== old) void load() })
onMounted(async () => {
  await load()
  openFromLink()
})
</script>

<template>
  <template v-if="store">
    <p v-if="store.announcement" class="bg-ink px-4 py-2.5 text-center text-sm text-white" role="note">
      {{ store.announcement.text }}
      <a v-if="store.announcement.link" :href="store.announcement.link" class="ml-1 font-semibold whitespace-nowrap underline underline-offset-2">{{ store.announcement.link_label ?? 'Saiba mais' }}</a>
    </p>
    <StoreHero :store="store" :branch="branch" @change-branch="selectBranch" />
    <div class="mx-auto grid max-w-2xl gap-2 px-4 pb-2 sm:px-6">
      <p v-if="store.loyalty" class="flex items-start gap-3 rounded-2xl border border-brand-100 bg-brand-50 px-4 py-3 text-sm">
        <svg viewBox="0 0 24 24" class="mt-0.5 size-5 shrink-0 text-brand-700" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="9" r="6" /><path d="m8.5 14.5-1.5 7 5-3 5 3-1.5-7" /></svg>
        <span><strong>Cartão fidelidade:</strong> a cada {{ store.loyalty.stamps_required }} pedidos<template v-if="store.loyalty.min_order && Number(store.loyalty.min_order) > 0"> acima de {{ formatMoney(store.loyalty.min_order) }}</template>, ganhe {{ store.loyalty.reward_label }}.</span>
      </p>
      <RouterLink
        :to="recentOrder ? `/pedido/${recentOrder}` : '/acompanhar'"
        class="flex min-h-11 items-center justify-between rounded-2xl border border-line px-4 text-sm font-semibold text-brand-700 hover:bg-canvas"
      >
        {{ recentOrder ? 'Acompanhar meu último pedido' : 'Já pediu? Acompanhe seu pedido' }}
        <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="m9 6 6 6-6 6" /></svg>
      </RouterLink>
    </div>

    <nav
      v-if="categories.length > 1 && !search"
      class="sticky top-0 z-10 border-b border-line bg-surface/95 backdrop-blur"
      aria-label="Categorias"
    >
      <ul class="mx-auto flex max-w-2xl gap-1.5 overflow-x-auto px-3 py-2.5 [scrollbar-width:none] sm:px-5">
        <li v-for="c in categories" :id="`chip-${c.id}`" :key="c.id">
          <a
            :href="`#cat-${c.id}`"
            class="block rounded-full px-3.5 py-1.5 text-sm font-semibold whitespace-nowrap transition-colors"
            :class="active === c.id ? 'bg-brand-600 text-brand-contrast' : 'text-muted hover:bg-canvas hover:text-ink'"
            :aria-current="active === c.id ? 'true' : undefined"
          >
            {{ c.name }}
          </a>
        </li>
      </ul>
    </nav>

    <main class="mx-auto max-w-2xl px-4 sm:px-6">
      <HighlightStrip v-if="featured.length" :products="featured" @open="open" />

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

      <StoreFooter :store="store" :branch="branch" />
    </main>

    <ProductSheet :product-id="openProduct" :branch-id="branchId" @close="openProduct = null" />
    <CartBar />
  </template>
</template>
