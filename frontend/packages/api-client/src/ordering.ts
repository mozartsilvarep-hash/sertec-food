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
export type PaymentMethod = 'cash' | 'card_on_delivery' | 'pix' | 'online_card'

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
  payment_required: boolean
  payment_expires_at: string | null
  tracking_token: string
}

/** Cobrança online vista pelo cliente (Pix ou Checkout Pro). */
export interface PublicPayment {
  status: string
  amount: string
  expires_at: string
  payable: boolean
  needs_start: boolean
  rejected: boolean
  pix: { qr_code: string; qr_code_base64: string | null; ticket_url: string | null } | null
  checkout_url: string | null
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
  payment: PublicPayment | null
  final: boolean
  steps: { status: string; label: string }[]
}

export const orderApi = {
  checkout: (payload: CheckoutPayload, idempotencyKey: string) =>
    request<Data<CheckoutResult>>('/checkout', { method: 'POST', body: payload, headers: { 'Idempotency-Key': idempotencyKey } }).then((r) => r.data),
  track: (token: string) => request<Data<TrackedOrder>>(`/orders/track/${enc(token)}`).then((r) => r.data),
  /** Gera a cobrança de novo (se falhou) ou consulta o provedor ("Já paguei"). */
  refreshPayment: (token: string) => request<Data<TrackedOrder>>(`/orders/track/${enc(token)}/payment`, { method: 'POST' }).then((r) => r.data),
}

/* ---------- Painel: pedidos e entrega ---------- */

export type OrderStatus = 'awaiting_payment' | 'pending_acceptance' | 'accepted' | 'preparing' | 'ready' | 'out_for_delivery' | 'completed' | 'rejected' | 'cancelled'
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
  online_payment?: MerchantOnlinePayment | null
}

export interface MerchantOnlinePayment {
  status: string | null
  amount: string | null
  amount_refunded: string | null
  refundable: string
  approved_at: string | null
  provider_reference: string | null
  attempts: { id: string; status: string; provider_status: string | null; provider_status_detail: string | null; created_at: string }[]
  refunds: { id: string; amount: string; status: string; reason: string | null; created_at: string }[]
}

export interface MercadoPagoSettings {
  provider: 'mercadopago'
  connected: boolean
  mode: 'test' | 'live' | null
  account_label: string | null
  account_id: string | null
  token_hint: string | null
  has_webhook_secret: boolean
  verified_at: string | null
  webhook_url: string
}

export const paymentsApi = {
  settings: () => request<Data<MercadoPagoSettings>>('/merchant/payments/mercadopago').then((r) => r.data),
  connect: (body: { access_token?: string | null; webhook_secret?: string | null; remove_webhook_secret?: boolean }) =>
    request<Data<MercadoPagoSettings>>('/merchant/payments/mercadopago', { method: 'PUT', body }).then((r) => r.data),
  disconnect: () => request<Data<MercadoPagoSettings>>('/merchant/payments/mercadopago', { method: 'DELETE' }).then((r) => r.data),
  refund: (orderId: string, body: { amount?: string | null; reason?: string | null }) =>
    request<Data<{ refund: { id: string; amount: string; status: string }; order: MerchantOrder }>>(`/merchant/orders/${enc(orderId)}/refunds`, { method: 'POST', body }).then((r) => r.data),
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

export interface OrderFilter {
  from?: string | null
  to?: string | null
  q?: string | null
  payment_method?: PaymentMethod | null
  branch?: string | null
}

/** Monta a query string ignorando filtros vazios. */
export function filterQuery(params: Record<string, string | number | null | undefined>): string {
  const qs = Object.entries(params)
    .filter(([, v]) => v !== null && v !== undefined && v !== '')
    .map(([k, v]) => `${enc(k)}=${enc(String(v))}`)
    .join('&')
  return qs ? `?${qs}` : ''
}

export interface ReportSummary {
  orders: number
  completed: number
  cancelled: number
  in_progress: number
  revenue: string
  average_ticket: string
  refunded: string
  by_payment_method: { method: PaymentMethod; label: string; orders: number; total: string }[]
}

export interface AuditEntry {
  id: string
  action: string
  actor: { id: string; name: string } | null
  entity_type: string | null
  entity_id: string | null
  metadata: Record<string, unknown> | null
  ip: string | null
  at: string
}

export const reportsApi = {
  summary: (filter: OrderFilter) => request<Data<ReportSummary>>(`/merchant/reports/summary${filterQuery({ ...filter })}`).then((r) => r.data),
  /** Link direto (download pelo navegador, com a sessão). */
  csvUrl: (filter: OrderFilter & { view?: string }) => `/api/v1/merchant/reports/orders.csv${filterQuery({ ...filter })}`,
}

export const auditApi = {
  list: (cursor?: string | null, action?: string | null) =>
    request<{ data: AuditEntry[]; meta: { next_cursor: string | null } }>(`/merchant/audit${filterQuery({ cursor, action, per_page: 50 })}`),
}

export const merchantOrdersApi = {
  list: (view: 'active' | 'finished' | 'all' | string = 'active', cursor?: string | null, filter: OrderFilter = {}) =>
    request<{ data: MerchantOrder[]; meta: { next_cursor: string | null } }>(`/merchant/orders${filterQuery({ view, per_page: 50, cursor, ...filter })}`),
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
