import { request } from './http'

type Data<T> = { data: T }

/* ---------- Tipos públicos (cardápio) ---------- */

export interface PublicZone {
  id: string
  name: string
  neighborhoods: string[]
  fee: string
  minimum_order: string | null
  free_above: string | null
  eta_minutes: number
}

export interface BranchStatus {
  id: string
  name: string
  phone: string | null
  address: Record<string, string> | null
  has_hours: boolean
  open: boolean
  closes_at: string | null
  next_opening_at: string | null
  fulfillment?: { pickup_enabled: boolean; delivery_enabled: boolean; pickup_eta_minutes: number; zones: PublicZone[] }
}

export interface StorefrontInfo {
  name: string
  slug: string
  timezone: string
  description: string | null
  brand_color: string | null
  branches: BranchStatus[]
  /** Formas aceitas; Pix e cartão pelo site só aparecem com o Mercado Pago conectado. */
  payment_methods?: { method: 'cash' | 'card_on_delivery' | 'pix' | 'online_card'; label: string; online: boolean }[]
  announcement?: Announcement | null
}

export interface ProductSummary {
  id: string
  name: string
  description: string | null
  image_url: string | null
  /** "combo": itens escolhidos entre produtos do cardápio, preço fixo. */
  kind?: 'item' | 'combo'
  available: boolean
  /** "Só sáb. e dom.", "Das 11h às 15h" (null = sempre). */
  availability?: string | null
  /** Combo: soma dos itens avulsos, para mostrar a economia. */
  compare_at?: string | null
  has_options: boolean
  price_from: string | null
  /** "A partir de" já com a promoção (exibição; o servidor recalcula). */
  promo_price_from?: string | null
  promotion?: ProductPromotion | null
}

export interface ProductPromotion {
  id: string
  /** percent: desconto no preço; bundle: "leve N, pague M" (aplicado no carrinho). */
  type?: 'percent' | 'bundle'
  percent_bp: number
  buy_quantity?: number | null
  pay_quantity?: number | null
  label: string
  featured: boolean
  ends_at: string | null
}

export interface Announcement {
  text: string
  link: string | null
  link_label: string | null
  starts_at: string | null
  ends_at: string | null
}

export interface PublicMenu {
  id: string
  name: string
  categories: { id: string; name: string; description: string | null; products: ProductSummary[] }[]
}

export type GroupType = 'addon' | 'fraction'
export type PricingRule = 'highest' | 'average'

export interface PublicOption {
  id: string
  name: string
  description: string | null
  price_delta: string
  max_quantity: number
  /** Itens de combo seguem o produto (esgotado, fora do dia). */
  available?: boolean
  /** Só em grupos "fraction": ULID da variante => preço do sabor. */
  prices: Record<string, string> | null
}

export interface PublicOptionGroup {
  id: string
  name: string
  type: GroupType
  min_select: number
  max_select: number
  required: boolean
  free_quantity: number
  pricing_rule: PricingRule | null
  options: PublicOption[]
}

export interface PublicProduct extends ProductSummary {
  variants: { id: string; name: string; price: string }[]
  option_groups: PublicOptionGroup[]
}

export const publicCatalogApi = {
  storefront: () => request<Data<StorefrontInfo>>('/storefront').then((r) => r.data),
  menus: (branch?: string) =>
    request<Data<{ branch_id: string; menus: PublicMenu[] }>>(`/menus${branch ? `?branch=${encodeURIComponent(branch)}` : ''}`).then((r) => r.data),
  product: (id: string, branch?: string) =>
    request<Data<PublicProduct>>(`/products/${encodeURIComponent(id)}${branch ? `?branch=${encodeURIComponent(branch)}` : ''}`).then((r) => r.data),
}

/* ---------- Tipos do painel (gestão) ---------- */

export interface Menu { id: string; name: string; branch_id: string | null; sort_order: number; active: boolean }
export interface Category { id: string; menu_id: string; name: string; description: string | null; sort_order: number; active: boolean; products_count?: number }
export interface VariantInput { id?: string | null; name: string; price: string; active?: boolean }
export interface Product {
  id: string
  name: string
  description: string | null
  image_url: string | null
  active: boolean
  variants: (VariantInput & { id: string; active: boolean })[]
  option_group_ids: string[]
  category_ids: string[]
  kind: ProductKind
  available_days: number[] | null
  available_from: string | null
  available_until: string | null
  availability: string | null
  combo_slots: ComboSlot[]
}
export type ProductKind = 'item' | 'combo'
/** Espaço do combo: "Escolha 2 bolos de pote" entre estes produtos. */
export interface ComboSlot {
  id?: string | null
  name: string
  quantity: number
  product_ids: string[]
}
export interface ProductInput {
  name: string
  description: string | null
  active: boolean
  variants: VariantInput[]
  option_group_ids: string[]
  category_ids: string[]
  kind?: ProductKind
  /** 0 = domingo … 6 = sábado; vazio = todos os dias. */
  available_days?: number[] | null
  available_from?: string | null
  available_until?: string | null
  combo_slots?: ComboSlot[] | null
}
export interface OptionInput {
  id?: string | null
  name: string
  description?: string | null
  price_delta?: string
  max_quantity?: number
  active?: boolean
  variant_prices?: { variant: string; price: string }[]
}
export interface OptionGroup {
  id: string
  name: string
  type: GroupType
  min_select: number
  max_select: number
  required: boolean
  free_quantity: number
  pricing_rule: PricingRule | null
  active: boolean
  options: (Required<Pick<OptionInput, 'name' | 'price_delta' | 'max_quantity' | 'active'>> & {
    id: string
    description: string | null
    variant_prices: { variant: string; price: string }[]
  })[]
}
export interface OptionGroupInput {
  name: string
  type: GroupType
  min_select: number
  max_select: number
  free_quantity: number
  pricing_rule: PricingRule | null
  active: boolean
  options: OptionInput[]
}
export interface HoursPeriod { weekday: number; opens_at: string; closes_at: string }
export interface ScheduleException { date: string; closed: boolean; opens_at: string | null; closes_at: string | null; note: string | null }
export interface BranchHours {
  branch_id: string
  timezone: string
  weekly: HoursPeriod[]
  exceptions: ScheduleException[]
  status: { open: boolean; closes_at: string | null; next_opening_at: string | null }
}
export interface TenantSettings { name: string; slug: string; timezone: string; description: string | null; brand_color: string | null }
export interface Paginated<T> { data: T[]; meta: { next_cursor: string | null; prev_cursor: string | null; per_page: number } }

