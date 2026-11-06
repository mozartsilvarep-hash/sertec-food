import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { cartApi, tableApi } from '@sertec/api-client'
import CartPage from './CartPage.vue'
import { branchId, cart, leaveTable, store, table, tableRequestToken } from '../shop'

const CART = { token: 'a'.repeat(48), branch_id: 'B1', expires_at: '', items: [{ id: 'i1', product_id: 'p', product_name: 'Pizza', variant_name: 'Média', quantity: 1, notes: null, options: [], unit_price: '40.00', line_total: '40.00', valid: true, problem: null }], subtotal: '40.00', valid: true, count: 1 }

beforeEach(() => {
  store.value = { name: 'Pizzaria', slug: 'pizzaria', timezone: 'America/Maceio', description: null, brand_color: null, branches: [] } as never
  branchId.value = 'B1'
  cart.value = { ...CART }
  vi.spyOn(cartApi, 'get').mockResolvedValue(CART)
})
afterEach(() => {
  vi.restoreAllMocks()
  leaveTable()
})

async function mountPage() {
  const router = createRouter({ history: createMemoryHistory(), routes: [
    { path: '/carrinho', component: CartPage },
    { path: '/finalizar', name: 'checkout', component: { template: '<p>checkout</p>' } },
    { path: '/mesa/pedido/:id', name: 'table-request', component: { template: '<p>status</p>' } },
  ] })
  await router.push('/carrinho')
  const wrapper = mount(CartPage, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

describe('Carrinho no modo mesa', () => {
  it('sem mesa: segue para finalizar com entrega/pagamento', async () => {
    const { wrapper } = await mountPage()
    expect(wrapper.text()).toContain('Continuar')
    expect(wrapper.text()).not.toContain('Enviar pedido')
  })

  it('na mesa: envia para o garçom confirmar e abre o acompanhamento', async () => {
    table.value = { token: 'q'.repeat(24), label: 'Mesa 4', branchId: 'B1', since: Date.now() }
    const send = vi.spyOn(tableApi, 'send').mockResolvedValue({ id: 'R1', type: 'order', status: 'pending', summary: '1× Pizza', reason: null, created_at: '', view_token: 'v'.repeat(40) })
    const { wrapper, router } = await mountPage()
    expect(wrapper.text()).toContain('o garçom confirma o pedido')
    await wrapper.find('input[autocomplete="given-name"]').setValue('Rita')
    await wrapper.findAll('button').find((b) => b.text() === 'Enviar pedido para a Mesa 4')!.trigger('click')
    await flushPromises()

    expect(send).toHaveBeenCalledWith('q'.repeat(24), { type: 'order', cart_token: CART.token, customer_name: 'Rita', notes: null })
    expect(router.currentRoute.value.path).toBe('/mesa/pedido/R1')
    expect(tableRequestToken('R1')).toBe('v'.repeat(40))
    expect(cart.value).toBeNull()
  })
})
