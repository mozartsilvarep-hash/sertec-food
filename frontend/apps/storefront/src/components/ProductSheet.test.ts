import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'
import { publicCatalogApi, type PublicProduct } from '@sertec/api-client'
import ProductSheet from './ProductSheet.vue'

beforeAll(() => {
  // jsdom não implementa <dialog>.showModal
  HTMLDialogElement.prototype.showModal ??= function (this: HTMLDialogElement) { this.setAttribute('open', '') }
  HTMLDialogElement.prototype.close ??= function (this: HTMLDialogElement) { this.removeAttribute('open') }
})
afterEach(() => vi.restoreAllMocks())

const pizza: PublicProduct = {
  id: 'P', name: 'Pizza tradicional', description: null, image_url: null, available: true, has_options: true, price_from: '39.90',
  variants: [{ id: 'M', name: 'Média', price: '0.00' }, { id: 'G', name: 'Grande', price: '0.00' }],
  option_groups: [{
    id: 'S', name: 'Sabores', type: 'fraction', min_select: 1, max_select: 2, required: true, free_quantity: 0, pricing_rule: 'highest',
    options: [
      { id: 'cal', name: 'Calabresa', description: null, price_delta: '0.00', max_quantity: 1, prices: { M: '39.90', G: '52.90' } },
      { id: 'mar', name: 'Margherita', description: null, price_delta: '0.00', max_quantity: 1, prices: { M: '42.90', G: '55.90' } },
      { id: 'cam', name: 'Camarão', description: null, price_delta: '0.00', max_quantity: 1, prices: { G: '79.90' } },
    ],
  }],
}

async function open() {
  vi.spyOn(publicCatalogApi, 'product').mockResolvedValue(pizza)
  const wrapper = mount(ProductSheet, { props: { productId: 'P', branchId: 'B' }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

const label = (w: Awaited<ReturnType<typeof open>>, text: string) => w.findAll('label').find((l) => l.text().includes(text))!

describe('ProductSheet', () => {
  it('meio a meio mostra o preço do sabor mais caro e marca o grupo como pronto', async () => {
    const w = await open()
    expect(w.text()).toContain('Obrigatório')

    await label(w, 'Calabresa').find('input').trigger('change')
    await label(w, 'Margherita').find('input').trigger('change')

    expect(w.text()).toContain('R$ 42,90')
    expect(w.text()).toContain('Pronto')
  })

  it('sabor indisponível no tamanho fica desabilitado e é desmarcado ao trocar de tamanho', async () => {
    const w = await open()
    expect(label(w, 'Camarão').find('input').attributes('disabled')).toBeDefined()

    await label(w, 'Grande').find('input').setValue(true)
    await label(w, 'Camarão').find('input').trigger('change')
    expect(w.text()).toContain('R$ 79,90')

    await label(w, 'Média').find('input').setValue(true)
    await flushPromises()
    expect((label(w, 'Camarão').find('input').element as HTMLInputElement).checked).toBe(false)
  })

  it('não deixa escolher mais sabores que o máximo', async () => {
    const w = await open()
    await label(w, 'Grande').find('input').setValue(true)
    await label(w, 'Calabresa').find('input').trigger('change')
    await label(w, 'Margherita').find('input').trigger('change')

    expect(label(w, 'Camarão').find('input').attributes('disabled')).toBeDefined()
  })
})
