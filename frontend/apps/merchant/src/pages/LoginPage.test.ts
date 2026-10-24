import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError, merchantApi } from '@sertec/api-client'
import LoginPage from './LoginPage.vue'
import { me } from '../session'

function setup() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/login', name: 'login', component: LoginPage },
      { path: '/', name: 'home', component: { template: '<p>home</p>' } },
    ],
  })
  return { router, wrapper: mount(LoginPage, { global: { plugins: [router] } }) }
}

beforeEach(() => {
  me.value = null
  vi.restoreAllMocks()
})

describe('LoginPage (merchant)', () => {
  it('envia só e-mail e senha e vai para o painel', async () => {
    const login = vi.spyOn(merchantApi, 'login').mockResolvedValue({
      user: { id: 'u1', name: 'Dono', email: 'dono@a.com' },
      tenant: { id: 't1', name: 'Pizzaria', slug: 'pizzaria', timezone: 'America/Maceio' },
      roles: ['owner'],
      permissions: ['branches.view'],
      all_branches: true,
    })
    const { router, wrapper } = setup()
    const replace = vi.spyOn(router, 'replace')

    await wrapper.find('input[type="email"]').setValue('dono@a.com')
    await wrapper.find('input[type="password"]').setValue('segredo')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(login).toHaveBeenCalledWith({ email: 'dono@a.com', password: 'segredo' })
    expect(me.value?.tenant.slug).toBe('pizzaria')
    expect(replace).toHaveBeenCalledWith({ name: 'home' })
  })

  it('mostra a mensagem do servidor e limpa a senha quando falha', async () => {
    vi.spyOn(merchantApi, 'login').mockRejectedValue(
      new ApiError(422, { code: 'invalid_credentials', message: 'E-mail ou senha inválidos.' }),
    )
    const { wrapper } = setup()

    await wrapper.find('input[type="email"]').setValue('x@a.com')
    await wrapper.find('input[type="password"]').setValue('errada')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').text()).toBe('E-mail ou senha inválidos.')
    expect((wrapper.find('input[type="password"]').element as HTMLInputElement).value).toBe('')
    expect(me.value).toBeNull()
  })
})
