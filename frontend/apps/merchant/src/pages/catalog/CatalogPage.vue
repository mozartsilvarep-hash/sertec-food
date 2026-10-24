<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError, catalogApi, describeError, formatMoney, type Category, type Menu, type Product } from '@sertec/api-client'
import { SButton, SPageState, STextField } from '@sertec/ui'
import { loadAllProducts } from '../../catalogStore'
import { notify } from '../../notify'
import { can } from '../../session'

const menus = ref<Menu[]>([])
const categories = ref<Category[]>([])
const products = ref<Product[]>([])
const state = ref<'loading' | 'ready' | 'error' | 'offline'>('loading')
const newCategory = ref('')
const editing = ref<{ id: string; name: string } | null>(null)
const confirmDelete = ref<string | null>(null)
const busy = ref(false)
const manage = computed(() => can('catalog.manage'))

const groups = computed(() => {
  const byCategory = categories.value.map((c) => ({
    category: c,
    products: products.value.filter((p) => p.category_ids.includes(c.id)),
  }))
  const loose = products.value.filter((p) => p.category_ids.length === 0)
  return { byCategory, loose }
})

async function load() {
  state.value = 'loading'
  try {
    ;[menus.value, categories.value, products.value] = await Promise.all([catalogApi.menus(), catalogApi.categories(), loadAllProducts()])
    state.value = 'ready'
  } catch (error) {
    state.value = error instanceof ApiError && error.code === 'network_error' ? 'offline' : 'error'
  }
}

async function addCategory() {
  const name = newCategory.value.trim()
  if (!name) return
  busy.value = true
  try {
    // Primeiro uso: cria o cardápio principal automaticamente.
    const menu = menus.value[0] ?? (await catalogApi.createMenu({ name: 'Cardápio' }))
    if (!menus.value.length) menus.value = [menu]
    const created = await catalogApi.createCategory({ menu_id: menu.id, name, sort_order: categories.value.length })
    categories.value = [...categories.value, created]
    newCategory.value = ''
    notify('Categoria criada')
  } catch (error) {
    notify(describeError(error).message, 'error')
  } finally {
    busy.value = false
  }
}

async function saveName() {
  if (!editing.value) return
  const { id, name } = editing.value
  try {
    const updated = await catalogApi.updateCategory(id, { name: name.trim() })
    categories.value = categories.value.map((c) => (c.id === id ? { ...c, name: updated.name } : c))
    editing.value = null
    notify('Categoria renomeada')
  } catch (error) {
    notify(describeError(error).message, 'error')
  }
}

async function move(index: number, delta: number) {
  const list = [...categories.value]
  const target = index + delta
  if (target < 0 || target >= list.length) return
  ;[list[index], list[target]] = [list[target]!, list[index]!]
  categories.value = list
  try {
    await Promise.all(list.map((c, i) => catalogApi.updateCategory(c.id, { sort_order: i })))
  } catch (error) {
    notify(describeError(error).message, 'error')
  }
}

async function toggleCategory(category: Category) {
  const updated = await catalogApi.updateCategory(category.id, { active: !category.active })
  categories.value = categories.value.map((c) => (c.id === category.id ? { ...c, active: updated.active } : c))
}

async function remove(id: string) {
  try {
    await catalogApi.deleteCategory(id)
    categories.value = categories.value.filter((c) => c.id !== id)
    products.value = products.value.map((p) => ({ ...p, category_ids: p.category_ids.filter((c) => c !== id) }))
    confirmDelete.value = null
    notify('Categoria excluída. Os produtos continuam cadastrados.')
  } catch (error) {
    notify(describeError(error).message, 'error')
  }
}

function priceLabel(product: Product): string {
  const prices = product.variants.filter((v) => v.active).map((v) => Number(v.price))
  if (prices.length === 0) return 'Sem preço ativo'
  const min = Math.min(...prices)
  const max = Math.max(...prices)
  return min === max ? formatMoney(min) : `${formatMoney(min)} a ${formatMoney(max)}`
}

onMounted(load)
</script>

