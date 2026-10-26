import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import type { ProductSummary } from '@sertec/api-client'
import { formatTotal } from '@sertec/api-client'
import ProductRow from './ProductRow.vue'

const base: ProductSummary = { id: 'p', name: 'Bolo de rolo', description: null, image_url: null, available: true, has_options: false, price_from: '12.00' }

describe('Linha do cardápio', () => {
  it('fora do dia: mostra quando volta e não abre', () => {
    const w = mount(ProductRow, { props: { product: { ...base, available: false, availability: 'Só sáb. e dom.' } } })
    expect(w.text()).toContain('Só sáb. e dom.')
    expect(w.find('button').attributes('disabled')).toBeDefined()
  })

  it('combo: selo e preço "de" pela soma dos avulsos', () => {
    const w = mount(ProductRow, { props: { product: { ...base, name: 'Combo Festa', kind: 'combo', price_from: '45.00', compare_at: '58.00' } } })
    expect(w.text()).toContain('Combo')
    expect(w.find('.line-through').text()).toMatch(/58,00/)
  })

  it('"leve 2, pague 1": selo sem preço riscado', () => {
    const w = mount(ProductRow, { props: { product: { ...base, promotion: { id: 'x', type: 'bundle', percent_bp: 0, buy_quantity: 2, pay_quantity: 1, label: 'Leve 2, pague 1', featured: false, ends_at: null } } } })
    expect(w.text()).toContain('Leve 2, pague 1')
    expect(w.find('.line-through').exists()).toBe(false)
  })

  it('totais de desconto aparecem com sinal de menos', () => {
    expect(formatTotal({ code: 'promotion', amount: '6.50' })).toMatch(/^− R\$\s6,50$/)
    expect(formatTotal({ code: 'total', amount: '6.50' })).toMatch(/^R\$\s6,50$/)
  })
})
