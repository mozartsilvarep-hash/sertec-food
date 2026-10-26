import { computed, ref } from 'vue'
import { readPref, writePref } from './storage'

/**
 * Pedidos "ao vivo": pulso a cada 3 s (Web Worker), alerta sonoro gerado no
 * navegador (sem arquivo de áudio), título da aba e notificação do sistema.
 */
export interface Pulse {
  version: string
  pending: number
  oldest_pending_at: string | null
}

export const pulse = ref<Pulse | null>(null)
export const offline = ref(false)
export const soundEnabled = ref(readPref('alerts:sound') === '1')
export const now = ref(Date.now())

/** Minutos que o pedido novo mais antigo espera aceite. */
export const waitingMinutes = computed(() => {
  const oldest = pulse.value?.oldest_pending_at
  return oldest ? Math.max(0, Math.floor((now.value - new Date(oldest).getTime()) / 60_000)) : 0
})
export const LATE_AFTER_MINUTES = 5

let worker: Worker | null = null
let fallback: ReturnType<typeof setInterval> | undefined
let clock: ReturnType<typeof setInterval> | undefined
let repeat: ReturnType<typeof setInterval> | undefined
let audio: AudioContext | null = null
let baseTitle = ''
let onUnauthorized: (() => void) | null = null

function handle(next: Pulse) {
  offline.value = false
  now.value = Date.now()
  const before = pulse.value
  pulse.value = next
  if (before && next.pending > before.pending) {
    void chime()
    notify(next.pending - before.pending)
  }
  updateTitle()
}

function updateTitle() {
  if (!baseTitle) baseTitle = document.title.replace(/^\(\d+\)\s*/, '')
  const n = pulse.value?.pending ?? 0
  document.title = n > 0 ? `(${n}) ${n === 1 ? 'Novo pedido' : 'Novos pedidos'} · ${baseTitle}` : baseTitle
}

/** Dois tons curtos (ding-dong) com Web Audio; funciona só após um clique. */
export async function chime(): Promise<void> {
  if (!soundEnabled.value) return
  try {
    audio ??= new AudioContext()
    if (audio.state === 'suspended') await audio.resume()
    const start = audio.currentTime
    for (const [i, freq] of [880, 660].entries()) {
      const osc = audio.createOscillator()
      const gain = audio.createGain()
      osc.type = 'sine'
      osc.frequency.value = freq
      const t = start + i * 0.28
      gain.gain.setValueAtTime(0.0001, t)
      gain.gain.exponentialRampToValueAtTime(0.5, t + 0.02)
      gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.26)
      osc.connect(gain).connect(audio.destination)
      osc.start(t)
      osc.stop(t + 0.3)
    }
  } catch {
    /* navegador sem Web Audio */
  }
}

function notify(count: number) {
  if (typeof Notification === 'undefined' || Notification.permission !== 'granted' || !document.hidden) return
  try {
    new Notification(count === 1 ? 'Novo pedido!' : `${count} novos pedidos!`, { body: 'Abra o painel para aceitar.', tag: 'sertec-novo-pedido' })
  } catch {
    /* alguns navegadores móveis só notificam via service worker */
  }
}

/** Liga som + notificações (precisa de um clique do usuário). */
export async function enableAlerts(): Promise<void> {
  soundEnabled.value = true
  writePref('alerts:sound', '1')
  await chime()
  if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
    await Notification.requestPermission().catch(() => 'denied')
  }
}

export function disableAlerts(): void {
  soundEnabled.value = false
  writePref('alerts:sound', '0')
}

export function startLive(unauthorized: () => void): void {
  if (worker || fallback) return
  onUnauthorized = unauthorized
  const receive = (msg: { type: string; data?: Pulse }) => {
    if (msg.type === 'pulse' && msg.data) handle(msg.data)
    else if (msg.type === 'offline') offline.value = true
    else if (msg.type === 'unauthorized') { stopLive(); onUnauthorized?.() }
  }

  if (typeof Worker !== 'undefined') {
    worker = new Worker(new URL('./pulse.worker.ts', import.meta.url), { type: 'module' })
    worker.onmessage = (e: MessageEvent<{ type: string; data?: Pulse }>) => receive(e.data)
    worker.postMessage({ type: 'start' })
  } else {
    const poll = async () => {
      try {
        const res = await fetch('/api/v1/merchant/orders/pulse', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        if (res.status === 401 || res.status === 403) return receive({ type: 'unauthorized' })
        if (res.ok) receive({ type: 'pulse', data: ((await res.json()) as { data: Pulse }).data })
      } catch {
        receive({ type: 'offline' })
      }
    }
    void poll()
    fallback = setInterval(poll, 3_000)
  }

  clock = setInterval(() => (now.value = Date.now()), 15_000)
  // Enquanto houver pedido sem aceite, o som repete a cada 30 s.
  repeat = setInterval(() => {
    if ((pulse.value?.pending ?? 0) > 0) void chime()
  }, 30_000)
}

export function refreshNow(): void {
  worker?.postMessage({ type: 'now' })
}

export function stopLive(): void {
  worker?.postMessage({ type: 'stop' })
  worker?.terminate()
  worker = null
  clearInterval(fallback)
  clearInterval(clock)
  clearInterval(repeat)
  fallback = undefined
  pulse.value = null
  if (baseTitle) document.title = baseTitle
}
