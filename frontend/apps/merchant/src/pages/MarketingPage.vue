<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import {
  ApiError, catalogApi, describeError, formatMoney, marketingApi,
  type CouponInput, type CouponType, type FeedSettings, type MerchantCoupon, type MerchantPromotion, type Product,
} from '@sertec/api-client'
import { SAlert, SButton, SPageState, STextField } from '@sertec/ui'
import LoyaltyPanel from '../components/LoyaltyPanel.vue'
import { notify } from '../notify'

/**
 * Promoções (desconto por produto), cupons e vitrine (faixa de aviso e feed
 * de promoções para o site do restaurante).
 */
type Tab = 'promotions' | 'coupons' | 'loyalty' | 'feed'
const tab = ref<Tab>('promotions')
const state = ref<'loading' | 'ready' | 'error' | 'offline'>('loading')
const promotions = ref<MerchantPromotion[]>([])
const coupons = ref<MerchantCoupon[] | null>(null) // null = plano sem cupons
const feed = ref<FeedSettings | null>(null)
const products = ref<Product[]>([])
const errors = ref<Record<string, string[]>>({})
const message = ref<string | null>(null)
const saving = ref(false)
const copied = ref(false)

const STATE_LABEL: Record<MerchantPromotion['state'], [string, string]> = {
  running: ['No ar', 'bg-success/10 text-success'],
  scheduled: ['Agendada', 'bg-brand-50 text-brand-700'],
  ended: ['Encerrada', 'bg-line text-muted'],
  paused: ['Pausada', 'bg-line text-muted'],
}
const COUPON_LABEL: Record<CouponType, string> = { percent: '% de desconto', fixed: 'Valor fixo', free_delivery: 'Entrega grátis' }

async function load() {
  state.value = 'loading'
  try {
    const [p, f, prods] = await Promise.all([marketingApi.promotions(), marketingApi.feed(), catalogApi.products({ per_page: 100 })])
    promotions.value = p
    feed.value = f
    products.value = prods.data.filter((x) => x.active)
    try {
      coupons.value = await marketingApi.coupons()
    } catch (e) {
      if (e instanceof ApiError && e.status === 403) coupons.value = null
      else throw e
    }
    Object.assign(feedForm, {
      text: f.announcement?.text ?? '', link: f.announcement?.link ?? '', link_label: f.announcement?.link_label ?? '',
      starts_at: f.announcement?.starts_at ?? '', ends_at: f.announcement?.ends_at ?? '', origins: f.feed_origins.join('\n'),
    })
    state.value = 'ready'
  } catch (e) {
    state.value = e instanceof ApiError && e.code === 'network_error' ? 'offline' : 'error'
  }
}

function fail(e: unknown) {
  const d = describeError(e)
  errors.value = d.fields ?? {}
  message.value = d.fields ? 'Confira os campos destacados.' : d.message
}
const err = (k: string) => errors.value[k]?.[0] ?? null

/* ---------- Promoções ---------- */
const promoOpen = ref(false)
const promo = reactive({ product_id: '', mode: 'price' as 'price' | 'percent' | 'bundle', promo_price: '', percent: '', buy: '2', pay: '1', starts_at: '', ends_at: '', badge: '', featured: false })

async function savePromotion() {
  saving.value = true
  errors.value = {}
  message.value = null
  try {
    const created = await marketingApi.createPromotion({
      product_id: promo.product_id,
      type: promo.mode === 'bundle' ? 'bundle' : 'percent',
      buy_quantity: promo.mode === 'bundle' ? Number(promo.buy) || null : null,
      pay_quantity: promo.mode === 'bundle' ? Number(promo.pay) || null : null,
      promo_price: promo.mode === 'price' ? promo.promo_price : null,
      percent: promo.mode === 'percent' ? promo.percent : null,
      starts_at: promo.starts_at || null, ends_at: promo.ends_at || null,
      badge: promo.badge.trim() || null, featured: promo.featured,
    })
    promotions.value.unshift(created)
    promoOpen.value = false
    Object.assign(promo, { product_id: '', promo_price: '', percent: '', starts_at: '', ends_at: '', badge: '', featured: false })
    notify(`Promoção criada: ${created.product.name} ${created.label}`)
  } catch (e) {
    fail(e)
  } finally {
    saving.value = false
  }
}

async function togglePromotion(p: MerchantPromotion) {
  try {
    const updated = await marketingApi.updatePromotion(p.id, { active: !p.active })
    promotions.value = promotions.value.map((x) => (x.id === p.id ? updated : x))
  } catch (e) {
    notify(describeError(e).message, 'error')
  }
}

