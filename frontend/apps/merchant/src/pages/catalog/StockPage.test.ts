import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { merchantApi, stockApi, type Branch, type MerchantMe, type StockRow } from '@sertec/api-client'
import { me } from '../../session'
import StockPage from './StockPage.vue'

afterEach(() => {
  vi.restoreAllMocks()
  me.value = null
})

const BRANCH = { id: '01HZZZZZZZZZZZZZZZZZZZZZZB', name: 'Centro' } as Branch
const ROWS: StockRow[] = [
  { id: 'p1', name: 'Refrigerante lata', code: 'COCA', active: true, tracked: true, quantity: 2, min_quantity: 5, low: true, sold_out: false },
  { id: 'p2', name: 'Pudim', code: null, active: true, tracked: true, quantity: 0, min_quantity: 0, low: true, sold_out: true },
  { id: 'p3', name: 'Pizza', code: null, active: true, tracked: false, quantity: null, min_quantity: null, low: false, sold_out: false },
]

function asManager(permissions = ['catalog.view', 'catalog.manage']) {
  me.value = { permissions, tenant: { timezone: 'America/Sao_Paulo' } } as unknown as MerchantMe
}

describe('Estoque', () => {
  it('mostra só os controlados por padrão, com estoque baixo e esgotado', async () => {
    asManager()
    vi.spyOn(merchantApi, 'branches').mockResolvedValue([BRANCH])
    vi.spyOn(stockApi, 'list').mockResolvedValue({ branch: BRANCH, products: ROWS })
    const wrapper = mount(StockPage)
    await flushPromises()

    expect(wrapper.text()).toContain('Refrigerante lata')
    expect(wrapper.text()).toContain('Estoque baixo')
    expect(wrapper.text()).toContain('Esgotado')
    expect(wrapper.text()).not.toContain('Pizza')

    await wrapper.findAll('button').find((b) => b.text() === 'Todos')!.trigger('click')
    expect(wrapper.text()).toContain('Pizza')
    expect(wrapper.text()).toContain('Controlar estoque')
  })

  it('ajuste envia a quantidade inteira e atualiza o saldo', async () => {
    asManager()
    vi.spyOn(merchantApi, 'branches').mockResolvedValue([BRANCH])
    vi.spyOn(stockApi, 'list').mockResolvedValue({ branch: BRANCH, products: ROWS.map((r) => ({ ...r })) })
    const adjust = vi.spyOn(stockApi, 'adjust').mockResolvedValue({ quantity: 14, low: false, sold_out: false })
    const wrapper = mount(StockPage, { attachTo: document.body })
    await flushPromises()

    await wrapper.findAll('button').find((b) => b.text() === 'Ajustar')!.trigger('click')
    await flushPromises()
    const form = wrapper.findAll('form').find((f) => f.text().includes('Salvar ajuste'))!
    await form.find('input[inputmode="numeric"]').setValue('12')
    await form.trigger('submit')
    await flushPromises()

    expect(adjust).toHaveBeenCalledWith('p1', { branch: BRANCH.id, type: 'entry', quantity: 12, reason: null })
    wrapper.unmount()
  })

  it('atendente só consulta: sem botões de ajuste', async () => {
    asManager(['catalog.view'])
    vi.spyOn(merchantApi, 'branches').mockResolvedValue([BRANCH])
    vi.spyOn(stockApi, 'list').mockResolvedValue({ branch: BRANCH, products: ROWS })
    const wrapper = mount(StockPage)
    await flushPromises()

    expect(wrapper.text()).toContain('Histórico')
    expect(wrapper.text()).not.toContain('Ajustar')
  })
})
