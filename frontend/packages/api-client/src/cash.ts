import { request } from './http'
import { filterQuery } from './ordering'

type Data<T> = { data: T }
const enc = encodeURIComponent

/* ---------- Caixa (mini PDV) ---------- */

export type CashMethod = 'cash' | 'pix' | 'debit' | 'credit' | 'card' | 'online' | 'other'
export type CashCategory =
  | 'counter_sale' | 'order' | 'supply' | 'other_in'
  | 'withdrawal' | 'supplier' | 'staff' | 'bills' | 'refund' | 'other_out'

export const CASH_METHODS: { value: CashMethod; label: string }[] = [
  { value: 'cash', label: 'Dinheiro' },
  { value: 'pix', label: 'Pix' },
  { value: 'debit', label: 'Débito' },
  { value: 'credit', label: 'Crédito' },
  { value: 'other', label: 'Outro' },
]

/** Lançamentos manuais (as vendas e os pedidos entram sozinhos). */
export const CASH_MANUAL: { value: CashCategory; label: string; type: 'in' | 'out'; drawerOnly?: boolean }[] = [
  { value: 'supply', label: 'Reforço de caixa', type: 'in', drawerOnly: true },
  { value: 'other_in', label: 'Outra entrada', type: 'in' },
  { value: 'withdrawal', label: 'Retirada (sangria)', type: 'out', drawerOnly: true },
  { value: 'supplier', label: 'Fornecedores e insumos', type: 'out' },
  { value: 'staff', label: 'Funcionários', type: 'out' },
  { value: 'bills', label: 'Contas (água, luz, aluguel…)', type: 'out' },
  { value: 'other_out', label: 'Outra saída', type: 'out' },
]

export interface CashMethodTotal { method: CashMethod; label: string; in: string; out: string }

export interface CashSession {
  id: string
  branch: { id: string; name: string }
  opened_at: string
  opened_by: string | null
  opening_amount: string
  open: boolean
  closed_at: string | null
  closed_by: string | null
  expected_amount: string
  counted_amount: string | null
  difference: string | null
  notes: string | null
  by_method?: CashMethodTotal[]
}

export interface CashEntry {
  id: string
  type: 'in' | 'out'
  category: CashCategory
  category_label: string
  method: CashMethod
  method_label: string
  amount: string
  description: string | null
  items: { name: string; quantity: number; unit_price: string; total: string }[] | null
  order_number: number | null
  occurred_at: string
  created_by: string | null
  voided: boolean
  void_reason: string | null
  change?: string | null
}

export interface SaleLine {
  product_id?: string | null
  variant_id?: string | null
  description?: string | null
  unit_price?: string | null
  quantity: number
}

export interface CashSummary {
  in: string
  out: string
  balance: string
  by_method: CashMethodTotal[]
  by_category: { category: CashCategory; label: string; type: 'in' | 'out'; count: number; total: string }[]
  by_day: { date: string; in: string; out: string }[]
}

export interface CashFilter { branch?: string | null; from?: string | null; to?: string | null; session?: string | null }

export const cashApi = {
  current: (branch: string | null) => request<Data<CashSession | null>>(`/merchant/cash/session${filterQuery({ branch })}`).then((r) => r.data),
  open: (branch: string | null, opening_amount: string) =>
    request<Data<CashSession>>('/merchant/cash/session', { method: 'POST', body: { branch, opening_amount } }).then((r) => r.data),
  close: (id: string, counted_amount: string, notes: string | null) =>
    request<Data<CashSession>>(`/merchant/cash/session/${enc(id)}/close`, { method: 'POST', body: { counted_amount, notes } }).then((r) => r.data),
  entry: (body: { branch: string | null; category: CashCategory; method: CashMethod; amount: string; description?: string | null }) =>
    request<Data<CashEntry>>('/merchant/cash/entries', { method: 'POST', body }).then((r) => r.data),
  sale: (body: { branch: string | null; items: SaleLine[]; method: CashMethod; discount?: string | null; received?: string | null; note?: string | null }) =>
    request<Data<CashEntry>>('/merchant/cash/sales', { method: 'POST', body }).then((r) => r.data),
  void: (id: string, reason: string) => request<Data<CashEntry>>(`/merchant/cash/entries/${enc(id)}/void`, { method: 'POST', body: { reason } }).then((r) => r.data),
  entries: (filter: CashFilter) => request<Data<CashEntry[]>>(`/merchant/cash/entries${filterQuery({ ...filter })}`).then((r) => r.data),
  summary: (filter: CashFilter) => request<Data<CashSummary>>(`/merchant/cash/summary${filterQuery({ ...filter })}`).then((r) => r.data),
  sessions: (branch: string | null) => request<Data<CashSession[]>>(`/merchant/cash/sessions${filterQuery({ branch })}`).then((r) => r.data),
  csvUrl: (filter: CashFilter) => `/api/v1/merchant/cash/entries.csv${filterQuery({ ...filter })}`,
}

/* ---------- PDV (Sprint 14) ---------- */

export type PosMethod = 'cash' | 'pix' | 'debit' | 'credit' | 'other'

export interface PosCatalog {
  branch_id: string
  menus: import('./catalog').PublicMenu[]
  /** id do produto → código e favorito. */
  products: Record<string, { code: string | null; favorite: boolean; quick_variant: string | null }>
  /** Mais vendidos nos últimos 30 dias (ids). */
  top: string[]
  methods: { method: PosMethod; label: string }[]
}

export interface PosLine {
  product_id?: string
  variant_id?: string
  options?: { option: string; quantity: number }[]
  description?: string
  unit_price?: string
  quantity: number
  notes?: string | null
}

export interface PosSalePayload {
  branch?: string | null
  items: PosLine[]
  customer?: { name?: string | null; phone?: string | null } | null
  use_loyalty?: boolean
  discount?: { type: 'amount' | 'percent'; value: string } | null
  service_percent?: number | null
  payments: { method: PosMethod; amount: string }[]
  cash_received?: string | null
  send_to_kitchen?: boolean
  notes?: string | null
}

export interface PosSaleResult { order_id: string; order_number: number; status: string; total: string; change: string | null }

export interface PosCustomer {
  phone: string
  name: string | null
  loyalty: import('./catalog').LoyaltyStatus | null
}

export const posApi = {
  catalog: (branch?: string | null) =>
    request<Data<PosCatalog>>(`/merchant/cash/pos/catalog${filterQuery({ branch })}`).then((r) => r.data),
  customer: (phone: string) =>
    request<Data<PosCustomer>>(`/merchant/cash/pos/customer?phone=${enc(phone)}`).then((r) => r.data),
  sale: (payload: PosSalePayload, idempotencyKey: string) =>
    request<Data<PosSaleResult>>('/merchant/cash/pos/sales', { method: 'POST', body: payload, headers: { 'Idempotency-Key': idempotencyKey } }).then((r) => r.data),
}
