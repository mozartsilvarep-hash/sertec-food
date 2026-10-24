export interface BrandColors {
  50?: string
  100?: string
  500?: string
  600: string
  700?: string
  contrast?: string
}

/**
 * Aplica as cores do estabelecimento como variáveis CSS. Validação de
 * contraste AA acontece no servidor ao salvar a marca (Sprint 1).
 */
export function applyBrand(colors: BrandColors, root: HTMLElement = document.documentElement): void {
  for (const [shade, value] of Object.entries(colors)) {
    if (value && /^#[0-9a-f]{6}$/i.test(value)) {
      root.style.setProperty(`--brand-${shade}`, value)
    }
  }
}

function mix(hex: string, target: number, amount: number): string {
  const n = parseInt(hex.slice(1), 16)
  const channels = [(n >> 16) & 255, (n >> 8) & 255, n & 255].map((c) => Math.round(c + (target - c) * amount))
  return `#${channels.map((c) => c.toString(16).padStart(2, '0')).join('')}`
}

/**
 * Gera a escala da marca a partir de uma cor base (já validada AA no servidor):
 * 600 = cor do restaurante, 700 mais escura (hover), 50/100 tons claros.
 */
export function deriveBrand(hex: string): BrandColors {
  return {
    50: mix(hex, 255, 0.92),
    100: mix(hex, 255, 0.82),
    500: mix(hex, 255, 0.12),
    600: hex,
    700: mix(hex, 0, 0.22),
    contrast: '#ffffff',
  }
}
