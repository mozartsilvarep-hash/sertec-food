import { request } from './http'
import { filterQuery } from './ordering'
import type { Branch } from './endpoints'

type Data<T> = { data: T }
const enc = encodeURIComponent

/* ---------- Painel SerTec ---------- */

export type TenantStatus = 'pending' | 'active' | 'suspended' | 'cancelled'

export interface AdminTenantSummary {
  id: string
  name: string
  slug: string
  status: TenantStatus
  host: string | null
  plan: string | null
  created_at: string | null
  orders_30d?: number
  payments?: 'test' | 'live' | null
}

export interface AdminTenant extends Omit<AdminTenantSummary, 'payments' | 'orders_30d'> {
  timezone: string
  owner: { name: string; email: string; last_login_at: string | null } | null
  domains: { id: string; hostname: string; type: 'platform_subdomain' | 'custom'; verified: boolean; is_primary: boolean }[]
  branches: { id: string; name: string; active: boolean; phone: string | null }[]
  staff_count: number
  orders_total: number
  last_order_at: string | null
  payments: { mode: 'test' | 'live'; account_label: string | null; verified_at: string | null } | null
  owner_password?: string | null
}

export interface DomainInstructions {
  id: string
  hostname: string
  verified: boolean
  txt_name: string
  txt_value: string
  cname_target: string
}

export interface NewTenant {
  name: string
  slug: string
  owner_name: string
  owner_email: string
  plan?: string | null
  timezone?: string | null
  activate: boolean
}

export interface PlatformAuditEntry {
  id: string
  action: string
  tenant: { name: string; slug: string } | null
  actor: { name: string; email: string } | null
  entity_type: string | null
  entity_id: string | null
  metadata: Record<string, unknown> | null
  ip: string | null
  at: string
}

export const adminTenantsApi = {
  list: (q?: string | null, status?: TenantStatus | null, cursor?: string | null) =>
    request<{ data: AdminTenantSummary[]; meta: { next_cursor: string | null } }>(`/admin/tenants${filterQuery({ q, status, cursor })}`),
  get: (id: string) => request<Data<AdminTenant>>(`/admin/tenants/${enc(id)}`).then((r) => r.data),
  create: (body: NewTenant) => request<Data<AdminTenant>>('/admin/tenants', { method: 'POST', body }).then((r) => r.data),
  update: (id: string, body: { name?: string; status?: TenantStatus; plan?: string | null }) =>
    request<Data<AdminTenant>>(`/admin/tenants/${enc(id)}`, { method: 'PATCH', body }).then((r) => r.data),
  resetOwnerPassword: (id: string) =>
    request<Data<{ email: string; password: string }>>(`/admin/tenants/${enc(id)}/owner-password`, { method: 'POST' }).then((r) => r.data),
  addDomain: (id: string, hostname: string) =>
    request<Data<DomainInstructions>>(`/admin/tenants/${enc(id)}/domains`, { method: 'POST', body: { hostname } }).then((r) => r.data),
  domainToken: (id: string, domain: string) =>
    request<Data<DomainInstructions>>(`/admin/tenants/${enc(id)}/domains/${enc(domain)}/token`, { method: 'POST' }).then((r) => r.data),
  verifyDomain: (id: string, domain: string) =>
    request<Data<{ hostname: string; verified: boolean }>>(`/admin/tenants/${enc(id)}/domains/${enc(domain)}/verify`, { method: 'POST' }).then((r) => r.data),
  primaryDomain: (id: string, domain: string) =>
    request<Data<{ hostname: string }>>(`/admin/tenants/${enc(id)}/domains/${enc(domain)}/primary`, { method: 'POST' }).then((r) => r.data),
  removeDomain: (id: string, domain: string) => request<void>(`/admin/tenants/${enc(id)}/domains/${enc(domain)}`, { method: 'DELETE' }),
  plans: () => request<Data<{ slug: string; name: string; monthly_price: string; active: boolean }[]>>('/admin/plans').then((r) => r.data),
  audit: (filter: { tenant?: string | null; action?: string | null; scope?: 'platform' | 'tenants' | null; cursor?: string | null }) =>
    request<{ data: PlatformAuditEntry[]; meta: { next_cursor: string | null } }>(`/admin/audit${filterQuery({ ...filter })}`),
}

/* ---------- Painel do restaurante: equipe, conta e unidades ---------- */

export type StaffRole = 'owner' | 'manager' | 'attendant' | 'kitchen'

export interface StaffMember {
  id: string
  name: string
  email: string
  role: StaffRole
  role_name: string
  branch: { id: string; name: string } | null
  active: boolean
  last_login_at: string | null
  is_self: boolean
  is_owner: boolean
  temporary_password?: string | null
}

export const STAFF_ROLES: { value: Exclude<StaffRole, 'owner'>; label: string; hint: string }[] = [
  { value: 'manager', label: 'Gerente', hint: 'Tudo, menos equipe, pagamentos online e auditoria' },
  { value: 'attendant', label: 'Atendente', hint: 'Pedidos e esgotados' },
  { value: 'kitchen', label: 'Cozinha', hint: 'Pedidos e esgotados, sem ver unidades' },
]

export const teamApi = {
  list: () => request<Data<StaffMember[]>>('/merchant/staff').then((r) => r.data),
  add: (body: { name: string; email: string; role: string; branch_id: string | null }) =>
    request<Data<StaffMember>>('/merchant/staff', { method: 'POST', body }).then((r) => r.data),
  update: (id: string, body: { role?: string; branch_id?: string | null; active?: boolean }) =>
    request<Data<StaffMember>>(`/merchant/staff/${enc(id)}`, { method: 'PATCH', body }).then((r) => r.data),
  remove: (id: string) => request<void>(`/merchant/staff/${enc(id)}`, { method: 'DELETE' }),
  resetPassword: (id: string) =>
    request<Data<{ email: string; temporary_password: string }>>(`/merchant/staff/${enc(id)}/password`, { method: 'POST' }).then((r) => r.data),
  changePassword: (body: { current_password: string; password: string; password_confirmation: string }) =>
    request<void>('/merchant/me/password', { method: 'POST', body }),
  updateBranch: (id: string, body: { name?: string; phone?: string | null; address?: Record<string, string | null> | null; active?: boolean }) =>
    request<Data<Branch>>(`/merchant/branches/${enc(id)}`, { method: 'PATCH', body }).then((r) => r.data),
}
