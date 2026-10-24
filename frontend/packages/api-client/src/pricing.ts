import type { PublicProduct } from './catalog'
import { centsToString, toCents } from './format'

/**
 * Prévia do preço no navegador — SOMENTE para exibição. O valor cobrado é
 * recalculado no servidor (PriceCalculator); estas regras espelham as dele.
 */
export interface Selection {
  variantId: string
  /** optionId => quantidade (sabores: 1) */
  options: Record<string, number>
}

export interface GroupCheck {
  groupId: string
  selected: number
  ok: boolean
  message: string | null
}

export interface PricePreview {
  unitCents: number
  unitPrice: string
  groups: GroupCheck[]
  valid: boolean
}

function averageCents(values: number[]): number {
  if (values.length === 0) return 0
  const n = values.length
  const sum = values.reduce((a, b) => a + b, 0)
  return Math.floor((2 * sum + n) / (2 * n))
}

export function previewPrice(product: PublicProduct, selection: Selection): PricePreview {
  const variant = product.variants.find((v) => v.id === selection.variantId)
  let cents = variant ? toCents(variant.price) : 0
  const groups: GroupCheck[] = []

  for (const group of product.option_groups) {
    const picks = group.options
      .map((option) => ({ option, qty: selection.options[option.id] ?? 0 }))
      .filter((p) => p.qty > 0)
    const selected = picks.reduce((total, p) => total + p.qty, 0)

    if (group.type === 'fraction') {
      const prices = picks
        .map((p) => (variant ? p.option.prices?.[variant.id] : undefined))
        .filter((p): p is string => p !== undefined)
        .map(toCents)
      if (prices.length > 0) cents += group.pricing_rule === 'average' ? averageCents(prices) : Math.max(...prices)
    } else {
      const units = picks
        .flatMap((p) => Array<number>(p.qty).fill(toCents(p.option.price_delta)))
        .sort((a, b) => a - b)
      cents += units.slice(group.free_quantity).reduce((a, b) => a + b, 0)
    }

    let message: string | null = null
    if (selected < group.min_select) message = group.min_select === 1 ? 'Escolha 1 opção' : `Escolha ${group.min_select} opções`
    else if (selected > group.max_select) message = `Máximo de ${group.max_select}`
    groups.push({ groupId: group.id, selected, ok: message === null, message })
  }

  return {
    unitCents: cents,
    unitPrice: centsToString(cents),
    groups,
    valid: variant !== undefined && groups.every((g) => g.ok),
  }
}

/** Sabor disponível no tamanho escolhido? */
export function flavorAvailable(option: { prices: Record<string, string> | null }, variantId: string): boolean {
  return option.prices === null || option.prices[variantId] !== undefined
}