<template>
  <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <h1 class="font-display text-2xl font-bold">Cardápio</h1>
    <RouterLink v-if="manage" to="/cardapio/produtos/novo">
      <SButton tabindex="-1">Novo produto</SButton>
    </RouterLink>
  </div>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'offline'" state="offline" @retry="load" />
  <SPageState v-else-if="state === 'error'" state="error" @retry="load" />

  <template v-else>
    <form v-if="manage" class="mb-8 flex max-w-md items-end gap-2" @submit.prevent="addCategory">
      <div class="flex-1">
        <STextField v-model="newCategory" label="Nova categoria" />
      </div>
      <SButton type="submit" variant="secondary" :loading="busy">Adicionar</SButton>
    </form>

    <SPageState
      v-if="categories.length === 0 && products.length === 0"
      state="empty"
      title="Seu cardápio está vazio"
      message="Crie uma categoria (ex.: Pizzas, Bebidas) e depois cadastre os produtos."
    />

    <section v-for="({ category, products: items }, index) in groups.byCategory" :key="category.id" class="mb-8">
      <div class="mb-2 flex flex-wrap items-center gap-2">
        <form v-if="editing?.id === category.id" class="flex items-end gap-2" @submit.prevent="saveName">
          <STextField v-model="editing.name" label="Nome da categoria" />
          <SButton type="submit">Salvar</SButton>
          <SButton variant="ghost" @click="editing = null">Cancelar</SButton>
        </form>
        <template v-else>
          <h2 class="font-display text-xl font-bold" :class="!category.active && 'text-muted line-through'">{{ category.name }}</h2>
          <span v-if="!category.active" class="text-sm text-muted">oculta no cardápio</span>
          <div v-if="manage" class="ml-auto flex flex-wrap gap-1">
            <SButton variant="ghost" :disabled="index === 0" :aria-label="`Subir ${category.name}`" @click="move(index, -1)">↑</SButton>
            <SButton variant="ghost" :disabled="index === groups.byCategory.length - 1" :aria-label="`Descer ${category.name}`" @click="move(index, 1)">↓</SButton>
            <SButton variant="ghost" @click="editing = { id: category.id, name: category.name }">Renomear</SButton>
            <SButton variant="ghost" @click="toggleCategory(category)">{{ category.active ? 'Ocultar' : 'Mostrar' }}</SButton>
            <SButton v-if="confirmDelete !== category.id" variant="ghost" @click="confirmDelete = category.id">Excluir</SButton>
            <SButton v-else variant="primary" @click="remove(category.id)">Confirmar exclusão</SButton>
          </div>
        </template>
      </div>

      <p v-if="items.length === 0" class="rounded-(--radius-card) border border-dashed border-line px-4 py-6 text-center text-sm text-muted">
        Nenhum produto nesta categoria.
      </p>
      <ul v-else class="divide-y divide-line rounded-(--radius-card) border border-line bg-surface">
        <li v-for="product in items" :key="product.id">
          <RouterLink :to="`/cardapio/produtos/${product.id}`" class="flex items-center gap-4 px-4 py-3 hover:bg-canvas">
            <img v-if="product.image_url" :src="product.image_url" alt="" class="size-12 rounded-lg object-cover">
            <span v-else class="size-12 rounded-lg bg-canvas" aria-hidden="true" />
            <span class="min-w-0 flex-1">
              <span class="block font-medium text-ink" :class="!product.active && 'text-muted'">{{ product.name }}</span>
              <span class="block text-sm text-muted">{{ priceLabel(product) }}<template v-if="!product.active"> · inativo</template></span>
            </span>
          </RouterLink>
        </li>
      </ul>
    </section>

    <section v-if="groups.loose.length" class="mb-8">
      <h2 class="mb-2 font-display text-xl font-bold">Fora do cardápio</h2>
      <p class="mb-2 text-sm text-muted">Produtos sem categoria não aparecem para os clientes.</p>
      <ul class="divide-y divide-line rounded-(--radius-card) border border-line bg-surface">
        <li v-for="product in groups.loose" :key="product.id">
          <RouterLink :to="`/cardapio/produtos/${product.id}`" class="block px-4 py-3 hover:bg-canvas">{{ product.name }}</RouterLink>
        </li>
      </ul>
    </section>
  </template>
</template>
