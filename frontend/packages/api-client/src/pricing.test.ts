import { describe, expect, it } from 'vitest'
import type { PublicProduct } from './catalog'
import { previewPrice } from './pricing'
import { formatMoney, openingLabel } from './format'

// Mesmo cenário do teste PHP (CatalogFixture): as duas implementações devem concordar.
const pizza: PublicProduct = {
  id: 'p', name: 'Pizza', description: null, image_url: null, available: true, has_options: true, price_from: '40.00',
  variants: [{ id: 'M', name: 'Média', price: '0.00' }, { id: 'G', name: 'Grande', price: '5.00' }],
  option_groups: [
    {
      id: 'sabores', name: 'Sabores', type: 'fraction', min_select: 1, max_select: 2, required: true, free_quantity: 0, pricing_rule: 'highest',
      options: [
        { id: 'cal', name: 'Calabresa', description: null, price_delta: '0.00', max_quantity: 1, prices: { M: '40.00', G: '50.00' } },
        { id: 'por', name: 'Portuguesa', description: null, price_delta: '0.00', max_quantity: 1, prices: { M: '45.01', G: '55.00' } },
      ],
    },
    {
      id: 'extras', name: 'Bordas e extras', type: 'addon', min_select: 0, max_select: 4, required: false, free_quantity: 1, pricing_rule: null,
      options: [
        { id: 'catu', name: 'Catupiry', description: null, price_delta: '8.00', max_quantity: 2, prices: null },
        { id: 'bacon', name: 'Bacon', description: null, price_delta: '4.50', max_quantity: 3, prices: null },
      ],
    },
  ],
}

describe('previewPrice', () => {
  it('meio a meio pelo sabor mais caro', () => {
    expect(previewPrice(pizza, { variantId: 'M', options: { cal: 1, por: 1 } }).unitPrice).toBe('45.01')
  })

  it('média arredonda meio centavo para cima, igual ao servidor', () => {
    const avg = { ...pizza, option_groups: [{ ...pizza.option_groups[0]!, pricing_rule: 'average' as const }] }
    expect(previewPrice(avg, { variantId: 'M', options: { cal: 1, por: 1 } }).unitPrice).toBe('42.51')
  })

  it('unidade grátis é a mais barata', () => {
    expect(previewPrice(pizza, { variantId: 'M', options: { cal: 1, catu: 1, bacon: 2 } }).unitPrice).toBe('52.50')
  })

  it('aponta grupo obrigatório pendente', () => {
    const p = previewPrice(pizza, { variantId: 'G', options: {} })
    expect(p.valid).toBe(false)
    expect(p.groups[0]?.message).toBe('Escolha 1 opção')
  })
})

describe('formatação', () => {
  it('formata reais', () => {
    expect(formatMoney('1234.5')).toBe('R$ 1.234,50')
  })

  it('descreve aberto/fechado no fuso do estabelecimento', () => {
    const tz = 'America/Maceio'
    const now = new Date('2026-10-23T15:00:00-03:00')
    expect(openingLabel({ open: true, closes_at: '2026-10-23T23:00:00-03:00', next_opening_at: null }, tz, now)).toBe('Aberto até 23:00')
    expect(openingLabel({ open: false, closes_at: null, next_opening_at: '2026-10-23T18:00:00-03:00' }, tz, now)).toBe('Fechado · abre hoje às 18:00')
    expect(openingLabel({ open: false, closes_at: null, next_opening_at: '2026-10-24T18:00:00-03:00' }, tz, now)).toBe('Fechado · abre amanhã às 18:00')
  })
})
