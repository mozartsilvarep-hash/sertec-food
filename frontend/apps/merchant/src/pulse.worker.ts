/**
 * Consulta o pulso dos pedidos num Web Worker: timers de workers não sofrem o
 * "intensive throttling" que o Chrome aplica a abas em segundo plano, então o
 * restaurante recebe o alerta mesmo com o painel minimizado.
 */
// Tipagem mínima do escopo do worker (evita misturar as libs DOM e WebWorker).
const scope = self as unknown as {
  postMessage: (message: unknown) => void
  onmessage: ((event: MessageEvent<{ type: 'start' | 'stop' | 'now' }>) => void) | null
}

const INTERVAL = 3_000
let timer: ReturnType<typeof setTimeout> | undefined
let failures = 0

async function tick() {
  try {
    const res = await fetch('/api/v1/merchant/orders/pulse', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    if (res.status === 401 || res.status === 403) {
      scope.postMessage({ type: 'unauthorized' })
      return // para: a sessão acabou
    }
    if (res.ok) {
      failures = 0
      const body = (await res.json()) as { data: unknown }
      scope.postMessage({ type: 'pulse', data: body.data })
    } else {
      failures++
    }
  } catch {
    failures++
    scope.postMessage({ type: 'offline' })
  }
  // Recuo simples se o servidor/rede falhar (até 30 s).
  timer = setTimeout(tick, Math.min(30_000, INTERVAL * 2 ** Math.min(failures, 3)))
}

scope.onmessage = (event) => {
  clearTimeout(timer)
  if (event.data.type === 'stop') return
  void tick()
}
