import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { merchantBillingApi, type Invoice } from '@sertec/api-client'
import BillingPage from './BillingPage.vue'

afterEach(() => {
  vi.restoreAllMocks()
  vi.useRealTimers()
})

const OPEN: Invoice = {
  id: 'inv1', plan_name: 'Cardápio Digital', months: 1, amount: '89.90', period_start: '2026-11-01', period_end: '2026-11-30',
  due_date: '2026-11-01', status: 'open', overdue: true, paid_at: null, paid_via: null, note: null, pix: null,
}

describe('Assinatura (mensalidade SerTec)', () => {
  it('mostra a fatura atrasada, gera o Pix e confirma sozinho quando pago', async () => {
    vi.useFakeTimers()
    vi.spyOn(merchantBillingApi, 'show').mockResolvedValueOnce({
      pix_available: true,
      subscription: { plan: { slug: 'cardapio', name: 'Cardápio Digital' }, status: 'past_due', billing_months: 1, period_label: 'Mensal', custom_price: null, price: '89.90', paid_until: '2026-10-31' },
      invoices: [OPEN],
    }).mockResolvedValue({
      pix_available: true,
      subscription: { plan: { slug: 'cardapio', name: 'Cardápio Digital' }, status: 'active', billing_months: 1, period_label: 'Mensal', custom_price: null, price: '89.90', paid_until: '2026-11-30' },
      invoices: [{ ...OPEN, status: 'paid', overdue: false, paid_at: '2026-11-02T10:00:00-03:00', paid_via: 'pix' }],
    })
    const pix = vi.spyOn(merchantBillingApi, 'pix').mockResolvedValue({ ...OPEN, pix: { code: '000201PIXCODE', qr_base64: 'aGVsbG8=', expires_at: null } })
    vi.spyOn(merchantBillingApi, 'status').mockResolvedValue({ ...OPEN, status: 'paid' })

    const wrapper = mount(BillingPage)
    await flushPromises()
    expect(wrapper.text()).toContain('Em atraso')
    expect(wrapper.text()).toContain('Venceu em 01/11/2026')
    expect(wrapper.text()).toContain('R$ 89,90 por mês')

    await wrapper.findAll('button').find((b) => b.text() === 'Pagar com Pix')!.trigger('click')
    await flushPromises()
    expect(pix).toHaveBeenCalledWith('inv1')
    expect((wrapper.find('textarea').element as HTMLTextAreaElement).value).toBe('000201PIXCODE')

    await vi.advanceTimersByTimeAsync(5100)
    await flushPromises()
    expect(wrapper.text()).toContain('Em dia')
    expect(wrapper.text()).toContain('Paga em')
    expect(wrapper.find('textarea').exists()).toBe(false)
  })
})
