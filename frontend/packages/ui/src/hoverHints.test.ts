import { afterEach, describe, expect, it } from 'vitest'
import { refreshHint } from './hoverHints'

/** jsdom não calcula layout: simulamos o tamanho do texto e da caixa. */
function box(el: HTMLElement, sizes: { sw?: number; cw?: number; sh?: number; ch?: number }) {
  Object.defineProperty(el, 'scrollWidth', { configurable: true, get: () => sizes.sw ?? 100 })
  Object.defineProperty(el, 'clientWidth', { configurable: true, get: () => sizes.cw ?? 100 })
  Object.defineProperty(el, 'scrollHeight', { configurable: true, get: () => sizes.sh ?? 20 })
  Object.defineProperty(el, 'clientHeight', { configurable: true, get: () => sizes.ch ?? 20 })
}

afterEach(() => { document.body.innerHTML = '' })

describe('dicas ao passar o mouse', () => {
  it('texto com reticências cortado mostra o texto completo; se couber, a dica some', () => {
    document.body.innerHTML = '<p style="text-overflow: ellipsis; overflow: hidden; white-space: nowrap"><span>Bolo de brigadeiro com morangos frescos</span></p>'
    const p = document.querySelector('p')!
    const sizes = { sw: 300, cw: 120 }
    box(p, sizes)
    refreshHint(p.querySelector('span'))
    expect(p.title).toBe('Bolo de brigadeiro com morangos frescos')

    sizes.sw = 100
    refreshHint(p)
    expect(p.hasAttribute('title')).toBe(false)
  })

  it('line-clamp cortado também ganha a dica', () => {
    document.body.innerHTML = '<p style="-webkit-line-clamp: 2; overflow: hidden">Massa de baunilha, recheio de brigadeiro e cobertura de ganache.</p>'
    const p = document.querySelector('p')!
    box(p, { sh: 90, ch: 40 })
    refreshHint(p)
    expect(p.title).toContain('cobertura de ganache')
  })

  it('não mexe em listas com rolagem nem em title escrito à mão', () => {
    document.body.innerHTML = '<div style="overflow: auto"><p id="a">texto</p></div><p id="b" title="Manual" style="text-overflow: ellipsis">Longo</p>'
    const list = document.querySelector('div')!
    box(list, { sh: 900, ch: 200 })
    refreshHint(document.getElementById('a'))
    expect(list.hasAttribute('title')).toBe(false)

    const manual = document.getElementById('b')!
    box(manual, { sw: 500, cw: 50 })
    refreshHint(manual)
    expect(manual.title).toBe('Manual')
  })

  it('botão só com ícone mostra o aria-label; botão com texto igual não repete', () => {
    document.body.innerHTML = '<button aria-label="Remover item"><svg></svg></button><button aria-label="Salvar">Salvar</button>'
    const [icon, text] = Array.from(document.querySelectorAll('button'))
    refreshHint(icon!.querySelector('svg'))
    expect(icon!.title).toBe('Remover item')
    refreshHint(text!)
    expect(text!.hasAttribute('title')).toBe(false)
  })
})
