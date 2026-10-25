import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { merchantOrdersApi, reportsApi } from '@sertec/api-client'
import HistoryPage from './HistoryPage.vue'
import { me } from '../session'

beforeEach(() => {
  me.value = {
    user: { id: 'u', name: 'Dono', email: 'd@a.com' }, tenant: { id: 't', name: 'Pizzaria', slug: 'p', timezone: 'America/Maceio' },
    roles: ['owner'], permissions: ['orders.view', 'reports.view'],
  } as never
})
afterEach(() => vi.restoreAllMocks())

describe('Histórico', () => {
  it('busca com filtros, mostra o resumo e o link do CSV com os mesmos filtros', async () => {
    const list = vi.spyOn(merchantOrdersApi, 'list').mockResolvedValue({ data: [], meta: { next_cursor: null } })
    const summary = vi.spyOn(reportsApi, 'summary').mockResolvedValue({
      orders: 3, completed: 2, cancelled: 1, in_progress: 0, revenue: '120.00', average_ticket: '60.00', refunded: '0.00',
      by_payment_method: [{ method: 'pix', label: 'Pix', orders: 2, total: '120.00' }],
    })
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: HistoryPage }] })
    const wrapper = mount(HistoryPage, { global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.text()).toContain('R$ 120,00')
    expect(wrapper.text()).toContain('Ticket médio')
    expect(wrapper.text()).toContain('Pix: R$ 120,00 (2)')

    await wrapper.find('input[type="search"]').setValue('maria')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    const [, , filter] = list.mock.calls.at(-1)!
    expect(filter).toMatchObject({ q: 'maria' })
    expect(summary).toHaveBeenLastCalledWith(expect.objectContaining({ q: 'maria' }))
    expect(wrapper.find('a[download]').attributes('href')).toContain('q=maria')
    expect(wrapper.find('a[download]').attributes('href')).toContain('/api/v1/merchant/reports/orders.csv?')
  })
})
