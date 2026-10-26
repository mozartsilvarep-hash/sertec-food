import { formatMoney, type TrackedOrder } from '@sertec/api-client'

/** Só dígitos com DDD (10 ou 11). wa.me exige o código do país. */
export function phoneDigits(phone: string | null | undefined): string {
  const d = (phone ?? '').replace(/\D/g, '')
  const local = d.length >= 12 && d.startsWith('55') ? d.slice(2) : d
  return local.length === 10 || local.length === 11 ? local : ''
}

export function whatsappLink(phone: string | null | undefined, text: string): string | null {
  const d = phoneDigits(phone)
  return d ? `https://wa.me/55${d}?text=${encodeURIComponent(text)}` : null
}

/**
 * Mensagem para avisar a loja do pedido recém-feito. Sem rua nem número:
 * o restaurante vê o endereço completo no painel.
 */
export function orderMessage(order: TrackedOrder): string {
  const total = order.totals.find((t) => t.code === 'total')?.amount
  const lines = [
    `Olá! Acabei de fazer o pedido nº ${order.number} pelo cardápio digital.`,
    '',
    ...order.items.map((i) => `• ${i.quantity}× ${i.name}${i.options.length ? ` (${i.options.map((o) => o.name).join(', ')})` : ''}`),
    '',
    total ? `Total: ${formatMoney(total)}` : null,
    `Pagamento: ${order.payment_method_label}`,
    order.fulfillment_type === 'delivery' ? `Entrega no bairro ${order.neighborhood ?? ''}`.trim() : `Retirada em ${order.branch.name}`,
    order.customer_first_name ? `Nome: ${order.customer_first_name}` : null,
  ]
  return lines.filter((l): l is string => l !== null).join('\n')
}

export function contactMessage(order: TrackedOrder): string {
  return `Olá! Sobre o pedido nº ${order.number}.`
}