async function removePromotion(p: MerchantPromotion) {
  await marketingApi.deletePromotion(p.id)
  promotions.value = promotions.value.filter((x) => x.id !== p.id)
  notify('Promoção removida')
}

/* ---------- Cupons ---------- */
const couponOpen = ref(false)
const cform = reactive({
  code: '', description: '', discount_type: 'percent' as CouponType, percent: '', amount: '', max_discount: '', min_subtotal: '',
  starts_at: '', ends_at: '', usage_limit: '', per_customer_limit: '', first_order_only: false,
})

async function saveCoupon() {
  saving.value = true
  errors.value = {}
  message.value = null
  const body: CouponInput = {
    code: cform.code.trim(), description: cform.description.trim() || null, discount_type: cform.discount_type,
    percent: cform.discount_type === 'percent' ? cform.percent : null,
    amount: cform.discount_type === 'fixed' ? cform.amount : null,
    max_discount: cform.discount_type === 'percent' ? cform.max_discount || null : null,
    min_subtotal: cform.min_subtotal || null, starts_at: cform.starts_at || null, ends_at: cform.ends_at || null,
    usage_limit: cform.usage_limit ? Number(cform.usage_limit) : null,
    per_customer_limit: cform.per_customer_limit ? Number(cform.per_customer_limit) : null,
    first_order_only: cform.first_order_only,
  }
  try {
    const created = await marketingApi.createCoupon(body)
    coupons.value = [created, ...(coupons.value ?? [])]
    couponOpen.value = false
    Object.assign(cform, { code: '', description: '', percent: '', amount: '', max_discount: '', min_subtotal: '', starts_at: '', ends_at: '', usage_limit: '', per_customer_limit: '', first_order_only: false })
    notify(`Cupom ${created.code} criado`)
  } catch (e) {
    fail(e)
  } finally {
    saving.value = false
  }
}

async function toggleCoupon(c: MerchantCoupon) {
  try {
    const updated = await marketingApi.updateCoupon(c.id, { active: !c.active })
    coupons.value = (coupons.value ?? []).map((x) => (x.id === c.id ? { ...updated, discount_given: c.discount_given } : x))
  } catch (e) {
    notify(describeError(e).message, 'error')
  }
}

function couponValue(c: MerchantCoupon): string {
  if (c.discount_type === 'percent') return `${c.percent}%${c.max_discount ? ` (até ${formatMoney(c.max_discount)})` : ''}`
  if (c.discount_type === 'fixed') return formatMoney(c.amount)
  return 'Entrega grátis'
}

/* ---------- Vitrine ---------- */
const feedForm = reactive({ text: '', link: '', link_label: '', starts_at: '', ends_at: '', origins: '' })

async function saveFeed() {
  saving.value = true
  errors.value = {}
  message.value = null
  try {
    feed.value = await marketingApi.saveFeed({
      announcement: feedForm.text.trim() ? {
        text: feedForm.text.trim(), link: feedForm.link.trim() || null, link_label: feedForm.link_label.trim() || null,
        starts_at: feedForm.starts_at || null, ends_at: feedForm.ends_at || null,
      } : null,
      feed_origins: feedForm.origins.split(/\s+/).filter(Boolean),
    })
    notify('Vitrine atualizada')
  } catch (e) {
    fail(e)
  } finally {
    saving.value = false
  }
}

async function copyFeed() {
  if (!feed.value) return
  try {
    await navigator.clipboard.writeText(feed.value.feed_url)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    /* sem permissão: o endereço continua visível */
  }
}

const tabs = computed(() => [
  { key: 'promotions' as const, label: 'Promoções' },
  { key: 'coupons' as const, label: 'Cupons' },
  { key: 'loyalty' as const, label: 'Fidelidade' },
  { key: 'feed' as const, label: 'Vitrine e aviso' },
])
const input = 'min-h-11 w-full rounded-(--radius-control) border border-line bg-surface px-3'

onMounted(load)
</script>

