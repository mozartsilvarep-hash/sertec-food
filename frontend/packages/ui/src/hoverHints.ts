/**
 * Dicas ao passar o mouse, em todos os apps, sem marcar elemento por elemento:
 *
 * 1. Texto cortado (reticências do `truncate` ou `line-clamp-*`): mostra o
 *    texto completo. Só vale quando o texto está de fato cortado naquele
 *    tamanho de tela; se a tela crescer e ele couber, a dica some.
 * 2. Botões e links só com ícone: mostra o rótulo acessível (aria-label).
 *
 * Usa o atributo `title` (nativo, sem JS de tooltip, acessível). Um `title`
 * escrito à mão nunca é trocado.
 */
const AUTO = 'autoTitle'

function isCut(el: HTMLElement): boolean {
  const s = getComputedStyle(el)
  const clamp = s.getPropertyValue('-webkit-line-clamp')
  if (clamp && clamp !== 'none') return el.scrollHeight > el.clientHeight + 1
  if (s.textOverflow === 'ellipsis') return el.scrollWidth > el.clientWidth + 1
  return false
}

function setAuto(el: HTMLElement, text: string | null) {
  if (text) {
    el.title = text
    el.dataset[AUTO] = ''
  } else {
    el.removeAttribute('title')
    delete el.dataset[AUTO]
  }
}

/** Atualiza as dicas a partir do elemento sob o mouse (exportado para teste). */
export function refreshHint(target: EventTarget | null): void {
  if (!(target instanceof Element)) return

  // Texto cortado: o próprio elemento ou um dos 3 ancestrais mais próximos.
  let el: Element | null = target
  for (let depth = 0; el && depth < 4; depth++, el = el.parentElement) {
    if (!(el instanceof HTMLElement)) continue
    const manual = el.hasAttribute('title') && !(AUTO in el.dataset)
    if (manual) break
    if (isCut(el)) {
      setAuto(el, (el.innerText || el.textContent || '').replace(/\s+/g, ' ').trim() || null)
      return
    }
    if (AUTO in el.dataset) setAuto(el, null)
  }

  // Botão/link só com ícone: o rótulo acessível vira a dica.
  const control = target.closest<HTMLElement>('button, a, [role="button"], [role="tab"], [role="switch"]')
  if (control && (!control.hasAttribute('title') || AUTO in control.dataset)) {
    const label = control.getAttribute('aria-label')?.trim()
    const visible = (control.textContent || '').replace(/\s+/g, ' ').trim()
    setAuto(control, label && label !== visible ? label : null)
  }
}

let installed = false

/** Liga as dicas para a página toda (chamar uma vez no main.ts de cada app). */
export function enableHoverHints(doc: Document = document): void {
  if (installed) return
  installed = true
  const onHover = (e: Event) => refreshHint(e.target)
  doc.addEventListener('mouseover', onHover, { passive: true })
  // Teclado: a mesma dica aparece para leitores que usam o foco.
  doc.addEventListener('focusin', onHover, { passive: true })
}
