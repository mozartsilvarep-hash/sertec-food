import { describe, expect, it } from 'vitest'
import type { TrackedOrder } from '@sertec/api-client'
import { orderMessage, phoneDigits, whatsappLink } from './whatsapp'

const order = {
  number: 42,
  items: [
    { quantity: 1, name: 'Bolo de pote', options: [{ name: 'Ninho' }], line_total: '15.00' },
    { quantity: 2, name: 'Brigadeiro', options: [], line_total: '6.00' },
  ],
  totals: [{ code: 'subtotal', label: 'Subtotal', amount: '21.00' }, { code: 'total', label: 'Total', amount: '28.00' }],
  payment_method_label: 'Pix',
  fulfillment_type: 'delivery',
  neighborhood: 'Jardins',
  branch: { name: 'Yris Cakes', phone: '(79) 99999-0000' },
  customer_first_name: 'Ana',
} as unknown as TrackedOrder

describe('WhatsApp do pedido', () => {
  it('aceita celular e fixo com DDD; recusa o resto', () => {
    expect(phoneDigits('(79) 99999-0000')).toBe('79999990000')
    expect(phoneDigits('+55 79 3211-0000')).toBe('7932110000')
    expect(phoneDigits('123')).toBe('')
    expect(whatsappLink(null, 'x')).toBeNull()
  })

  it('mensagem traz número, itens, total, pagamento e bairro — sem rua', () => {
    const text = orderMessage(order)
    expect(text).toContain('pedido nº 42')
    expect(text).toContain('• 1× Bolo de pote (Ninho)')
    expect(text).toContain('• 2× Brigadeiro')
    expect(text).toMatch(/Total: R\$\s28,00/)
    expect(text).toContain('Pagamento: Pix')
    expect(text).toContain('Entrega no bairro Jardins')
    expect(whatsappLink('(79) 99999-0000', text)).toMatch(/^https:\/\/wa\.me\/5579999990000\?text=Ol%C3%A1/)
  })
})
