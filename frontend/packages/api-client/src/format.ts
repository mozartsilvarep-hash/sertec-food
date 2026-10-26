const brl = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' })

/** "12.5" | "12.50" → "R$ 12,50" (sem passar por float para somar; só exibição). */
export function formatMoney(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return ''
  return brl.format(Number(value)).replace(/ /g, ' ')
}

export function centsToString(cents: number): string {
  const sign = cents < 0 ? '-' : ''
  const abs = Math.abs(cents)
  return `${sign}${Math.trunc(abs / 100)}.${String(abs % 100).padStart(2, '0')}`
}

export function toCents(value: string): number {
  const match = /^(-)?(\d+)(?:\.(\d{1,2}))?$/.exec(value.trim())
  if (!match) throw new Error(`Valor inválido: ${value}`)
  const cents = Number(match[2]) * 100 + Number((match[3] ?? '0').padEnd(2, '0'))
  return match[1] ? -cents : cents
}

/** "2026-10-23T23:00:00-03:00" → "23:00" no fuso do estabelecimento. */
export function formatTime(iso: string, timeZone: string): string {
  return new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone }).format(new Date(iso))
}

const weekdayFmt = (tz: string) => new Intl.DateTimeFormat('pt-BR', { weekday: 'long', timeZone: tz })

/** Texto curto de status: "Aberto até 23:00" / "Abre hoje às 18:00" / "Abre sábado às 11:00". */
export function openingLabel(
  status: { open: boolean; closes_at: string | null; next_opening_at: string | null; has_hours?: boolean },
  timeZone: string,
  now: Date = new Date(),
): string {
  if (status.open && status.closes_at) return `Aberto até ${formatTime(status.closes_at, timeZone)}`
  if (!status.next_opening_at) return status.has_hours === false ? 'Horário não informado' : 'Fechado'
  const next = new Date(status.next_opening_at)
  const day = (d: Date) => new Intl.DateTimeFormat('en-CA', { timeZone }).format(d)
  const tomorrow = new Date(now.getTime() + 86_400_000)
  const when = day(next) === day(now) ? 'hoje' : day(next) === day(tomorrow) ? 'amanhã' : weekdayFmt(timeZone).format(next)
  return `Fechado · abre ${when} às ${formatTime(status.next_opening_at, timeZone)}`
}

/** "25/10/2026 19:42" no fuso do estabelecimento. */
export function formatDateTime(iso: string, timeZone: string): string {
  return new Intl.DateTimeFormat('pt-BR', { timeZone, day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
    .format(new Date(iso))
    .replace(',', '')
}

/** Data de hoje (AAAA-MM-DD) no fuso informado. */
export function todayIn(timeZone: string, offsetDays = 0): string {
  const d = new Date(Date.now() + offsetDays * 86_400_000)
  return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(d)
}

/** Linhas de desconto nos totais do pedido (cupom, promoção, fidelidade) aparecem com "−". */
export const DISCOUNT_TOTAL_CODES = ['discount', 'promotion', 'loyalty'] as const

export function formatTotal(total: { code: string; amount: string }): string {
  const value = formatMoney(total.amount)
  return (DISCOUNT_TOTAL_CODES as readonly string[]).includes(total.code) ? `− ${value}` : value
}