const m = '/merchant'
const enc = encodeURIComponent

export const catalogApi = {
  menus: () => request<Data<Menu[]>>(`${m}/menus`).then((r) => r.data),
  createMenu: (body: Partial<Menu>) => request<Data<Menu>>(`${m}/menus`, { method: 'POST', body }).then((r) => r.data),

  categories: () => request<Data<Category[]>>(`${m}/categories`).then((r) => r.data),
  createCategory: (body: { menu_id: string; name: string; description?: string | null; sort_order?: number }) =>
    request<Data<Category>>(`${m}/categories`, { method: 'POST', body }).then((r) => r.data),
  updateCategory: (id: string, body: Partial<Category>) =>
    request<Data<Category>>(`${m}/categories/${enc(id)}`, { method: 'PATCH', body }).then((r) => r.data),
  deleteCategory: (id: string) => request<void>(`${m}/categories/${enc(id)}`, { method: 'DELETE' }),

  products: (params: { search?: string; cursor?: string | null; per_page?: number } = {}) => {
    const q = new URLSearchParams()
    if (params.search) q.set('search', params.search)
    if (params.cursor) q.set('cursor', params.cursor)
    q.set('per_page', String(params.per_page ?? 100))
    return request<Paginated<Product>>(`${m}/products?${q.toString()}`)
  },
  product: (id: string) => request<Data<Product>>(`${m}/products/${enc(id)}`).then((r) => r.data),
  createProduct: (body: ProductInput) => request<Data<Product>>(`${m}/products`, { method: 'POST', body }).then((r) => r.data),
  updateProduct: (id: string, body: ProductInput) =>
    request<Data<Product>>(`${m}/products/${enc(id)}`, { method: 'PUT', body }).then((r) => r.data),
  archiveProduct: (id: string) => request<void>(`${m}/products/${enc(id)}`, { method: 'DELETE' }),
  uploadImage: (id: string, file: File) =>
    request<Data<Product>>(`${m}/products/${enc(id)}/image`, { method: 'POST', form: { image: file } }).then((r) => r.data),
  deleteImage: (id: string) => request<void>(`${m}/products/${enc(id)}/image`, { method: 'DELETE' }),

  optionGroups: () => request<Data<OptionGroup[]>>(`${m}/option-groups`).then((r) => r.data),
  optionGroup: (id: string) => request<Data<OptionGroup>>(`${m}/option-groups/${enc(id)}`).then((r) => r.data),
  createOptionGroup: (body: OptionGroupInput) =>
    request<Data<OptionGroup>>(`${m}/option-groups`, { method: 'POST', body }).then((r) => r.data),
  updateOptionGroup: (id: string, body: OptionGroupInput) =>
    request<Data<OptionGroup>>(`${m}/option-groups/${enc(id)}`, { method: 'PUT', body }).then((r) => r.data),
  deleteOptionGroup: (id: string) => request<void>(`${m}/option-groups/${enc(id)}`, { method: 'DELETE' }),

  soldOut: (branch: string) =>
    request<Data<{ branch_id: string; sold_out_product_ids: string[] }>>(`${m}/branches/${enc(branch)}/availability`).then((r) => r.data),
  setAvailability: (branch: string, product: string, available: boolean) =>
    request<Data<{ available: boolean }>>(`${m}/branches/${enc(branch)}/availability/${enc(product)}`, { method: 'PUT', body: { available } }).then((r) => r.data),

  hours: (branch: string) => request<Data<BranchHours>>(`${m}/branches/${enc(branch)}/hours`).then((r) => r.data),
  saveHours: (branch: string, body: { weekly: HoursPeriod[]; exceptions: ScheduleException[] }) =>
    request<Data<BranchHours>>(`${m}/branches/${enc(branch)}/hours`, { method: 'PUT', body }).then((r) => r.data),

  settings: () => request<Data<TenantSettings>>(`${m}/settings`).then((r) => r.data),
  saveSettings: (body: { description?: string | null; brand_color?: string | null }) =>
    request<Data<TenantSettings>>(`${m}/settings`, { method: 'PATCH', body }).then((r) => r.data),
}
