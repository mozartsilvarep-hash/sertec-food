import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { orderApi, type TrackedOrder } from '@sertec/api-client'
import TrackPage from './TrackPage.vue'

afterEach(() => vi.restoreAllMocks())

const order: TrackedOrder = {
  id: 'o', number: 12, status: 'preparing', status_label: 'Em preparo', payment_status: 'pending', payment_method: 'cash',
  payment_method_label: 'Dinheiro', fulfillment_type: 'pickup', placed_at: '2026-10-24T19:00:00-03:00', eta_minutes: 30,
  customer_first_name: 'Maria', customer_phone_masked: '(82) •••••-0000', neighborhood: null, branch: { name: 'Centro', phone: null },
  cancellation_reason: null, items: [{ name: 'Pizza', variant: 'Média', quantity: 1, unit_price: '40.00', line_total: '40.00', notes: null, options: [{ group: 'Sabores', name: 'Calabresa', quantity: 1 }] }],
  totals: [{ code: 'subtotal', label: 'Subtotal', amount: '40.00' }, { code: 'total', label: 'Total', amount: '40.00' }],
  timeline: [], final: false, payment: null,
  steps: [
    { status: 'pending_acceptance', label: 'Aguardando confirmação' }, { status: 'accepted', label: 'Pedido confirmado' },
    { status: 'preparing', label: 'Em preparo' }, { status: 'ready', label: 'Pronto' }, { status: 'completed', label: 'Concluído' },
  ],
}

describe('Acompanhamento', () => {
  it('mostra a etapa atual, a previsão e os itens', async () => {
    vi.spyOn(orderApi, 'track').mockResolvedValue(order)
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<p/>' } }] })
    const wrapper = mount(TrackPage, { props: { token: 't' }, global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.find('h2').text()).toBe('Em preparo')
    expect(wrapper.text()).toContain('Pedido nº 12')
    expect(wrapper.text()).toContain('cerca de 30 min para retirar')
    expect(wrapper.text()).toContain('(etapa atual)')
    expect(wrapper.text()).toContain('Calabresa')
    wrapper.unmount()
  })

  it('pedido recém-feito: botão verde envia o pedido nº pelo WhatsApp da loja', async () => {
    vi.spyOn(orderApi, 'track').mockResolvedValue({ ...order, status: 'pending_acceptance', status_label: 'Aguardando confirmação', branch: { name: 'Centro', phone: '(79) 99888-7766' } })
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<p/>' } }] })
    const wrapper = mount(TrackPage, { props: { token: 't' }, global: { plugins: [router] } })
    await flushPromises()
    const send = wrapper.findAll('a').find((a) => a.text() === 'Enviar pedido nº 12 pelo WhatsApp')!
    const href = decodeURIComponent(send.attributes('href')!)
    expect(href).toMatch(/^https:\/\/wa\.me\/5579998887766\?text=/)
    expect(href).toContain('pedido nº 12')
    expect(href).toContain('1× Pizza (Calabresa)')
    expect(href).toContain('Retirada em Centro')
    wrapper.unmount()
  })

  it('Pix aguardando: mostra QR, copia e cola, contagem e "Já paguei" consulta o servidor', async () => {
    const awaiting: TrackedOrder = {
      ...order, status: 'awaiting_payment', status_label: 'Aguardando pagamento', payment_method: 'pix', payment_method_label: 'Pix',
      payment: {
        status: 'pending', amount: '40.00', expires_at: new Date(Date.now() + 29 * 60_000 + 30_000).toISOString(), payable: true,
        needs_start: false, rejected: false, checkout_url: null,
        pix: { qr_code: '00020126580014br.gov.bcb.pix', qr_code_base64: 'iVBORw0KGgo=', ticket_url: null },
      },
    }
    vi.spyOn(orderApi, 'track').mockResolvedValue(awaiting)
    const refresh = vi.spyOn(orderApi, 'refreshPayment').mockResolvedValue({ ...awaiting, status: 'pending_acceptance', status_label: 'Aguardando confirmação', payment_status: 'paid', payment: null })
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<p/>' } }] })
    const wrapper = mount(TrackPage, { props: { token: 't' }, global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.find('img[alt="QR Code do Pix"]').attributes('src')).toBe('data:image/png;base64,iVBORw0KGgo=')
    expect((wrapper.find('#pix-code').element as HTMLTextAreaElement).value).toBe('00020126580014br.gov.bcb.pix')
    expect(wrapper.text()).toMatch(/Expira em 29:\d\d/)
    expect(wrapper.text()).not.toContain('Previsão')

    await wrapper.findAll('button').find((b) => b.text() === 'Copiar código Pix')!.trigger('click')
    await flushPromises()
    expect(writeText).toHaveBeenCalledWith('00020126580014br.gov.bcb.pix')
    expect(wrapper.text()).toContain('Código copiado!')

    await wrapper.findAll('button').find((b) => b.text() === 'Já paguei')!.trigger('click')
    await flushPromises()
    expect(refresh).toHaveBeenCalledWith('t')
    expect(wrapper.find('h2').text()).toBe('Aguardando confirmação')
    expect(wrapper.find('#pix-code').exists()).toBe(false)
    wrapper.unmount()
  })

  it('cartão: botão leva ao Checkout Pro e avisa recusa', async () => {
    vi.spyOn(orderApi, 'track').mockResolvedValue({
      ...order, status: 'awaiting_payment', status_label: 'Aguardando pagamento', payment_method: 'online_card', payment_method_label: 'Cartão pelo site',
      payment: { status: 'rejected', amount: '40.00', expires_at: new Date(Date.now() + 600_000).toISOString(), payable: true, needs_start: false, rejected: true, pix: null, checkout_url: 'https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=x' },
    })
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<p/>' } }] })
    const wrapper = mount(TrackPage, { props: { token: 't' }, global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.text()).toContain('não foi aprovado')
    const link = wrapper.findAll('a').find((a) => a.text() === 'Tentar outro cartão')!
    expect(link.attributes('href')).toBe('https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=x')
    wrapper.unmount()
  })
})
