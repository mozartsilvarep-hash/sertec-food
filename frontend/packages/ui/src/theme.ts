/**
 * Tema da loja: a "pele" do cardápio. Cores de fundo/texto, fontes e cantos
 * viram variáveis CSS no <html> (--theme-*), lidas por tokens.css. Sem CSS
 * por cliente: só valores fechados, validados no servidor (StoreTheme.php).
 *
 * As chaves de fonte precisam bater com backend/app/Modules/Tenancy/Support/StoreTheme.php.
 * Todas as fontes são OFL e auto-hospedadas (Fontsource): nenhuma requisição a terceiros,
 * e cada uma só é baixada quando a loja usa.
 */
import { applyBrand, deriveBrand } from './brand'

export type FontRole = 'display' | 'body' | 'script'

export interface FontOption {
  key: string
  label: string
  /** Nome da família registrada pelo @font-face do Fontsource. */
  family: string
  /** Fallback genérico. */
  generic: 'serif' | 'sans-serif' | 'cursive'
  roles: FontRole[]
  /** Só um peso (400): negrito "falso" fica desligado nos títulos. */
  singleWeight?: boolean
  load: () => Promise<unknown>
}

export const FONTS: FontOption[] = [
  { key: 'bricolage-grotesque', label: 'Bricolage Grotesque (padrão)', family: 'Bricolage Grotesque Variable', generic: 'sans-serif', roles: ['display'], load: async () => undefined },
  { key: 'gilda-display', label: 'Gilda Display', family: 'Gilda Display', generic: 'serif', roles: ['display'], singleWeight: true, load: () => import('@fontsource/gilda-display/400.css') },
  { key: 'playfair-display', label: 'Playfair Display', family: 'Playfair Display Variable', generic: 'serif', roles: ['display'], load: () => import('@fontsource-variable/playfair-display/index.css') },
  { key: 'fraunces', label: 'Fraunces', family: 'Fraunces Variable', generic: 'serif', roles: ['display'], load: () => import('@fontsource-variable/fraunces/index.css') },
  { key: 'dm-serif-display', label: 'DM Serif Display', family: 'DM Serif Display', generic: 'serif', roles: ['display'], singleWeight: true, load: () => import('@fontsource/dm-serif-display/400.css') },
  { key: 'cormorant-garamond', label: 'Cormorant Garamond', family: 'Cormorant Garamond Variable', generic: 'serif', roles: ['display'], load: () => import('@fontsource-variable/cormorant-garamond/index.css') },
  { key: 'poppins', label: 'Poppins', family: 'Poppins', generic: 'sans-serif', roles: ['display', 'body'], load: () => Promise.all([import('@fontsource/poppins/400.css'), import('@fontsource/poppins/500.css'), import('@fontsource/poppins/600.css'), import('@fontsource/poppins/700.css'), import('@fontsource/poppins/800.css')]) },
  { key: 'montserrat', label: 'Montserrat', family: 'Montserrat Variable', generic: 'sans-serif', roles: ['display', 'body'], load: () => import('@fontsource-variable/montserrat/index.css') },
  { key: 'oswald', label: 'Oswald', family: 'Oswald Variable', generic: 'sans-serif', roles: ['display'], load: () => import('@fontsource-variable/oswald/index.css') },
  { key: 'bebas-neue', label: 'Bebas Neue', family: 'Bebas Neue', generic: 'sans-serif', roles: ['display'], singleWeight: true, load: () => import('@fontsource/bebas-neue/400.css') },
  { key: 'baloo-2', label: 'Baloo 2', family: 'Baloo 2 Variable', generic: 'sans-serif', roles: ['display'], load: () => import('@fontsource-variable/baloo-2/index.css') },
  { key: 'lobster', label: 'Lobster', family: 'Lobster', generic: 'cursive', roles: ['display'], singleWeight: true, load: () => import('@fontsource/lobster/400.css') },
  { key: 'system', label: 'Do sistema (padrão)', family: '', generic: 'sans-serif', roles: ['body'], load: async () => undefined },
  { key: 'jost', label: 'Jost', family: 'Jost Variable', generic: 'sans-serif', roles: ['body'], load: () => import('@fontsource-variable/jost/index.css') },
  { key: 'inter', label: 'Inter', family: 'Inter Variable', generic: 'sans-serif', roles: ['body'], load: () => import('@fontsource-variable/inter/index.css') },
  { key: 'nunito', label: 'Nunito', family: 'Nunito Variable', generic: 'sans-serif', roles: ['body'], load: () => import('@fontsource-variable/nunito/index.css') },
  { key: 'lato', label: 'Lato', family: 'Lato', generic: 'sans-serif', roles: ['body'], load: () => Promise.all([import('@fontsource/lato/400.css'), import('@fontsource/lato/700.css'), import('@fontsource/lato/900.css')]) },
  { key: 'dm-sans', label: 'DM Sans', family: 'DM Sans Variable', generic: 'sans-serif', roles: ['body'], load: () => import('@fontsource-variable/dm-sans/index.css') },
  { key: 'great-vibes', label: 'Great Vibes', family: 'Great Vibes', generic: 'cursive', roles: ['script'], singleWeight: true, load: () => import('@fontsource/great-vibes/400.css') },
  { key: 'dancing-script', label: 'Dancing Script', family: 'Dancing Script Variable', generic: 'cursive', roles: ['script'], load: () => import('@fontsource-variable/dancing-script/index.css') },
  { key: 'pacifico', label: 'Pacifico', family: 'Pacifico', generic: 'cursive', roles: ['script'], singleWeight: true, load: () => import('@fontsource/pacifico/400.css') },
  { key: 'sacramento', label: 'Sacramento', family: 'Sacramento', generic: 'cursive', roles: ['script'], singleWeight: true, load: () => import('@fontsource/sacramento/400.css') },
  { key: 'parisienne', label: 'Parisienne', family: 'Parisienne', generic: 'cursive', roles: ['script'], singleWeight: true, load: () => import('@fontsource/parisienne/400.css') },
]

