import { describe, expect, it } from 'vitest'
import type { PublicProduct } from './catalog'
import { previewPrice } from './pricing'

const pizza = {
  id: 'p', name: 'Pizza', description: null, image_url: null, available: true, has_options: true, price_from: '40.00',
  promotion: { id: 'x', percent_bp: 1000, label: '-10%', featured: false, ends_at: null },
  variants: [{ id: 'M', name: 'Média', price: '0.00' }],
  option_groups: [
    { id: 'S', name: 'Sabores', type: 'fraction', min_select: 1, max_select: 2, required: true, free_quantity: 0, pricing_rule: 'highest',
      options: [{ id: 'cal', name: 'Calabresa', description: null, price_delta: '0.00', max_quantity: 1, prices: { M: '40.00' } }, { id: 'por', name: 'Portuguesa', description: null, price_delta: '0.00', max_quantity: 1, prices: { M: '44.51' } }] },
    { id: 'B', name: 'Bordas', type: 'addon', min_select: 0, max_select: 3, required: false, free_quantity: 0, pricing_rule: null,
      options: [{ id: 'bac', name: 'Bacon', description: null, price_delta: '2.50', max_quantity: 3, prices: null }] },
  ],
} as unknown as PublicProduct

describe('previewPrice com promoção', () => {
  it('aplica o mesmo arredondamento do servidor sobre a unidade inteira', () => {
    const r = previewPrice(pizza, { variantId: 'M', options: { cal: 1, por: 1, bac: 2 } })
    expect(r.originalCents).toBe(4951)
    expect(r.unitCents).toBe(4456)
    expect(r.unitPrice).toBe('44.56')
  })

  it('sem promoção o preço é o cheio', () => {
    const r = previewPrice({ ...pizza, promotion: null }, { variantId: 'M', options: { cal: 1 } })
    expect(r.unitCents).toBe(4000)
    expect(r.originalCents).toBe(4000)
  })
})
