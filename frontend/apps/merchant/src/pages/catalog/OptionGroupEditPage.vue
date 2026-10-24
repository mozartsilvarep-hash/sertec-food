<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  ApiError, catalogApi, describeError, type GroupType, type OptionGroup, type OptionGroupInput, type PricingRule, type Product,
} from '@sertec/api-client'
import { SAlert, SButton, SPageState, SSwitch, STextField } from '@sertec/ui'
import { loadAllProducts } from '../../catalogStore'
import { notify } from '../../notify'
import { can } from '../../session'

interface OptionRow {
  id?: string | null
  name: string
  price_delta: string
  max_quantity: number
  active: boolean
  /** variantId => preço (sabores) */
  prices: Record<string, string>
}

const props = defineProps<{ id?: string }>()
const router = useRouter()
const manage = can('catalog.manage')

const form = reactive({
  name: '', type: 'addon' as GroupType, min_select: 0, max_select: 1, free_quantity: 0,
  pricing_rule: 'highest' as PricingRule, active: true,
})
const options = ref<OptionRow[]>([{ name: '', price_delta: '0.00', max_quantity: 1, active: true, prices: {} }])
const products = ref<Product[]>([])
const columns = ref<{ variantId: string; label: string }[]>([])
const addFrom = ref('')
const state = ref<'loading' | 'ready' | 'error' | 'notfound'>('loading')
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const errorMessage = ref<string | null>(null)
const confirmDelete = ref(false)

const isFraction = computed(() => form.type === 'fraction')
const isNew = computed(() => !props.id)
const err = (k: string) => errors.value[k]?.[0] ?? null

function columnsFor(product: Product) {
  return product.variants.map((v) => ({ variantId: v.id, label: product.variants.length > 1 ? `${product.name} ${v.name}` : product.name }))
}

function addColumns(product: Product | undefined) {
  if (!product) return
  for (const col of columnsFor(product)) {
    if (!columns.value.some((c) => c.variantId === col.variantId)) columns.value.push(col)
  }
}

async function load() {
  state.value = 'loading'
  try {
    products.value = await loadAllProducts()
    if (props.id) {
      const g = await catalogApi.optionGroup(props.id)
      applyGroup(g)
      for (const p of products.value.filter((p) => p.option_group_ids.includes(g.id))) addColumns(p)
      // Tamanhos com preço cadastrado de produtos não vinculados também aparecem.
      for (const p of products.value) {
        if (p.variants.some((v) => options.value.some((o) => o.prices[v.id] !== undefined))) addColumns(p)
      }
    }
    state.value = 'ready'
  } catch (error) {
    state.value = error instanceof ApiError && error.status === 404 ? 'notfound' : 'error'
  }
}

function applyGroup(g: OptionGroup) {
  Object.assign(form, {
    name: g.name, type: g.type, min_select: g.min_select, max_select: g.max_select,
    free_quantity: g.free_quantity, pricing_rule: g.pricing_rule ?? 'highest', active: g.active,
  })
  options.value = g.options.map((o) => ({
    id: o.id, name: o.name, price_delta: o.price_delta, max_quantity: o.max_quantity, active: o.active,
    prices: Object.fromEntries(o.variant_prices.map((p) => [p.variant, p.price])),
  }))
}

function money(value: string): string {
  const n = Number(value.replace(',', '.'))
  return value.trim() !== '' && Number.isFinite(n) ? n.toFixed(2) : value
}

