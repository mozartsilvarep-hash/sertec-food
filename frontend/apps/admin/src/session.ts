import { ref } from 'vue'
import { ApiError, adminApi, type AdminMe } from '@sertec/api-client'

export const me = ref<AdminMe | null>(null)

export async function loadSession(): Promise<AdminMe | null> {
  try {
    me.value = await adminApi.me()
  } catch (error) {
    if (error instanceof ApiError && (error.status === 401 || error.status === 403)) {
      me.value = null
    } else {
      throw error
    }
  }
  return me.value
}
