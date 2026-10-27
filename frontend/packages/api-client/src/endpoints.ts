import { request } from './http'

export interface MerchantMe {
  user: { id: string; name: string; email: string }
  tenant: { id: string; name: string; slug: string; timezone: string }
  roles: string[]
  permissions: string[]
  all_branches: boolean
  /** Mensalidade SerTec perto do vencimento ou atrasada (só para o proprietário). */
  billing?: { invoice_id: string; due_date: string; amount: string; overdue: boolean } | null
}

export interface Branch {
  id: string
  name: string
  phone: string | null
  address: Record<string, string> | null
  active: boolean
}

export interface AdminMe {
  user: { id: string; name: string; email: string; platform_role: 'superadmin' | 'support' }
}

export interface Storefront {
  name: string
  slug: string
  timezone: string
}

export interface Credentials {
  email: string
  password: string
}

type Data<T> = { data: T }

export interface PasswordResetInput {
  email: string
  token: string
  password: string
  password_confirmation: string
}

/** Configuração de envio de e-mail (painel SerTec; nunca traz a senha). */
export interface MailStatus {
  mailer: string
  configured: boolean
  host: string | null
  port: number | string | null
  from: string | null
  from_name: string | null
}

export const merchantApi = {
  login: (credentials: Credentials) =>
    request<Data<MerchantMe>>('/merchant/auth/login', { method: 'POST', body: credentials }).then((r) => r.data),
  logout: () => request<void>('/merchant/auth/logout', { method: 'POST' }),
  forgotPassword: (email: string) =>
    request<{ message: string }>('/merchant/auth/forgot-password', { method: 'POST', body: { email } }).then((r) => r.message),
  resetPassword: (body: PasswordResetInput) =>
    request<{ message: string }>('/merchant/auth/reset-password', { method: 'POST', body }).then((r) => r.message),
  me: () => request<Data<MerchantMe>>('/merchant/me').then((r) => r.data),
  branches: () => request<Data<Branch[]>>('/merchant/branches').then((r) => r.data),
}

export const adminApi = {
  login: (credentials: Credentials) =>
    request<Data<AdminMe>>('/admin/auth/login', { method: 'POST', body: credentials }).then((r) => r.data),
  logout: () => request<void>('/admin/auth/logout', { method: 'POST' }),
  forgotPassword: (email: string) =>
    request<{ message: string }>('/admin/auth/forgot-password', { method: 'POST', body: { email } }).then((r) => r.message),
  resetPassword: (body: PasswordResetInput) =>
    request<{ message: string }>('/admin/auth/reset-password', { method: 'POST', body }).then((r) => r.message),
  mailStatus: () => request<Data<MailStatus>>('/admin/system/mail').then((r) => r.data),
  mailTest: () => request<{ message: string }>('/admin/system/mail/test', { method: 'POST' }).then((r) => r.message),
  me: () => request<Data<AdminMe>>('/admin/me').then((r) => r.data),
}

export const storefrontApi = {
  show: () => request<Data<Storefront>>('/storefront').then((r) => r.data),
}
