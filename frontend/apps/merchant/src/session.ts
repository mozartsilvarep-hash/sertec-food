import { ref } from 'vue'
import { ApiError, merchantApi, type MerchantMe } from '@sertec/api-client'

/** Sessão do painel. O tenant é sempre o do host atual (decidido pelo servidor). */
export const me = ref<MerchantMe | null>(null)

export async function loadSession(): Promise<MerchantMe | null> {
  try {
    me.value = await merchantApi.me()
  } catch (error) {
    if (error instanceof ApiError && (error.status === 401 || error.status === 403)) {
      me.value = null
    } else {
      throw error
    }
  }
  return me.value
}

/** Funcionalidade liberada pela assinatura (plano + módulos contratados). */
export function has(feature: string): boolean {
  const list = me.value?.features
  return list === undefined ? true : list.includes(feature)
}

export function can(permission: string): boolean {
  return me.value?.permissions.includes(permission) ?? false
}
