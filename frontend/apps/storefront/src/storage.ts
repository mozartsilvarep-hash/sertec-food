/** Preferências locais do cliente (unidade escolhida). Falha silenciosa. */
export function readPref(key: string): string | null {
  try {
    return window.localStorage.getItem(`sertec:${key}`)
  } catch {
    return null
  }
}

export function writePref(key: string, value: string): void {
  try {
    window.localStorage.setItem(`sertec:${key}`, value)
  } catch {
    /* modo privado / bloqueado: ignora */
  }
}

export function removePref(key: string): void {
  try {
    window.localStorage.removeItem(`sertec:${key}`)
  } catch {
    /* ignora */
  }
}
