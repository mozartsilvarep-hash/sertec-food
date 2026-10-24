import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError, cartApi, orderApi } from '@sertec/api-client'
import CheckoutPage from './CheckoutPage.vue'
import { branchId, cart, store } from '../shop'

const CART = { token: 'a'.repeat(48), branch_id: 'B1', expires_at: '', items: [{ id: 'i1', product_id: 'p', product_name: 'Pizza', variant_name: 'Média', quantity: 1, notes: null, options: [], unit_price: '40.00', line_total: '40.00', valid: true, problem: null }], subtotal: '40.00', valid: true, count: 1 }

beforeEach(() => {
  store.value = {
    name: 'Pizzaria', slug: 'pizzaria', timezone: 'America/Maceio', description: null, brand_color: null,
    branches: [{
      id: 'B1', name: 'Centro', phone: null, address: null, has_hours: false, open: false, closes_at: null, next_opening_at: null,
      fulfillment: { pickup_enabled: true, delivery_enabled: true, pickup_eta_minutes: 25, zones: [
        { id: 'Z1', name: 'Orla', neighborhoods: ['Pajuçara', 'Ponta Verde'], fee: '7.00', minimum_order: '30.00', free_above: '120.00', eta_minutes: 50 },
      ] },
    }],
  }
  branchId.value = 'B1'
  cart.value = { ...CART }
  vi.spyOn(cartApi, 'get').mockResolvedValue(CART)
  try { localStorage.setItem('sertec:cart:pizzaria', CART.token) } catch { /* jsdom */ }
})
afterEach(() => vi.restoreAllMocks())

async function mountPage() {
  const router = createRouter({ history: createMemoryHistory(), routes: [
    { path: '/finalizar', component: CheckoutPage },
    { path: '/carrinho', component: { template: '<p/>' } },
    { path: '/pedido/:token', component: { template: '<p>pedido</p>' } },
  ] })
  await router.push('/finalizar')
  const wrapper = mount(CheckoutPage, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

async function fill(wrapper: Awaited<ReturnType<typeof mountPage>>['wrapper']) {
  await wrapper.find('#bairro').setValue('Ponta Verde')
  const inputs = wrapper.findAll('input[type="text"], input[type="tel"]')
  const byLabel = (text: string) => wrapper.findAll('label').find((l) => l.text().startsWith(text))!
  await wrapper.find(`#${byLabel('Rua').attributes('for')}`).setValue('Rua A')
  await wrapper.find(`#${byLabel('Número').attributes('for')}`).setValue('10')
  await wrapper.find(`#${byLabel('Nome').attributes('for')}`).setValue('Maria')
  await wrapper.find(`#${byLabel('Celular').attributes('for')}`).setValue('82999990000')
  expect(inputs.length).toBeGreaterThan(0)
}

describe('Checkout', () => {
  it('calcula a taxa da zona do bairro e envia só escolhas, nunca preços', async () => {
    const spy = vi.spyOn(orderApi, 'checkout').mockResolvedValue({ order_id: 'o', order_number: 1, status: 'pending_acceptance', payment_status: 'pending', payment_method: 'card_on_delivery', fulfillment_type: 'delivery', total: '47.00', eta_minutes: 50, tracking_token: 'f'.repeat(40) })
    const { wrapper, router } = await mountPage()
    await fill(wrapper)

    expect(wrapper.text()).toContain('Fazer pedido · R$ 47,00')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    const [payload, key] = spy.mock.calls[0]!
    expect(payload.fulfillment).toMatchObject({ type: 'delivery', zone_id: 'Z1', address: { neighborhood: 'Ponta Verde', street: 'Rua A', number: '10' } })
    expect(JSON.stringify(payload)).not.toMatch(/price|total|fee/)
    expect(key).toMatch(/^[0-9a-f-]{36}$/)
    expect(router.currentRoute.value.path).toBe(`/pedido/${'f'.repeat(40)}`)
  })

  it('reenvio após falha de rede usa a MESMA chave; dados alterados geram outra', async () => {
    const spy = vi.spyOn(orderApi, 'checkout').mockRejectedValue(new ApiError(0, { code: 'network_error', message: 'Sem conexão.' }))
    const { wrapper } = await mountPage()
    await fill(wrapper)

    await wrapper.find('form').trigger('submit'); await flushPromises()
    await wrapper.find('form').trigger('submit'); await flushPromises()
    expect(spy.mock.calls[0]![1]).toBe(spy.mock.calls[1]![1])
    expect(wrapper.find('[role="alert"]').text()).toContain('Sem conexão')

    await wrapper.find('#bairro').setValue('Pajuçara')
    await wrapper.find('form').trigger('submit'); await flushPromises()
    expect(spy.mock.calls[2]![1]).not.toBe(spy.mock.calls[0]![1])
  })

  it('bloqueia envio abaixo do pedido mínimo do bairro', async () => {
    cart.value = { ...CART, subtotal: '20.00' }
    vi.spyOn(cartApi, 'get').mockResolvedValue({ ...CART, subtotal: '20.00' })
    const { wrapper } = await mountPage()
    await wrapper.find('#bairro').setValue('Pajuçara')

    expect(wrapper.text()).toContain('Pedido mínimo para Pajuçara: R$ 30,00')
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()
  })
})