export const fontsFor = (role: FontRole) => FONTS.filter((f) => f.roles.includes(role))
const fontByKey = (key: string | null | undefined) => FONTS.find((f) => f.key === key)

export type ThemeColorKey = 'canvas' | 'surface' | 'ink' | 'muted' | 'line' | 'accent'
export type ThemeRadius = 'square' | 'soft' | 'round'

export interface ThemeInput {
  colors?: Partial<Record<ThemeColorKey, string | null>>
  fonts?: { display?: string | null; body?: string | null; script?: string | null }
  radius?: ThemeRadius | string | null
}

/** Mesmos padrões de tokens.css e de StoreTheme::DEFAULT_COLORS. */
export const DEFAULT_THEME_COLORS: Record<ThemeColorKey, string | null> = {
  canvas: '#fafaf9', surface: '#ffffff', ink: '#1c1917', muted: '#57534e', line: '#e7e5e4', accent: null,
}

export const RADII: Record<ThemeRadius, { label: string; card: string; control: string }> = {
  square: { label: 'Retos', card: '0.25rem', control: '0.25rem' },
  soft: { label: 'Suaves (padrão)', card: '0.875rem', control: '0.625rem' },
  round: { label: 'Redondos', card: '1.5rem', control: '9999px' },
}

const HEX = /^#[0-9a-f]{6}$/i

/** Pilha CSS de uma fonte (com fallback), ou null para a padrão do app. */
export function fontStack(key: string | null | undefined): string | null {
  const f = fontByKey(key)
  if (!f || !f.family) return null
  return `"${f.family}", ${f.generic === 'sans-serif' ? 'ui-sans-serif, system-ui, sans-serif' : f.generic}`
}

