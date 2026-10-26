import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SPasswordResetCard from './SPasswordResetCard.vue'

const toError = (e: unknown) => ({ message: String(e) })

describe('Esqueci a senha', () => {
  it('pede o link e mostra a resposta do servidor (sempre a mesma)', async () => {
    const requestLink = vi.fn().mockResolvedValue('Se este e-mail tiver acesso…')
    const w = mount(SPasswordResetCard, { props: { mode: 'request', title: 'Painel', requestLink, reset: vi.fn(), toError } })
    await w.find('input[type="email"]').setValue(' ana@loja.com ')
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(requestLink).toHaveBeenCalledWith('ana@loja.com')
    expect(w.text()).toContain('Se este e-mail tiver acesso')
    expect(w.find('form').exists()).toBe(false)
  })

  it('cria a senha nova com o token do link; link incompleto avisa', async () => {
    const reset = vi.fn().mockResolvedValue('Senha criada.')
    const w = mount(SPasswordResetCard, { props: { mode: 'reset', title: 'Painel', token: 't0k', email: 'ana@loja.com', requestLink: vi.fn(), reset, toError } })
    const [pwd, confirm] = w.findAll('input[type="password"]')
    await pwd!.setValue('Nova@1')
    await confirm!.setValue('Nova@1')
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(reset).toHaveBeenCalledWith({ email: 'ana@loja.com', token: 't0k', password: 'Nova@1', password_confirmation: 'Nova@1' })
    expect(w.emitted('done')![0]).toEqual(['Senha criada.'])

    const broken = mount(SPasswordResetCard, { props: { mode: 'reset', title: 'Painel', token: '', email: '', requestLink: vi.fn(), reset, toError } })
    expect(broken.text()).toContain('Link incompleto')
  })
})
