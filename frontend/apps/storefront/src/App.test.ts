import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError, publicCatalogApi, type StorefrontInfo } from '@sertec/api-client'
import App from './App.vue'

const store: StorefrontInfo = {
  name: 'Pizzaria Demo', slug: 'pizzaria-demo', timezone: 'America/Maceio', description: 'Forno a lenha', brand_color: '#9a3412',
  branches: [{ id: 'B1', name: 'Centro', phone: null, address: null, has_hours: true, open: true, closes_at: '2026-10-23T23:30:00-03:00', next_opening_at: null }],
}

function mockCatalog() {
  vi.spyOn(publicCatalogApi, 'storefront').mockResolvedValue(store)
  vi.spyOn(publicCatalogApi, 'menus').mockResolvedValue({
    branch_id: 'B1',
    menus: [{
      id: 'M', name: 'Cardápio', categories: [
        { id: 'C1', name: 'Pizzas', description: null, products: [
          { id: 'P1', name: 'Pizza tradicional', description: 'Calabresa ou margherita', image_url: null, available: true, has_options: true, price_from: '39.90' },
        ] },
        { id: 'C2', name: 'Bebidas', description: null, products: [
          { id: 'P2', name: 'Refrigerante lata', description: null, image_url: null, available: false, has_options: false, price_from: '6.50' },
        ] },
      ],
    }],
  })
}

afterEach(() => vi.restoreAllMocks())

describe('Cardápio público', () => {
  it('mostra nome, status de funcionamento, categorias e preços', async () => {
    mockCatalog()
    const wrapper = mount(App)
    await flushPromises()

    expect(wrapper.find('h1').text()).toBe('Pizzaria Demo')
    expect(wrapper.text()).toContain('Aberto até 23:30')
    expect(wrapper.text()).toContain('a partir de R$ 39,90')
    expect(wrapper.text()).toContain('Esgotado hoje')
    expect(document.documentElement.style.getPropertyValue('--brand-600')).toBe('#9a3412')
  })

  it('busca ignora acentos e maiúsculas', async () => {
    mockCatalog()
    const wrapper = mount(App)
    await flushPromises()

    await wrapper.find('input[type="search"]').setValue('REFRIGERANTE')
    expect(wrapper.text()).toContain('Refrigerante lata')
    expect(wrapper.text()).not.toContain('Pizza tradicional')

    await wrapper.find('input[type="search"]').setValue('calabresa')
    expect(wrapper.text()).toContain('Pizza tradicional')
  })

  it('estabelecimento suspenso mostra estado indisponível', async () => {
    vi.spyOn(publicCatalogApi, 'storefront').mockRejectedValue(new ApiError(403, { code: 'tenant_unavailable', message: 'x' }))
    const wrapper = mount(App)
    await flushPromises()

    expect(wrapper.text()).toContain('Indisponível no momento')
  })
})