/** Baixa as fontes do tema (só as usadas). Falha de rede não quebra a página. */
export async function loadThemeFonts(theme: ThemeInput): Promise<void> {
  const keys = [theme.fonts?.display, theme.fonts?.body, theme.fonts?.script]
  await Promise.all(keys.map((k) => fontByKey(k)?.load().catch(() => undefined)))
}

/**
 * Aplica o tema (e a cor principal) como variáveis CSS. Campos vazios voltam
 * ao padrão. Também baixa as fontes escolhidas.
 */
export function applyTheme(theme: ThemeInput | null | undefined, brandColor?: string | null, root: HTMLElement = document.documentElement): Promise<void> {
  if (brandColor && HEX.test(brandColor)) applyBrand(deriveBrand(brandColor), root)
  const t = theme ?? {}

  for (const key of Object.keys(DEFAULT_THEME_COLORS) as ThemeColorKey[]) {
    const value = t.colors?.[key]
    if (value && HEX.test(value)) root.style.setProperty(`--theme-${key}`, value)
    else root.style.removeProperty(`--theme-${key}`)
  }

  const fonts: [string, string | null][] = [
    ['--theme-font-display', fontStack(t.fonts?.display)],
    ['--theme-font-body', fontStack(t.fonts?.body)],
    ['--theme-font-script', fontStack(t.fonts?.script)],
  ]
  for (const [prop, stack] of fonts) {
    if (stack) root.style.setProperty(prop, stack)
    else root.style.removeProperty(prop)
  }
  // Fonte de título com um peso só: sem negrito "falso" (borrado).
  if (fontByKey(t.fonts?.display)?.singleWeight) root.dataset.displaySingle = ''
  else delete root.dataset.displaySingle

  const radius = RADII[(t.radius ?? 'soft') as ThemeRadius] ?? RADII.soft
  root.style.setProperty('--theme-radius-card', radius.card)
  root.style.setProperty('--theme-radius-control', radius.control)

  return loadThemeFonts(t)
}

/** Contraste WCAG entre duas cores (1 a 21), igual ao do servidor. */
export function contrastRatio(a: string, b: string): number {
  const lum = (hex: string) => {
    const n = parseInt(hex.slice(1), 16)
    const [r, g, bl] = [(n >> 16) & 255, (n >> 8) & 255, n & 255]
      .map((c) => c / 255)
      .map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4))
    return 0.2126 * r! + 0.7152 * g! + 0.0722 * bl!
  }
  const [x, y] = [lum(a), lum(b)]
  return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)
}

/** Mesmas regras de StoreTheme::contrastErrors: texto legível (AA) sobre fundo e cartões. */
export function themeContrastIssues(colors: Partial<Record<ThemeColorKey, string | null>>): Partial<Record<ThemeColorKey, string>> {
  const c = { ...DEFAULT_THEME_COLORS, ...Object.fromEntries(Object.entries(colors).filter(([, v]) => v && HEX.test(v))) } as Record<ThemeColorKey, string>
  const names: Record<ThemeColorKey, string> = { canvas: 'fundo', surface: 'cartões', ink: 'texto', muted: 'texto suave', line: 'linhas', accent: 'destaque' }
  const issues: Partial<Record<ThemeColorKey, string>> = {}
  for (const [text, bg] of [['ink', 'canvas'], ['ink', 'surface'], ['muted', 'canvas'], ['muted', 'surface']] as [ThemeColorKey, ThemeColorKey][]) {
    const ratio = contrastRatio(c[text], c[bg])
    if (ratio < 4.5 && !issues[text]) issues[text] = `Pouco legível sobre ${names[bg]} (${ratio.toFixed(1).replace('.', ',')}:1; mínimo 4,5:1)`
  }
  if (!issues.ink && contrastRatio(c.ink, '#ffffff') < 4.5) issues.ink = 'Use um tom escuro (avisos usam texto branco sobre ele)'
  return issues
}
