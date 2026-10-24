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
