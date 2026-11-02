import { afterEach, describe, expect, it } from 'vitest'
import { applyTheme, FONTS, fontStack, themeContrastIssues } from './theme'

const root = () => document.documentElement

afterEach(() => {
  root().removeAttribute('style')
  delete root().dataset.displaySingle
})

/** Tema da Yris Cakes (yriscakes.com.br). */
const yris = {
  colors: { canvas: '#fff7ee', surface: '#ffffff', ink: '#4a2c23', muted: '#6b4c40', line: '#e9d9c9', accent: '#a9cdee' },
  fonts: { display: 'gilda-display', body: 'jost', script: 'great-vibes' },
  radius: 'round' as const,
}

describe('applyTheme', () => {
  it('aplica cores, fontes, cantos e a cor principal como variáveis', async () => {
    await applyTheme(yris, '#4a2c23')
    const s = root().style
    expect(s.getPropertyValue('--theme-canvas')).toBe('#fff7ee')
    expect(s.getPropertyValue('--theme-accent')).toBe('#a9cdee')
    expect(s.getPropertyValue('--brand-600')).toBe('#4a2c23')
    expect(s.getPropertyValue('--theme-font-display')).toBe('"Gilda Display", serif')
    expect(s.getPropertyValue('--theme-font-body')).toBe('"Jost Variable", ui-sans-serif, system-ui, sans-serif')
    expect(s.getPropertyValue('--theme-font-script')).toBe('"Great Vibes", cursive')
    expect(s.getPropertyValue('--theme-radius-control')).toBe('9999px')
    expect(root().dataset.displaySingle).toBe('')
  })

  it('campos vazios ou fora do formato voltam ao padrão', async () => {
    await applyTheme(yris, '#4a2c23')
    await applyTheme({ colors: { canvas: 'red;}body{display:none', ink: null }, fonts: { display: 'comic-sans' } }, null)
    const s = root().style
    expect(s.getPropertyValue('--theme-canvas')).toBe('')
    expect(s.getPropertyValue('--theme-ink')).toBe('')
    expect(s.getPropertyValue('--theme-font-display')).toBe('')
    expect(s.getPropertyValue('--theme-radius-card')).toBe('0.875rem')
    expect(root().dataset.displaySingle).toBeUndefined()
  })

  it('fonte do sistema e padrão não trocam a pilha', () => {
    expect(fontStack('system')).toBeNull()
    expect(fontStack('bricolage-grotesque')).toBe('"Bricolage Grotesque Variable", ui-sans-serif, system-ui, sans-serif')
    expect(new Set(FONTS.map((f) => f.key)).size).toBe(FONTS.length)
  })
})

describe('themeContrastIssues', () => {
  it('aceita o tema da Yris e recusa texto claro sobre creme', () => {
    expect(themeContrastIssues(yris.colors)).toEqual({})
    expect(themeContrastIssues({ canvas: '#fff7ee', ink: '#c9a28f' }).ink).toMatch(/fundo/)
    expect(themeContrastIssues({ muted: '#a8a29e' }).muted).toMatch(/mínimo 4,5:1/)
  })
})