async function save() {
  saving.value = true
  errors.value = {}
  errorMessage.value = null
  const payload: OptionGroupInput = {
    ...form,
    free_quantity: isFraction.value ? 0 : form.free_quantity,
    pricing_rule: isFraction.value ? form.pricing_rule : null,
    options: options.value.map((o) => ({
      id: o.id ?? null,
      name: o.name,
      price_delta: isFraction.value ? '0.00' : money(o.price_delta),
      max_quantity: isFraction.value ? 1 : o.max_quantity,
      active: o.active,
      variant_prices: isFraction.value
        ? Object.entries(o.prices).filter(([, v]) => v.trim() !== '').map(([variant, price]) => ({ variant, price: money(price) }))
        : [],
    })),
  }
  try {
    const saved = props.id ? await catalogApi.updateOptionGroup(props.id, payload) : await catalogApi.createOptionGroup(payload)
    applyGroup(saved)
    notify(props.id ? 'Grupo salvo' : 'Grupo criado')
    if (!props.id) await router.replace(`/opcoes/${saved.id}`)
  } catch (error) {
    const d = describeError(error)
    errors.value = d.fields ?? {}
    errorMessage.value = d.fields ? 'Confira os campos destacados.' : d.message
  } finally {
    saving.value = false
  }
}

async function remove() {
  if (!props.id) return
  await catalogApi.deleteOptionGroup(props.id)
  notify('Grupo excluído')
  await router.replace('/opcoes')
}

onMounted(load)
</script>

