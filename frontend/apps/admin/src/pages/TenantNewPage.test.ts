import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { adminTenantsApi } from '@sertec/api-client'
import TenantNewPage from './TenantNewPage.vue'
import { slugify } from '../labels'

afterEach(() => vi.restoreAllMocks())

describe('Novo estabelecimento', () => {
  it('sugere o endereço pelo nome e mostra a senha do dono uma única vez', async () => {
    vi.spyOn(adminTenantsApi, 'plans').mockResolvedValue([{ slug: 'essencial', name: 'Essencial', monthly_price: '0.00', active: true }])
    const create = vi.spyOn(adminTenantsApi, 'create').mockResolvedValue({
      id: 't1', name: 'Cantina São João', slug: 'cantina-sao-joao', status: 'active', host: 'cantina-sao-joao.cardapios.test', plan: null,
      created_at: null, timezone: 'America/Maceio', owner: { name: 'Rosa', email: 'rosa@x.com', last_login_at: null },
      domains: [], branches: [], staff_count: 1, orders_total: 0, last_order_at: null, payments: null, owner_password: 'Senha16Caracter1',
    })
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { template: '<p/>' } }] })
    const wrapper = mount(TenantNewPage, { global: { plugins: [router] } })
    await flushPromises()

    const field = (label: string) => wrapper.find(`#${wrapper.findAll('label').find((l) => l.text().startsWith(label))!.attributes('for')}`)
    await field('Nome do estabelecimento').setValue('Cantina São João')
    expect((field('Endereço do cardápio').element as HTMLInputElement).value).toBe('cantina-sao-joao')
    await field('Nome do proprietário').setValue('Rosa')
    await field('E-mail do proprietário').setValue('rosa@x.com')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(create).toHaveBeenCalledWith(expect.objectContaining({ slug: 'cantina-sao-joao', activate: true, plan: null }))
    expect(wrapper.text()).toContain('Senha16Caracter1')
    expect(slugify('  Pizzaria D’Ângelo & Cia!! ')).toBe('pizzaria-d-angelo-cia')
  })
})
