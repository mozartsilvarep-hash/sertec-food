import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { merchantApi, teamApi, type StaffMember } from '@sertec/api-client'
import TeamPage from './TeamPage.vue'

afterEach(() => vi.restoreAllMocks())

const OWNER: StaffMember = { id: 'u1', name: 'Dono', email: 'dono@a.com', role: 'owner', role_name: 'Proprietário', branch: null, active: true, last_login_at: null, is_self: true, is_owner: true }

describe('Equipe', () => {
  it('adiciona atendente numa unidade e mostra a senha temporária uma vez', async () => {
    vi.spyOn(teamApi, 'list').mockResolvedValue([OWNER])
    vi.spyOn(merchantApi, 'branches').mockResolvedValue([{ id: 'B1', name: 'Centro', phone: null, address: null, active: true }])
    const add = vi.spyOn(teamApi, 'add').mockResolvedValue({
      id: 'u2', name: 'Ana', email: 'ana@a.com', role: 'attendant', role_name: 'Atendente', branch: { id: 'B1', name: 'Centro' },
      active: true, last_login_at: null, is_self: false, is_owner: false, temporary_password: 'Abc123def456',
    })
    const wrapper = mount(TeamPage)
    await flushPromises()

    // Dono não tem controles de edição
    expect(wrapper.findAll('select')).toHaveLength(0)

    await wrapper.findAll('button').find((b) => b.text() === 'Adicionar pessoa')!.trigger('click')
    const field = (label: string) => wrapper.find(`#${wrapper.findAll('label').find((l) => l.text().startsWith(label))!.attributes('for')}`)
    await field('Nome').setValue('Ana')
    await field('E-mail').setValue('ana@a.com')
    await wrapper.find('select').setValue('B1')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(add).toHaveBeenCalledWith({ name: 'Ana', email: 'ana@a.com', role: 'attendant', branch_id: 'B1' })
    expect(wrapper.text()).toContain('Abc123def456')
    expect(wrapper.text()).toContain('Ana')
  })
})
