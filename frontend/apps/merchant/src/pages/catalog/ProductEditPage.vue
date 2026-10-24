<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  ApiError, catalogApi, describeError, type Category, type OptionGroup, type Product, type ProductInput, type VariantInput,
} from '@sertec/api-client'
import { SAlert, SButton, SPageState, SSwitch, STextArea, STextField } from '@sertec/ui'
import { notify } from '../../notify'
import { can } from '../../session'

const props = defineProps<{ id?: string }>()
const router = useRouter()
const manage = can('catalog.manage')

const form = reactive<ProductInput & { variants: VariantInput[] }>({
  name: '', description: '', active: true,
  variants: [{ name: 'Único', price: '' }],
  option_group_ids: [], category_ids: [],
})
const imageUrl = ref<string | null>(null)
const categories = ref<Category[]>([])
const groups = ref<OptionGroup[]>([])
const state = ref<'loading' | 'ready' | 'error' | 'notfound'>('loading')
const saving = ref(false)
const uploading = ref(false)
const errors = ref<Record<string, string[]>>({})
const errorMessage = ref<string | null>(null)
const confirmArchive = ref(false)

const isNew = computed(() => !props.id)
const singlePrice = computed(() => form.variants.length === 1)

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

async function load() {
  state.value = 'loading'
  try {
    const [cats, opts] = await Promise.all([catalogApi.categories(), catalogApi.optionGroups()])
    categories.value = cats
    groups.value = opts
    if (props.id) applyProduct(await catalogApi.product(props.id))
    state.value = 'ready'
  } catch (error) {
    state.value = error instanceof ApiError && error.status === 404 ? 'notfound' : 'error'
  }
}

/** O formulário sempre reflete o que o servidor gravou (IDs das variantes inclusos). */
function applyProduct(p: Product) {
  Object.assign(form, {
    name: p.name, description: p.description ?? '', active: p.active,
    variants: p.variants.map((v) => ({ id: v.id, name: v.name, price: v.price.replace('.', ','), active: v.active })),
    option_group_ids: [...p.option_group_ids], category_ids: [...p.category_ids],
  })
  imageUrl.value = p.image_url
}

function normalizePrice(value: string): string {
  // Aceita "12,5" ou "12.50" e envia sempre "12.50".
  const clean = value.replace(/\s|R\$/g, '').replace(/\.(?=\d{3}(\D|$))/g, '').replace(',', '.')
  const n = Number(clean)
  return Number.isFinite(n) && clean !== '' ? n.toFixed(2) : value
}

function addVariant() {
  if (singlePrice.value && form.variants[0]?.name === 'Único') form.variants[0].name = 'Pequena'
  form.variants.push({ name: '', price: '' })
}

function toggle(list: string[], id: string) {
  const i = list.indexOf(id)
  if (i >= 0) list.splice(i, 1)
  else list.push(id)
}

async function save() {
  saving.value = true
  errors.value = {}
  errorMessage.value = null
  const payload: ProductInput = {
    ...form,
    description: form.description?.trim() || null,
    variants: form.variants.map((v) => ({ ...v, price: normalizePrice(v.price) })),
  }
  try {
    const saved = props.id ? await catalogApi.updateProduct(props.id, payload) : await catalogApi.createProduct(payload)
    applyProduct(saved)
    notify(props.id ? 'Produto salvo' : 'Produto criado')
    if (!props.id) await router.replace(`/cardapio/produtos/${saved.id}`)
  } catch (error) {
    const d = describeError(error)
    errors.value = d.fields ?? {}
    errorMessage.value = d.fields ? 'Confira os campos destacados.' : d.message
  } finally {
    saving.value = false
  }
}

async function onImage(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || !props.id) return
  uploading.value = true
  try {
    const p = await catalogApi.uploadImage(props.id, file)
    imageUrl.value = p.image_url
    notify('Foto atualizada')
  } catch (error) {
    notify(describeError(error).fields?.image?.[0] ?? describeError(error).message, 'error')
  } finally {
    uploading.value = false
    input.value = ''
  }
}

async function removeImage() {
  if (!props.id) return
  await catalogApi.deleteImage(props.id)
  imageUrl.value = null
  notify('Foto removida')
}

async function archive() {
  if (!props.id) return
  await catalogApi.archiveProduct(props.id)
  notify('Produto arquivado')
  await router.replace('/cardapio')
}

onMounted(load)
</script>

