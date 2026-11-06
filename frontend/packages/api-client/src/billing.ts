import { request } from './http'

type Data<T> = { data: T }
const enc = encodeURIComponent

/* ---------- Mensalidade SerTec (Sprint 13) ---------- */

export type InvoiceStatus = 'open' | 'paid' | 'cancelled'

export interface Invoice {
  id: string
  plan_name: string
  months: number
  amount: string
  /** Composição: um item por módulo (ou o valor próprio). */
  lines: { label: string; amount: string }[]
  period_start: string
  period_end: string
  due_date: string
  status: InvoiceStatus
  overdue: boolean
  paid_at: string | null
  paid_via: 'pix' | 'manual' | null
  note: string | null
  pix: { code: string; qr_base64: string | null; expires_at: string | null } | null
}

export interface BillingSubscription {
  plan: { slug: string; name: string }
  status: 'trialing' | 'active' | 'past_due' | 'cancelled'
  billing_months: number
  period_label: string
  modules: ModuleKey[]
  /** Plano (se tiver preço) + cada módulo; amount null = sem preço no período. */
  lines: { label: string; amount: string | null; module: ModuleKey | null }[]
  /** Soma do catálogo (sem o valor próprio). */
  catalog_price: string | null
  custom_price: string | null
  /** null = plano sem preço para o período; "0.00" = cortesia. */
  price: string | null
  paid_until: string | null
  /** Bloqueia sozinho 7 dias após vencer (a SerTec pode desligar por cliente). */
  auto_block?: boolean
  blocked_at?: string | null
  /** Dia em que bloqueia, se houver fatura em aberto. */
  block_date?: string | null
}

/** Módulos vendidos separadamente. */
export type ModuleKey = 'menu' | 'pos' | 'tabs' | 'stock'

export const MODULES: { key: ModuleKey; label: string; requires?: ModuleKey }[] = [
  { key: 'menu', label: 'Cardápio digital' },
  { key: 'pos', label: 'PDV e caixa' },
  { key: 'tabs', label: 'Mesas e comandas', requires: 'pos' },
  { key: 'stock', label: 'Estoque' },
]

export interface AdminModule {
  key: ModuleKey
  name: string
  description: string | null
  active: boolean
  requires: ModuleKey | null
  prices: Record<string, string | null>
  tenants: number
}

export interface AdminPlan {
  slug: string
  name: string
  description: string | null
  active: boolean
  monthly_price: string
  prices: Record<string, string | null>
  features: Record<string, boolean>
  tenants: number
}

export interface PlanMeta {
  periods: { months: number; label: string }[]
  features: { key: string; label: string }[]
}

export interface BillingProviderSettings {
  connected: boolean
  mode: 'test' | 'live' | null
  account_label: string | null
  account_id: string | null
  token_hint: string | null
  has_webhook_secret: boolean
  verified_at: string | null
  webhook_url: string
}

export interface AdminInvoice extends Invoice {
  tenant: { id: string; name: string; status: string }
}

export interface TenantBilling {
  subscription: BillingSubscription | null
  invoices: Invoice[]
}

export const PERIOD_LABELS: Record<number, string> = { 1: 'Mensal', 3: 'Trimestral', 6: 'Semestral', 12: 'Anual' }

export const adminBillingApi = {
  plans: () => request<{ data: AdminPlan[]; meta: PlanMeta }>('/admin/billing/plans'),
  modules: () => request<{ data: AdminModule[]; meta: { periods: { months: number; label: string }[] } }>('/admin/billing/modules'),
  updateModule: (key: ModuleKey, body: Partial<Pick<AdminModule, 'name' | 'description' | 'active' | 'prices'>>) =>
    request<Data<AdminModule>>(`/admin/billing/modules/${enc(key)}`, { method: 'PATCH', body }).then((r) => r.data),
  createPlan: (body: Partial<Omit<AdminPlan, 'tenants' | 'monthly_price'>>) =>
    request<Data<AdminPlan>>('/admin/billing/plans', { method: 'POST', body }).then((r) => r.data),
  updatePlan: (slug: string, body: Partial<Omit<AdminPlan, 'slug' | 'tenants' | 'monthly_price'>>) =>
    request<Data<AdminPlan>>(`/admin/billing/plans/${enc(slug)}`, { method: 'PATCH', body }).then((r) => r.data),
  settings: () => request<Data<BillingProviderSettings>>('/admin/billing/settings').then((r) => r.data),
  saveSettings: (body: { access_token?: string | null; webhook_secret?: string | null; remove_webhook_secret?: boolean }) =>
    request<Data<BillingProviderSettings>>('/admin/billing/settings', { method: 'PUT', body }).then((r) => r.data),
  disconnect: () => request<Data<BillingProviderSettings>>('/admin/billing/settings', { method: 'DELETE' }).then((r) => r.data),
  invoices: (status?: 'open' | 'overdue' | 'paid' | 'cancelled' | null) =>
    request<{ data: AdminInvoice[]; meta: { open_total: string; overdue_total: string; paid_month: string } }>(`/admin/billing/invoices${status ? `?status=${status}` : ''}`),
  payInvoice: (id: string, note?: string | null) =>
    request<Data<TenantBilling>>(`/admin/billing/invoices/${enc(id)}/pay`, { method: 'POST', body: { note: note ?? null } }).then((r) => r.data),
  cancelInvoice: (id: string, note?: string | null) =>
    request<Data<TenantBilling>>(`/admin/billing/invoices/${enc(id)}/cancel`, { method: 'POST', body: { note: note ?? null } }).then((r) => r.data),
  tenant: (id: string) => request<Data<TenantBilling>>(`/admin/tenants/${enc(id)}/billing`).then((r) => r.data),
  updateTenant: (id: string, body: { plan: string; billing_months: number; price?: string | null; paid_until?: string | null; modules?: ModuleKey[]; auto_block?: boolean }) =>
    request<Data<TenantBilling>>(`/admin/tenants/${enc(id)}/billing`, { method: 'PUT', body }).then((r) => r.data),
  createInvoice: (id: string) =>
    request<Data<TenantBilling>>(`/admin/tenants/${enc(id)}/billing/invoices`, { method: 'POST' }).then((r) => r.data),
}

export const merchantBillingApi = {
  show: () => request<Data<TenantBilling & { pix_available: boolean }>>('/merchant/billing').then((r) => r.data),
  pix: (id: string) => request<Data<Invoice>>(`/merchant/billing/invoices/${enc(id)}/pix`, { method: 'POST' }).then((r) => r.data),
  status: (id: string) => request<Data<Invoice>>(`/merchant/billing/invoices/${enc(id)}`).then((r) => r.data),
}

/** "2026-12-01" → "01/12/2026" sem fuso (data pura). */
export function formatDay(day: string): string {
  const [y, m, d] = day.split('-')
  return `${d}/${m}/${y}`
}
