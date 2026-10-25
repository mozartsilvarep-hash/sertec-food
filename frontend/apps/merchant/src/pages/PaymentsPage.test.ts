import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError, paymentsApi, type MercadoPagoSettings } from '@sertec/api-client'
import PaymentsPage from './PaymentsPage.vue'

afterEach(() => vi.restoreAllMocks())

const DISCONNECTED: MercadoPagoSettings = {
  provider: 'mercadopago', connected: false, mode: null, account_label: null, account_id: null, token_hint: null,
  has_webhook_secret: false, verified_at: null, webhook_url: 'https://pizzaria.cardapios.test/api/v1/webhooks/mercadopago',
}

describe('Pagamentos online', () => {
  it('conecta com o token colado e depois só mostra os 4 últimos caracteres', async () => {
    vi.spyOn(paymentsApi, 'settings').mockResolvedValue(DISCONNECTED)
    const connect = vi.spyOn(paymentsApi, 'connect').mockResolvedValue({
      ...DISCONNECTED, connected: true, mode: 'test', account_label: 'PIZZARIA', account_id: '123', token_hint: 'a1b2', has_webhook_secret: true,
    })
    const wrapper = mount(PaymentsPage)
    await flushPromises()

    expect(wrapper.text()).toContain(DISCONNECTED.webhook_url)
    const inputs = wrapper.findAll('input[type="password"]')
    await inputs[0]!.setValue('TEST-0000000000000000-000000-segredo-a1b2')
    await inputs[1]!.setValue('assinatura0123456789')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(connect).toHaveBeenCalledWith({ access_token: 'TEST-0000000000000000-000000-segredo-a1b2', webhook_secret: 'assinatura0123456789' })
    expect(wrapper.text()).toContain('Conectado: PIZZARIA')
    expect(wrapper.text()).toContain('terminando em a1b2')
    expect(wrapper.text()).toContain('Modo de teste')
    expect(wrapper.html()).not.toContain('segredo-a1b2')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('mostra o erro do servidor quando o Mercado Pago recusa o token', async () => {
    vi.spyOn(paymentsApi, 'settings').mockResolvedValue(DISCONNECTED)
    vi.spyOn(paymentsApi, 'connect').mockRejectedValue(new ApiError(422, { code: 'invalid_provider_credentials', message: 'O Mercado Pago recusou estas credenciais. Confira o Access Token.' }))
    const wrapper = mount(PaymentsPage)
    await flushPromises()

    await wrapper.findAll('input[type="password"]')[0]!.setValue('TEST-errado-00000000000000000000')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').text()).toContain('recusou estas credenciais')
  })
})
