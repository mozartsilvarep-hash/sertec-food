import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError, storefrontApi } from '@sertec/api-client'
import App from './App.vue'

afterEach(() => vi.restoreAllMocks())

describe('Storefront shell', () => {
  it('mostra o nome do estabelecimento resolvido pelo domínio', async () => {
    vi.spyOn(storefrontApi, 'show').mockResolvedValue({ name: 'Pizzaria Demo', slug: 'pizzaria-demo', timezone: 'America/Maceio' })
    const wrapper = mount(App)
    await flushPromises()

    expect(wrapper.find('h1').text()).toBe('Pizzaria Demo')
  })

  it('estabelecimento suspenso mostra estado indisponível', async () => {
    vi.spyOn(storefrontApi, 'show').mockRejectedValue(new ApiError(403, { code: 'tenant_unavailable', message: 'x' }))
    const wrapper = mount(App)
    await flushPromises()

    expect(wrapper.text()).toContain('Indisponível no momento')
  })
})