<template>
  <RouterLink to="/cardapio" class="text-sm font-medium text-brand-700 hover:underline">Voltar ao cardápio</RouterLink>
  <h1 class="mt-2 mb-6 font-display text-2xl font-bold">{{ isNew ? 'Novo produto' : form.name || 'Produto' }}</h1>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'notfound'" state="empty" title="Produto não encontrado" message="Ele pode ter sido arquivado." />
  <SPageState v-else-if="state === 'error'" state="error" @retry="load" />

  <form v-else class="grid max-w-3xl gap-8" @submit.prevent="save">
    <SAlert v-if="errorMessage">{{ errorMessage }}</SAlert>

    <section class="grid gap-4">
      <STextField v-model="form.name" label="Nome" required :error="fieldError('name')" />
      <STextArea v-model="form.description as string" label="Descrição" :maxlength="2000" hint="Ingredientes, tamanho da porção, quantas pessoas serve." />
      <SSwitch v-model="form.active" label="Visível no cardápio" />
    </section>

    <section>
      <h2 class="mb-1 font-semibold">{{ singlePrice ? 'Preço' : 'Tamanhos e preços' }}</h2>
      <p class="mb-3 text-sm text-muted">
        Em pizzas por sabor, deixe o preço do tamanho em 0,00 e defina o valor de cada sabor em Opções e sabores.
      </p>
      <ul class="grid gap-3">
        <li v-for="(variant, i) in form.variants" :key="variant.id ?? `new-${i}`" class="flex flex-wrap items-end gap-3">
          <div v-if="!singlePrice" class="min-w-40 flex-1">
            <STextField v-model="variant.name" :label="`Tamanho ${i + 1}`" :error="fieldError(`variants.${i}.name`)" />
          </div>
          <div class="w-36">
            <STextField v-model="variant.price" label="Preço (R$)" :error="fieldError(`variants.${i}.price`)" @blur="variant.price = normalizePrice(variant.price).replace('.', ',')" />
          </div>
          <SButton v-if="!singlePrice" variant="ghost" @click="form.variants.splice(i, 1)">Remover</SButton>
        </li>
      </ul>
      <SButton variant="secondary" class="mt-3" @click="addVariant">{{ singlePrice ? 'Vender em tamanhos' : 'Adicionar tamanho' }}</SButton>
    </section>

    <section>
      <h2 class="mb-3 font-semibold">Categorias</h2>
      <p v-if="categories.length === 0" class="text-sm text-muted">Crie categorias na tela do cardápio.</p>
      <div class="flex flex-wrap gap-2">
        <label v-for="c in categories" :key="c.id" class="flex cursor-pointer items-center gap-2 rounded-full border border-line px-3 py-1.5 text-sm has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
          <input type="checkbox" class="accent-brand-600" :checked="form.category_ids.includes(c.id)" @change="toggle(form.category_ids, c.id)">
          {{ c.name }}
        </label>
      </div>
    </section>

    <section>
      <h2 class="mb-3 font-semibold">Opções e sabores</h2>
      <p v-if="groups.length === 0" class="text-sm text-muted">
        Nenhum grupo criado. <RouterLink to="/opcoes/novo" class="font-medium text-brand-700 hover:underline">Criar grupo de opções</RouterLink>
      </p>
      <ul class="grid gap-2">
        <li v-for="g in groups" :key="g.id">
          <label class="flex cursor-pointer items-center gap-3 text-sm">
            <input type="checkbox" class="size-4 accent-brand-600" :checked="form.option_group_ids.includes(g.id)" @change="toggle(form.option_group_ids, g.id)">
            <span><span class="font-medium">{{ g.name }}</span> <span class="text-muted">({{ g.type === 'fraction' ? 'sabores' : 'adicionais' }}, {{ g.options.length }} opções)</span></span>
          </label>
        </li>
      </ul>
    </section>

    <section v-if="!isNew">
      <h2 class="mb-3 font-semibold">Foto</h2>
      <div class="flex flex-wrap items-center gap-4">
        <img v-if="imageUrl" :src="imageUrl" alt="Foto atual do produto" class="size-28 rounded-xl object-cover">
        <label class="cursor-pointer">
          <span class="inline-flex min-h-11 items-center rounded-(--radius-control) border border-line px-4 text-sm font-semibold hover:bg-canvas">
            {{ uploading ? 'Enviando…' : imageUrl ? 'Trocar foto' : 'Enviar foto' }}
          </span>
          <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" :disabled="uploading || !manage" @change="onImage">
        </label>
        <SButton v-if="imageUrl" variant="ghost" @click="removeImage">Remover foto</SButton>
      </div>
      <p class="mt-2 text-sm text-muted">JPG, PNG ou WebP até 5 MB. Fotos quadradas ou 4:3 ficam melhores.</p>
    </section>
    <p v-else class="text-sm text-muted">Salve o produto para enviar a foto.</p>

    <div v-if="manage" class="flex flex-wrap gap-3 border-t border-line pt-6">
      <SButton type="submit" :loading="saving">{{ isNew ? 'Criar produto' : 'Salvar alterações' }}</SButton>
      <template v-if="!isNew">
        <SButton v-if="!confirmArchive" variant="ghost" @click="confirmArchive = true">Arquivar produto</SButton>
        <SButton v-else variant="secondary" @click="archive">Confirmar: arquivar</SButton>
      </template>
    </div>
  </form>
</template>
