import { request } from './http'

type Data<T> = { data: T }
const enc = encodeURIComponent

/* ---------- Carrinho (público) ---------- */

export interface CartLine {
  id: string
  product_id: string | null
  product_name: string
  variant_name: string | null
  quantity: number
  notes: string | null
  options: { group_name: string; option_name: string; quantity: number }[]
  unit_price: string | null
  line_total: string | null
  valid: boolean
  problem: { reason: string; message: string } | null
}

export interface CartView {
  token?: string
  branch_id: string
  expires_at: string
  items: CartLine[]
  subtotal: string
  valid: boolean
  count: number
}

export interface AddToCart {
  product_id: string
  variant_id: string
  options: { option: string; quantity: number }[]
  quantity: number
  notes?: string | null
}

export const cartApi = {
  create: (branchId?: string | null) => request<Data<CartView & { token: string }>>('/carts', { method: 'POST', body: { branch_id: branchId ?? null } }).then((r) => r.data),
  get: (token: string) => request<Data<CartView>>(`/carts/${enc(token)}`).then((r) => r.data),
  add: (token: string, item: AddToCart) => request<Data<CartView>>(`/carts/${enc(token)}/items`, { method: 'POST', body: item }).then((r) => r.data),
  update: (token: string, itemId: string, body: { quantity?: number; notes?: string | null }) =>
    request<Data<CartView>>(`/carts/${enc(token)}/items/${enc(itemId)}`, { method: 'PATCH', body }).then((r) => r.data),
  remove: (token: string, itemId: string) => request<Data<CartView>>(`/carts/${enc(token)}/items/${enc(itemId)}`, { method: 'DELETE' }).then((r) => r.data),
}

/* ---------- Checkout e acompanhamento (público) ---------- */

export type FulfillmentType = 'pickup' | 'delivery'
export type PaymentMethod = 'cash' | 'card_on_delivery'

export interface CheckoutPayload {
  cart_token: string
  customer: { name: string; phone: string; email?: string | null }
  fulfillment: {
    type: FulfillmentType
    zone_id?: string | null
    address?: { street: string; number: string; complement?: string | null; neighborhood: string; reference?: string | null } | null
  }
  payment: { method: PaymentMethod; change_for?: string | null }
  notes?: string | null
}

export interface CheckoutResult {
  order_id: string
  order_number: number
  status: string
  payment_status: string
  payment_method: PaymentMethod
  fulfillment_type: FulfillmentType
  total: string
  eta_minutes: number | null
  tracking_token: string
}

export interface OrderLine {
  name: string
  variant: string
  quantity: number
  unit_price: string
  line_total: string
  notes: string | null
  options: { group: string; name: string; quantity: number }[]
}

export interface TrackedOrder {
  id: string
  number: number
  status: string
  status_label: string
  payment_status: string
  payment_method: PaymentMethod
  payment_method_label: string
  fulfillment_type: FulfillmentType
  placed_at: string
  eta_minutes: number | null
  customer_first_name: string | null
  customer_phone_masked: string
  neighborhood: string | null
  branch: { name: string; phone: string | null }
  cancellation_reason: string | null
  items: OrderLine[]
  totals: { code: string; label: string; amount: string }[]
  timeline: { status: string; label: string; at: string }[]
  final: boolean
  steps: { status: string; label: string }[]
}

export const orderApi = {
  checkout: (payload: CheckoutPayload, idempotencyKey: string) =>
    request<Data<CheckoutResult>>('/checkout', { method: 'POST', body: payload, headers: { 'Idempotency-Key': idempotencyKey } }).then((r) => r.data),
  track: (token: string) => request<Data<TrackedOrder>>(`/orders/track/${enc(token)}`).then((r) => r.data),
}

/* ---------- Painel: pedidos e entrega ---------- */

export type OrderStatus = 'pending_acceptance' | 'accepted' | 'preparing' | 'ready' | 'out_for_delivery' | 'completed' | 'rejected' | 'cancelled'
export type CancellationReason = 'store_closed' | 'out_of_stock' | 'out_of_area' | 'customer_request' | 'customer_unreachable' | 'other'

export interface MerchantOrder {
  id: string
  number: number
  branch: { id: string; name: string }
  status: OrderStatus
  status_label: string
  next_statuses: { status: OrderStatus; label: string }[]
  payment_status: string
  payment_method: PaymentMethod
  payment_method_label: string
  change_for: string | null
  fulfillment_type: FulfillmentType
  customer: { name: string; phone: string }
  address: { street?: string; number?: string; complement?: string | null; neighborhood?: string; reference?: string | null } | null
  delivery_zone: string | null
  notes: string | null
  subtotal: string
  delivery_fee: string
  total: string
  eta_minutes: number | null
  placed_at: string
  accepted_at: string | null
  cancellation_reason: string | null
  cancellation_note: string | null
  items?: OrderLine[]
  totals?: { code: string; label: string; amount: string }[]
  history?: { from: string | null; to: string; label: string; note: string | null; at: string }[]
}

export interface DeliveryZoneInput {
  id?: string | null
  name: string
  neighborhoods: string[]
  fee: string
  minimum_order: string | null
  free_above: string | null
  eta_minutes: number
  active: boolean
}

export interface DeliverySettings {
  branch_id?: string
  pickup_enabled: boolean
  delivery_enabled: boolean
  pickup_eta_minutes: number
  zones: (DeliveryZoneInput & { id: string })[]
}

export const merchantOrdersApi = {
  list: (view: 'active' | 'finished' | 'all' = 'active', cursor?: string | null) =>
    request<{ data: MerchantOrder[]; meta: { next_cursor: string | null } }>(`/merchant/orders?view=${view}&per_page=50${cursor ? `&cursor=${enc(cursor)}` : ''}`),
  get: (id: string) => request<Data<MerchantOrder>>(`/merchant/orders/${enc(id)}`).then((r) => r.data),
  setStatus: (id: string, body: { status: OrderStatus; eta_minutes?: number | null; reason?: CancellationReason | null; note?: string | null }) =>
    request<Data<MerchantOrder>>(`/merchant/orders/${enc(id)}/status`, { method: 'PATCH', body }).then((r) => r.data),
  delivery: (branch: string) => request<Data<DeliverySettings>>(`/merchant/branches/${enc(branch)}/delivery`).then((r) => r.data),
  saveDelivery: (branch: string, body: Omit<DeliverySettings, 'branch_id' | 'zones'> & { zones: DeliveryZoneInput[] }) =>
    request<Data<DeliverySettings>>(`/merchant/branches/${enc(branch)}/delivery`, { method: 'PUT', body }).then((r) => r.data),
}

export const CANCELLATION_REASONS: { value: CancellationReason; label: string }[] = [
  { value: 'out_of_stock', label: 'Item indisponível' },
  { value: 'store_closed', label: 'Estabelecimento fechado' },
  { value: 'out_of_area', label: 'Endereço fora da área de entrega' },
  { value: 'customer_request', label: 'A pedido do cliente' },
  { value: 'customer_unreachable', label: 'Não conseguimos contato' },
  { value: 'other', label: 'Outro motivo' },
]