<template>
  <h1 class="mb-4 font-display text-2xl font-bold">Promoções</h1>
  <div class="mb-6 inline-flex rounded-full border border-line bg-surface p-1" role="tablist">
    <button
      v-for="t in tabs" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key"
      class="rounded-full px-4 py-1.5 text-sm font-medium" :class="tab === t.key ? 'bg-ink text-white' : 'text-muted'"
      @click="tab = t.key; message = null; errors = {}"
    >
      {{ t.label }}
    </button>
  </div>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'offline'" state="offline" @retry="load" />
  <SPageState v-else-if="state === 'error'" state="error" @retry="load" />

  <template v-else>
    <SAlert v-if="message" class="mb-4 max-w-3xl">{{ message }}</SAlert>

    <!-- Promoções -->
    <section v-if="tab === 'promotions'" class="max-w-4xl" aria-label="Promoções">
      <p class="mb-4 text-muted">O desconto aparece no cardápio (preço riscado e selo) e vale no carrinho e no pedido. Uma promoção por produto em cada período.</p>
      <SButton v-if="!promoOpen" class="mb-4" @click="promoOpen = true">Nova promoção</SButton>
      <form v-else class="mb-6 grid gap-4 rounded-(--radius-card) border border-line bg-surface p-5" novalidate @submit.prevent="savePromotion">
        <label class="grid gap-1.5 text-sm font-medium">Produto
          <select v-model="promo.product_id" :class="input">
            <option value="" disabled>Escolha o produto</option>
            <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
          <span v-if="err('product_id')" class="text-danger">{{ err('product_id') }}</span>
        </label>
        <fieldset class="grid gap-2">
          <legend class="mb-1 text-sm font-medium">Desconto</legend>
          <div class="flex flex-wrap gap-4 text-sm">
            <label class="inline-flex items-center gap-2"><input v-model="promo.mode" type="radio" value="price" class="accent-brand-600"> Preço promocional ("de … por …")</label>
            <label class="inline-flex items-center gap-2"><input v-model="promo.mode" type="radio" value="percent" class="accent-brand-600"> Percentual</label>
            <label class="inline-flex items-center gap-2"><input v-model="promo.mode" type="radio" value="bundle" class="accent-brand-600"> Leve mais, pague menos</label>
          </div>
          <div v-if="promo.mode === 'bundle'" class="flex flex-wrap items-end gap-3">
            <div class="w-28"><STextField v-model="promo.buy" label="Leve" inputmode="numeric" :error="err('buy_quantity')" /></div>
            <div class="w-28"><STextField v-model="promo.pay" label="Pague" inputmode="numeric" :error="err('pay_quantity')" /></div>
            <SButton variant="ghost" @click="promo.buy = '2'; promo.pay = '1'">Compre 1, leve outro</SButton>
            <SButton variant="ghost" @click="promo.buy = '3'; promo.pay = '2'">Leve 3, pague 2</SButton>
          </div>
          <div v-else class="w-48">
            <STextField v-if="promo.mode === 'price'" v-model="promo.promo_price" label="Por (R$)" :error="err('promo_price')" />
            <STextField v-else v-model="promo.percent" label="Desconto (%)" :error="err('percent')" />
          </div>
          <p v-if="promo.mode === 'bundle'" class="text-sm text-muted">No carrinho, a cada {{ promo.buy || 'N' }} unidades do produto o cliente paga {{ promo.pay || 'M' }}. As unidades mais baratas saem de graça.</p>
          <p v-else class="text-sm text-muted">No preço promocional, o desconto é calculado sobre o “a partir de” do produto e vale também para os outros tamanhos e adicionais.</p>
        </fieldset>
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="grid gap-1.5 text-sm font-medium">Início (opcional)<input v-model="promo.starts_at" type="datetime-local" :class="input"></label>
          <label class="grid gap-1.5 text-sm font-medium">Fim (opcional)<input v-model="promo.ends_at" type="datetime-local" :class="input"><span v-if="err('ends_at')" class="text-danger">{{ err('ends_at') }}</span></label>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <STextField v-model="promo.badge" label="Selo (opcional, ex.: Novidade)" :error="err('badge')" />
          <label class="inline-flex items-center gap-2 self-end pb-3 text-sm"><input v-model="promo.featured" type="checkbox" class="accent-brand-600"> Destaque (aparece primeiro no site)</label>
        </div>
        <div class="flex gap-2">
          <SButton type="submit" :loading="saving">Criar promoção</SButton>
          <SButton variant="ghost" @click="promoOpen = false">Cancelar</SButton>
        </div>
      </form>

      <SPageState v-if="promotions.length === 0" state="empty" title="Nenhuma promoção" message="Crie a primeira para destacar um produto." />
      <ul v-else class="divide-y divide-line rounded-(--radius-card) border border-line bg-surface">
        <li v-for="p in promotions" :key="p.id" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
          <div>
            <p class="font-semibold">{{ p.product.name }} <span class="ml-1 rounded-full bg-success px-2 py-0.5 text-xs font-bold text-white">{{ p.label }}</span><span v-if="p.featured" class="ml-1 text-xs text-brand-700">★ destaque</span></p>
            <p class="text-sm text-muted">
              <span class="mr-2 rounded-full px-2 py-0.5 text-xs font-semibold" :class="STATE_LABEL[p.state][1]">{{ STATE_LABEL[p.state][0] }}</span>
              {{ p.starts_at ? `de ${new Date(p.starts_at).toLocaleString('pt-BR')}` : 'desde já' }} {{ p.ends_at ? `até ${new Date(p.ends_at).toLocaleString('pt-BR')}` : 'sem data para acabar' }}
            </p>
          </div>
          <div class="flex gap-2">
            <SButton variant="secondary" @click="togglePromotion(p)">{{ p.active ? 'Pausar' : 'Reativar' }}</SButton>
            <SButton variant="ghost" @click="removePromotion(p)">Remover</SButton>
          </div>
        </li>
      </ul>
    </section>

    <!-- Cupons -->
    <section v-else-if="tab === 'coupons'" class="max-w-4xl" aria-label="Cupons">
      <SPageState v-if="coupons === null" state="empty" title="Cupons não estão no seu plano" message="Fale com a SerTec para liberar cupons de desconto." />
      <template v-else>
        <p class="mb-4 text-muted">O cliente digita o código no checkout. Cancelar ou recusar o pedido devolve o uso.</p>
        <SButton v-if="!couponOpen" class="mb-4" @click="couponOpen = true">Novo cupom</SButton>
        <form v-else class="mb-6 grid gap-4 rounded-(--radius-card) border border-line bg-surface p-5" novalidate @submit.prevent="saveCoupon">
          <div class="grid gap-4 sm:grid-cols-2">
            <STextField v-model="cform.code" label="Código (ex.: BEMVINDO10)" :error="err('code')" />
            <STextField v-model="cform.description" label="Descrição para o cliente (opcional)" :error="err('description')" />
          </div>
          <fieldset>
            <legend class="mb-2 text-sm font-medium">Tipo</legend>
            <div class="flex flex-wrap gap-4 text-sm">
              <label v-for="(label, key) in COUPON_LABEL" :key="key" class="inline-flex items-center gap-2"><input v-model="cform.discount_type" type="radio" :value="key" class="accent-brand-600"> {{ label }}</label>
            </div>
          </fieldset>
          <div class="grid gap-4 sm:grid-cols-3">
            <STextField v-if="cform.discount_type === 'percent'" v-model="cform.percent" label="Desconto (%)" :error="err('percent')" />
            <STextField v-if="cform.discount_type === 'percent'" v-model="cform.max_discount" label="Desconto máximo (R$, opcional)" :error="err('max_discount')" />
            <STextField v-if="cform.discount_type === 'fixed'" v-model="cform.amount" label="Valor (R$)" :error="err('amount')" />
            <STextField v-model="cform.min_subtotal" label="Pedido mínimo (R$, opcional)" :error="err('min_subtotal')" />
          </div>
          <div class="grid gap-4 sm:grid-cols-4">
            <label class="grid gap-1.5 text-sm font-medium">Início<input v-model="cform.starts_at" type="datetime-local" :class="input"></label>
            <label class="grid gap-1.5 text-sm font-medium">Fim<input v-model="cform.ends_at" type="datetime-local" :class="input"></label>
            <label class="grid gap-1.5 text-sm font-medium">Usos no total<input v-model="cform.usage_limit" type="number" min="1" placeholder="sem limite" :class="input"></label>
            <label class="grid gap-1.5 text-sm font-medium">Usos por cliente<input v-model="cform.per_customer_limit" type="number" min="1" placeholder="sem limite" :class="input"></label>
          </div>
          <label class="inline-flex items-center gap-2 text-sm"><input v-model="cform.first_order_only" type="checkbox" class="accent-brand-600"> Só no primeiro pedido do cliente (pelo celular)</label>
          <div class="flex gap-2">
            <SButton type="submit" :loading="saving">Criar cupom</SButton>
            <SButton variant="ghost" @click="couponOpen = false">Cancelar</SButton>
          </div>
        </form>

        <SPageState v-if="coupons.length === 0" state="empty" title="Nenhum cupom" message="Crie um cupom para divulgar." />
        <div v-else class="overflow-x-auto rounded-(--radius-card) border border-line bg-surface">
          <table class="w-full min-w-[40rem] text-sm">
            <thead class="border-b border-line text-left text-muted">
              <tr><th scope="col" class="px-4 py-3 font-medium">Código</th><th scope="col" class="px-4 py-3 font-medium">Desconto</th><th scope="col" class="px-4 py-3 font-medium">Regras</th><th scope="col" class="px-4 py-3 text-right font-medium">Usos</th><th scope="col" class="px-4 py-3"><span class="sr-only">Ações</span></th></tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="c in coupons" :key="c.id" :class="!c.active && 'text-muted'">
                <td class="px-4 py-3 font-mono font-semibold">{{ c.code }}<span v-if="!c.active" class="ml-2 font-sans text-xs">(desativado)</span></td>
                <td class="px-4 py-3">{{ couponValue(c) }}</td>
                <td class="px-4 py-3 text-muted">
                  <template v-if="c.min_subtotal">mín. {{ formatMoney(c.min_subtotal) }} · </template>
                  <template v-if="c.first_order_only">1º pedido · </template>
                  <template v-if="c.per_customer_limit">{{ c.per_customer_limit }}×/cliente · </template>
                  {{ c.ends_at ? `até ${new Date(c.ends_at).toLocaleDateString('pt-BR')}` : 'sem validade' }}
                </td>
                <td class="px-4 py-3 text-right tabular-nums">{{ c.times_used }}{{ c.usage_limit ? ` / ${c.usage_limit}` : '' }}<span class="block text-xs text-muted">{{ formatMoney(c.discount_given) }} em descontos</span></td>
                <td class="px-4 py-3 text-right"><SButton variant="ghost" @click="toggleCoupon(c)">{{ c.active ? 'Desativar' : 'Reativar' }}</SButton></td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </section>

    <!-- Fidelidade -->
    <LoyaltyPanel v-else-if="tab === 'loyalty'" />

    <!-- Vitrine -->
    <form v-else-if="feed" class="grid max-w-3xl gap-6" novalidate aria-label="Vitrine e aviso" @submit.prevent="saveFeed">
      <fieldset class="grid gap-4 rounded-(--radius-card) border border-line bg-surface p-5">
        <legend class="px-1 font-semibold">Faixa de aviso <span v-if="feed.announcement_active" class="ml-2 rounded-full bg-success/10 px-2 py-0.5 text-xs font-semibold text-success">no ar</span></legend>
        <p class="text-sm text-muted">Aparece no topo do cardápio e no site do restaurante. Ex.: “Encomendas de Natal abertas até 15 de dezembro”.</p>
        <STextField v-model="feedForm.text" label="Texto (até 160 caracteres)" :error="err('announcement.text')" />
        <div class="grid gap-4 sm:grid-cols-[1fr_12rem]">
          <STextField v-model="feedForm.link" label="Link (https, opcional)" :error="err('announcement.link')" />
          <STextField v-model="feedForm.link_label" label="Texto do link" :error="err('announcement.link_label')" />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="grid gap-1.5 text-sm font-medium">Início<input v-model="feedForm.starts_at" type="datetime-local" :class="input"></label>
          <label class="grid gap-1.5 text-sm font-medium">Fim<input v-model="feedForm.ends_at" type="datetime-local" :class="input"><span v-if="err('announcement.ends_at')" class="text-danger">{{ err('announcement.ends_at') }}</span></label>
        </div>
      </fieldset>

      <fieldset class="grid gap-4 rounded-(--radius-card) border border-line bg-surface p-5">
        <legend class="px-1 font-semibold">Promoções no seu site</legend>
        <p class="text-sm text-muted">O site institucional do restaurante pode mostrar as promoções e a faixa automaticamente, lendo este endereço (formato “contrato v1”):</p>
        <div class="flex items-center gap-2">
          <code class="min-w-0 flex-1 truncate rounded-lg bg-canvas px-3 py-2 text-sm">{{ feed.feed_url }}</code>
          <SButton variant="secondary" @click="copyFeed">{{ copied ? 'Copiado!' : 'Copiar' }}</SButton>
        </div>
        <label class="grid gap-1.5 text-sm font-medium">Sites autorizados (um por linha)
          <textarea v-model="feedForm.origins" rows="3" placeholder="https://www.seurestaurante.com.br" class="rounded-(--radius-control) border border-line bg-surface px-3 py-2 font-mono text-sm" />
          <span class="font-normal text-muted">Só esses endereços conseguem ler o feed pelo navegador. Use https e sem caminho.</span>
          <span v-for="(m, k) in errors" v-show="String(k).startsWith('feed_origins')" :key="k" class="text-danger">{{ m[0] }}</span>
        </label>
      </fieldset>
      <div><SButton type="submit" :loading="saving">Salvar vitrine</SButton></div>
    </form>
  </template>
</template>
