import { request } from './http'

export interface MerchantMe {
  user: { id: string; name: string; email: string }
  tenant: { id: string; name: string; slug: string; timezone: string }
  roles: string[]
  permissions: string[]
  all_branches: boolean
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

export const merchantApi = {
  login: (credentials: Credentials) =>
    request<Data<MerchantMe>>('/merchant/auth/login', { method: 'POST', body: credentials }).then((r) => r.data),
  logout: () => request<void>('/merchant/auth/logout', { method: 'POST' }),
  me: () => request<Data<MerchantMe>>('/merchant/me').then((r) => r.data),
  branches: () => request<Data<Branch[]>>('/merchant/branches').then((r) => r.data),
}

export const adminApi = {
  login: (credentials: Credentials) =>
    request<Data<AdminMe>>('/admin/auth/login', { method: 'POST', body: credentials }).then((r) => r.data),
  logout: () => request<void>('/admin/auth/logout', { method: 'POST' }),
  me: () => request<Data<AdminMe>>('/admin/me').then((r) => r.data),
}

export const storefrontApi = {
  show: () => request<Data<Storefront>>('/storefront').then((r) => r.data),
}
