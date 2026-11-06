import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { adminBillingApi, type AdminModule, type TenantBilling as Billing } from '@sertec/api-client'
import TenantBilling from './TenantBilling.vue'

afterEach(() => vi.restoreAllMocks())

const MODS: AdminModule[] = [
  { key: 'menu', name: 'Cardápio digital', description: null, active: true, requires: null, prices: { 1: '49.90', 3: null, 6: null, 12: '499.00' }, tenants: 1 },
  { key: 'pos', name: 'PDV e caixa', description: null, active: true, requires: null, prices: { 1: '79.90', 3: null, 6: null, 12: '799.00' }, tenants: 0 },
  { key: 'tabs', name: 'Mesas e comandas', description: null, active: true, requires: 'pos', prices: { 1: '39.90', 3: null, 6: null, 12: null }, tenants: 0 },
  { key: 'stock', name: 'Estoque', description: null, active: true, requires: null, prices: { 1: '19.90', 3: null, 6: null, 12: '199.00' }, tenants: 0 },
]
const BILLING: Billing = {
  subscription: {
    plan: { slug: 'cardapio', name: 'Básico' }, status: 'active', billing_months: 1, period_label: 'Mensal', modules: ['menu'],
    lines: [{ label: 'Cardápio digital', amount: '49.90', module: 'menu' }], catalog_price: '49.90', custom_price: null, price: '49.90', paid_until: null,
  },
  invoices: [],
}

function setup() {
  vi.spyOn(adminBillingApi, 'tenant').mockResolvedValue(BILLING)
  vi.spyOn(adminBillingApi, 'plans').mockResolvedValue({
    data: [{ slug: 'cardapio', name: 'Básico', description: null, active: true, monthly_price: '0.00', prices: { 1: null, 3: null, 6: null, 12: null }, features: {}, tenants: 1 }],
    meta: { periods: [{ months: 1, label: 'Mensal' }, { months: 3, label: 'Trimestral' }, { months: 6, label: 'Semestral' }, { months: 12, label: 'Anual' }], features: [] },
  })
  vi.spyOn(adminBillingApi, 'modules').mockResolvedValue({ data: MODS, meta: { periods: [] } })
  return vi.spyOn(adminBillingApi, 'updateTenant').mockResolvedValue(BILLING)
}

const box = (w: ReturnType<typeof mount>, name: string) => w.find(`input[type="checkbox"][aria-label="${name}"]`)

describe('Mensalidade por módulos', () => {
  it('marcar Mesas inclui o PDV; o total soma os módulos no ciclo escolhido', async () => {
    const update = setup()
    const w = mount(TenantBilling, { props: { tenantId: 't1', superadmin: true } })
    await flushPromises()
    expect(w.text()).toContain('R$ 49,90')

    await box(w, 'Mesas e comandas').setValue(true)
    expect((box(w, 'PDV e caixa').element as HTMLInputElement).checked).toBe(true)
    expect(w.text()).toContain('R$ 169,70') // 49,90 + 79,90 + 39,90

    // Anual: mesas sem preço anual entra sem custo e a tela avisa.
    await w.find('select').setValue('12')
    expect(w.text()).toContain('R$ 1.298,00')
    expect(w.text()).toContain('Sem preço neste período (entra sem custo): Mesas e comandas')

    // Tirar o PDV tira as mesas.
    await box(w, 'PDV e caixa').setValue(false)
    expect((box(w, 'Mesas e comandas').element as HTMLInputElement).checked).toBe(false)

    await w.find('form').trigger('submit')
    await flushPromises()
    expect(update).toHaveBeenCalledWith('t1', expect.objectContaining({ billing_months: 12, modules: ['menu'] }))
  })
})
