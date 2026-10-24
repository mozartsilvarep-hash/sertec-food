import { ref } from 'vue'

/** Aviso curto e acessível ("Produto salvo"). Uma mensagem por vez. */
export const notice = ref<{ text: string; tone: 'ok' | 'error' } | null>(null)
let timer: ReturnType<typeof setTimeout> | undefined

export function notify(text: string, tone: 'ok' | 'error' = 'ok'): void {
  notice.value = { text, tone }
  clearTimeout(timer)
  timer = setTimeout(() => (notice.value = null), 3500)
}
