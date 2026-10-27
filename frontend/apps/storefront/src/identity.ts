import type { BranchStatus, ProductSummary, PublicMenu, StorefrontInfo } from '@sertec/api-client'
import { whatsappLink } from './whatsapp'

/** "Rua X, 123 — Bairro, Cidade/UF" (só o que estiver preenchido). */
export function addressLine(address: Record<string, string> | null | undefined): string | null {
  if (!address) return null
  const street = [address.street, address.number].filter(Boolean).join(', ')
  const city = [address.city, address.state].filter(Boolean).join('/')
  const place = [address.neighborhood, city].filter(Boolean).join(', ')
  const line = [street, place].filter(Boolean).join(' — ')
  return line || null
}

export function mapsUrl(address: Record<string, string> | null | undefined, name: string): string | null {
  const line = addressLine(address)
  return line ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${name}, ${line}`)}` : null
}

export function instagramUrl(handle: string | null | undefined): string | null {
  return handle ? `https://instagram.com/${encodeURIComponent(handle)}` : null
}

/** WhatsApp da loja; sem número próprio, usa o telefone da unidade. */
export function storeWhatsapp(store: StorefrontInfo, branch: BranchStatus | null): string | null {
  return whatsappLink(store.whatsapp ?? branch?.phone, `Olá, ${store.name}! Vim pelo cardápio digital.`)
}

/** Iniciais para o monograma quando a loja não tem logo. */
export function initials(name: string): string {
  const words = name.replace(/[^\p{L}\p{N}\s]/gu, ' ').split(/\s+/).filter((w) => w.length > 2 || /^\p{Lu}/u.test(w))
  return (words.slice(0, 2).map((w) => w[0]!.toUpperCase()).join('') || name.slice(0, 1).toUpperCase())
}

/**
 * Destaques do topo: produtos com foto que estão em promoção, são combo ou
 * têm preço "de/por". Sem pelo menos 2, a faixa não aparece.
 */
export function highlights(menus: PublicMenu[], limit = 8): ProductSummary[] {
  const seen = new Set<string>()
  const list: ProductSummary[] = []
  for (const p of menus.flatMap((m) => m.categories).flatMap((c) => c.products)) {
    if (seen.has(p.id) || !p.available || !p.image_url) continue
    const featured = p.promotion || p.promo_price_from || p.kind === 'combo' || (p.compare_at && Number(p.compare_at) > Number(p.price_from ?? 0))
    if (!featured) continue
    seen.add(p.id)
    list.push(p)
    if (list.length >= limit) break
  }
  return list.length >= 2 ? list : []
}
