import { describe, expect, it } from 'vitest'
import type { ProductSummary, PublicMenu } from '@sertec/api-client'
import { addressLine, highlights, initials, instagramUrl, mapsUrl } from './identity'

const product = (id: string, extra: Partial<ProductSummary> = {}): ProductSummary => ({
  id, name: id, description: null, image_url: `/storage/${id}.webp`, available: true, has_options: false, price_from: '10.00', ...extra,
})
const menu = (products: ProductSummary[]): PublicMenu[] => [{ id: 'm', name: 'M', categories: [{ id: 'c', name: 'C', description: null, products }] }]

describe('identidade da loja', () => {
  it('monta endereço e links só com o que existe', () => {
    expect(addressLine({ street: 'Av. Beira Mar', number: '100', neighborhood: 'Atalaia', city: 'Aracaju', state: 'SE' }))
      .toBe('Av. Beira Mar, 100 — Atalaia, Aracaju/SE')
    expect(addressLine(null)).toBeNull()
    expect(mapsUrl({}, 'Loja')).toBeNull()
    expect(instagramUrl('yriscakes')).toBe('https://instagram.com/yriscakes')
    expect(instagramUrl(null)).toBeNull()
  })

  it('iniciais ignoram preposições', () => {
    expect(initials('Yris Cakes')).toBe('YC')
    expect(initials('Casa do Pão')).toBe('CP')
  })

  it('destaques: só com foto, disponíveis e em promoção/combo; precisa de 2', () => {
    const list = [
      product('promo', { promotion: { id: 'p', type: 'percent', percent_bp: 1000, label: '10% off' } as never }),
      product('combo', { kind: 'combo' }),
      product('comum'),
      product('sem-foto', { kind: 'combo', image_url: null }),
      product('esgotado', { kind: 'combo', available: false }),
    ]
    expect(highlights(menu(list)).map((p) => p.id)).toEqual(['promo', 'combo'])
    expect(highlights(menu([product('combo', { kind: 'combo' })]))).toEqual([])
  })
})