<template>
  <RouterLink to="/opcoes" class="text-sm font-medium text-brand-700 hover:underline">Voltar às opções</RouterLink>
  <h1 class="mt-2 mb-6 font-display text-2xl font-bold">{{ isNew ? 'Novo grupo de opções' : form.name }}</h1>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'notfound'" state="empty" title="Grupo não encontrado" />
  <SPageState v-else-if="state === 'error'" state="error" @retry="load" />

  <form v-else class="grid max-w-4xl gap-8" @submit.prevent="save">
    <SAlert v-if="errorMessage">{{ errorMessage }}</SAlert>

    <section class="grid gap-4 sm:max-w-md">
      <STextField v-model="form.name" label="Nome do grupo" :error="err('name')" />
      <fieldset>
        <legend class="mb-2 text-sm font-medium">Tipo</legend>
        <div class="grid gap-2">
          <label class="flex gap-3 rounded-(--radius-control) border border-line p-3 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
            <input v-model="form.type" type="radio" value="addon" class="mt-1 accent-brand-600">
            <span><span class="block font-medium">Adicionais</span><span class="text-sm text-muted">Cada opção soma um valor: bordas, extras, molhos.</span></span>
          </label>
          <label class="flex gap-3 rounded-(--radius-control) border border-line p-3 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
            <input v-model="form.type" type="radio" value="fraction" class="mt-1 accent-brand-600">
            <span><span class="block font-medium">Sabores de pizza (meio a meio)</span><span class="text-sm text-muted">Cada sabor tem preço por tamanho; o cliente escolhe 1 ou mais.</span></span>
          </label>
        </div>
      </fieldset>
    </section>

    <section class="grid gap-4 sm:grid-cols-3">
      <template v-if="isFraction">
        <STextField :model-value="String(form.max_select)" label="Máximo de sabores" :error="err('max_select')" @update:model-value="form.max_select = Number($event) || 1; form.min_select = 1" />
        <fieldset class="sm:col-span-2">
          <legend class="mb-2 text-sm font-medium">Preço da pizza com mais de um sabor</legend>
          <label class="mr-4 inline-flex items-center gap-2 text-sm"><input v-model="form.pricing_rule" type="radio" value="highest" class="accent-brand-600"> Sabor mais caro</label>
          <label class="inline-flex items-center gap-2 text-sm"><input v-model="form.pricing_rule" type="radio" value="average" class="accent-brand-600"> Média dos sabores</label>
        </fieldset>
      </template>
      <template v-else>
        <STextField :model-value="String(form.min_select)" label="Mínimo (0 = opcional)" :error="err('min_select')" @update:model-value="form.min_select = Number($event) || 0" />
        <STextField :model-value="String(form.max_select)" label="Máximo" :error="err('max_select')" @update:model-value="form.max_select = Number($event) || 1" />
        <STextField :model-value="String(form.free_quantity)" label="Quantos grátis" @update:model-value="form.free_quantity = Number($event) || 0" />
      </template>
      <div class="sm:col-span-3"><SSwitch v-model="form.active" label="Grupo ativo" /></div>
    </section>

    <section>
      <h2 class="mb-3 font-semibold">{{ isFraction ? 'Sabores e preços por tamanho' : 'Opções' }}</h2>

      <div v-if="isFraction" class="mb-4 flex flex-wrap items-end gap-2">
        <label class="grid gap-1.5 text-sm font-medium">
          Mostrar tamanhos do produto
          <select v-model="addFrom" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3">
            <option value="">Escolha um produto</option>
            <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </label>
        <SButton variant="secondary" :disabled="!addFrom" @click="addColumns(products.find((p) => p.id === addFrom)); addFrom = ''">Adicionar colunas</SButton>
        <p v-if="columns.length === 0" class="w-full text-sm text-muted">Escolha o produto pizza para informar o preço de cada sabor em cada tamanho. Deixe em branco o tamanho em que o sabor não existe.</p>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[36rem] text-sm">
          <thead>
            <tr class="text-left text-muted">
              <th class="py-2 pr-3 font-medium">Nome</th>
              <template v-if="isFraction">
                <th v-for="c in columns" :key="c.variantId" class="py-2 pr-3 font-medium">{{ c.label }} (R$)</th>
              </template>
              <template v-else>
                <th class="py-2 pr-3 font-medium">Acréscimo (R$)</th>
                <th class="py-2 pr-3 font-medium">Qtd. máx.</th>
              </template>
              <th class="py-2 pr-3 font-medium">Ativo</th>
              <th />
            </tr>
          </thead>
          <tbody>
            <tr v-for="(o, i) in options" :key="o.id ?? `n${i}`" class="border-t border-line">
              <td class="py-2 pr-3"><input v-model="o.name" :aria-label="`Nome da opção ${i + 1}`" class="min-h-10 w-full rounded-lg border px-2" :class="err(`options.${i}.name`) ? 'border-danger' : 'border-line'"></td>
              <template v-if="isFraction">
                <td v-for="c in columns" :key="c.variantId" class="py-2 pr-3">
                  <input
                    :value="o.prices[c.variantId] ?? ''"
                    inputmode="decimal"
                    :aria-label="`Preço de ${o.name || 'sabor'} em ${c.label}`"
                    placeholder="—"
                    class="min-h-10 w-24 rounded-lg border border-line px-2 tabular-nums"
                    @input="o.prices[c.variantId] = ($event.target as HTMLInputElement).value"
                  >
                </td>
              </template>
              <template v-else>
                <td class="py-2 pr-3"><input v-model="o.price_delta" inputmode="decimal" :aria-label="`Acréscimo de ${o.name || 'opção'}`" class="min-h-10 w-24 rounded-lg border border-line px-2 tabular-nums"></td>
                <td class="py-2 pr-3"><input v-model.number="o.max_quantity" type="number" min="1" max="20" :aria-label="`Quantidade máxima de ${o.name || 'opção'}`" class="min-h-10 w-20 rounded-lg border border-line px-2"></td>
              </template>
              <td class="py-2 pr-3"><input v-model="o.active" type="checkbox" class="size-4 accent-brand-600" :aria-label="`${o.name || 'Opção'} ativa`"></td>
              <td class="py-2"><SButton variant="ghost" :disabled="options.length === 1" @click="options.splice(i, 1)">Remover</SButton></td>
            </tr>
          </tbody>
        </table>
      </div>
      <SButton variant="secondary" class="mt-3" @click="options.push({ name: '', price_delta: '0.00', max_quantity: 1, active: true, prices: {} })">
        {{ isFraction ? 'Adicionar sabor' : 'Adicionar opção' }}
      </SButton>
    </section>

    <div v-if="manage" class="flex flex-wrap gap-3 border-t border-line pt-6">
      <SButton type="submit" :loading="saving">{{ isNew ? 'Criar grupo' : 'Salvar alterações' }}</SButton>
      <template v-if="!isNew">
        <SButton v-if="!confirmDelete" variant="ghost" @click="confirmDelete = true">Excluir grupo</SButton>
        <SButton v-else variant="secondary" @click="remove">Confirmar exclusão</SButton>
      </template>
    </div>
  </form>
</template>
