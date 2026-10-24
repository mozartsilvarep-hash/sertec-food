import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { adminApi } from '@sertec/api-client'
import LoginPage from './LoginPage.vue'
import { me } from '../session'

describe('LoginPage (admin)', () => {
  it('autentica a equipe SerTec', async () => {
    vi.spyOn(adminApi, 'login').mockResolvedValue({
      user: { id: 'u1', name: 'Ana', email: 'ana@sertec.com', platform_role: 'superadmin' },
    })
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/', name: 'home', component: { template: '<p/>' } }],
    })
    const wrapper = mount(LoginPage, { global: { plugins: [router] } })

    await wrapper.find('input[type="email"]').setValue('ana@sertec.com')
    await wrapper.find('input[type="password"]').setValue('x')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(me.value?.user.platform_role).toBe('superadmin')
  })
})
