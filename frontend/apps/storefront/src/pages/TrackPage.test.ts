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
  timeline: [], final: false,
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
    expect(wrapper.text()).toContain('Pedido #12')
    expect(wrapper.text()).toContain('cerca de 30 min para retirar')
    expect(wrapper.text()).toContain('(etapa atual)')
    expect(wrapper.text()).toContain('Calabresa')
    wrapper.unmount()
  })
})
