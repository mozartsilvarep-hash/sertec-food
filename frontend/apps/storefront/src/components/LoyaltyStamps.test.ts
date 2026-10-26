import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { loyaltyRewardCents, type LoyaltyRule } from '@sertec/api-client'
import LoyaltyStamps from './LoyaltyStamps.vue'

describe('Cartão fidelidade', () => {
  it('mostra os selos e o que falta; com prêmio, destaca o prêmio', () => {
    const w = mount(LoyaltyStamps, { props: { status: { stamps: 2, required: 5, rewards: 0, reward_label: 'R$ 10,00 de desconto' } } })
    expect(w.text()).toContain('2 de 5 selos')
    expect(w.text()).toContain('Faltam 3 para ganhar R$ 10,00 de desconto')
    expect(w.findAll('span.rounded-full').filter((s) => s.text() === '★')).toHaveLength(2)

    const prize = mount(LoyaltyStamps, { props: { status: { stamps: 0, required: 5, rewards: 1, reward_label: 'R$ 10,00 de desconto' } } })
    expect(prize.text()).toContain('Você tem 1 prêmio: R$ 10,00 de desconto.')
  })

  it('prévia do prêmio: fixo, percentual com teto e nunca acima dos itens', () => {
    const fixed: LoyaltyRule = { stamps_required: 5, reward_label: '', min_order: null, reward_type: 'fixed', reward_amount: '10.00', reward_percent_bp: null, reward_max: null }
    expect(loyaltyRewardCents(fixed, 4000)).toBe(1000)
    expect(loyaltyRewardCents(fixed, 650)).toBe(650)
    const pct: LoyaltyRule = { ...fixed, reward_type: 'percent', reward_amount: null, reward_percent_bp: 5000, reward_max: '15.00' }
    expect(loyaltyRewardCents(pct, 4000)).toBe(1500)
    expect(loyaltyRewardCents(pct, 2001)).toBe(1001)
  })
})
