import { afterEach, describe, expect, it } from 'vitest'
import type { MerchantMe } from '@sertec/api-client'
import { router } from './router'
import { me } from './session'

afterEach(() => {
  me.value = null
})

function session(over: Partial<MerchantMe>) {
  me.value = {
    user: { id: 'u', name: 'Ana', email: 'a@x.com' }, tenant: { id: 't', name: 'Loja', slug: 'loja', timezone: 'America/Bahia' },
    roles: ['owner'], permissions: ['orders.view', 'billing.manage', 'cash.operate'], all_branches: true,
    modules: ['menu', 'pos'], features: ['online_ordering', 'cashbook'], blocked: false, ...over,
  }
}

describe('Rotas do painel por módulo e bloqueio', () => {
  it('módulo não contratado abre a explicação', async () => {
    session({ features: ['online_ordering'] })
    await router.push('/pdv')
    expect(router.currentRoute.value.name).toBe('module-locked')
    expect(router.currentRoute.value.params.modulo).toBe('cashbook')
  })

  it('bloqueado: o dono vai para Assinatura; a equipe vê o aviso', async () => {
    session({ blocked: true, features: [] })
    await router.push('/pedidos')
    expect(router.currentRoute.value.name).toBe('billing')

    session({ blocked: true, features: [], permissions: ['orders.view'] })
    await router.push('/historico')
    expect(router.currentRoute.value.name).toBe('blocked')
    await router.push('/minha-conta')
    expect(router.currentRoute.value.name).toBe('account')
  })
})
