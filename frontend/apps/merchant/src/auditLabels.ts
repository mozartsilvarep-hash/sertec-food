/** Rótulos em português das ações auditadas (nome da rota ou ação explícita). */
const LABELS: Record<string, string> = {
  'auth.merchant.login': 'Entrou no painel',
  'auth.merchant.login_failed': 'Tentativa de login recusada',
  'auth.merchant.logout': 'Saiu do painel',
  'order.status_changed': 'Mudou o status de um pedido',
  'payment.approved': 'Pagamento online confirmado',
  'payment.refunded': 'Reembolso',
  'payment.mismatch': 'Pagamento com divergência',
  'payment.duplicate_approval': 'Pagamento em duplicidade',
  'payment.reference_mismatch': 'Pagamento de outra referência',
  'payments.connected': 'Conectou o Mercado Pago',
  'payments.disconnected': 'Desconectou o Mercado Pago',
  'tenant.settings_updated': 'Alterou a marca/descrição',
  'reports.orders_exported': 'Exportou pedidos (CSV)',
  'products.store': 'Criou produto',
  'products.update': 'Alterou produto',
  'products.destroy': 'Arquivou produto',
  'products.image.store': 'Trocou foto de produto',
  'products.image.destroy': 'Removeu foto de produto',
  'categories.store': 'Criou categoria',
  'categories.update': 'Alterou categoria',
  'categories.destroy': 'Removeu categoria',
  'menus.store': 'Criou cardápio',
  'menus.update': 'Alterou cardápio',
  'menus.destroy': 'Removeu cardápio',
  'option-groups.store': 'Criou grupo de opções',
  'option-groups.update': 'Alterou grupo de opções',
  'option-groups.destroy': 'Removeu grupo de opções',
  'availability.update': 'Marcou disponibilidade (esgotado)',
  'branches.hours.update': 'Alterou horários',
  'delivery.update': 'Alterou entrega/retirada',
}

export function auditLabel(action: string): string {
  return LABELS[action] ?? action
}

/** Resumo legível dos dados extras (nomes e status), sem IDs técnicos. */
export function auditDetail(metadata: Record<string, unknown> | null): string {
  if (!metadata) return ''
  const parts: string[] = []
  for (const [key, value] of Object.entries(metadata)) {
    if (typeof value !== 'string' && typeof value !== 'number' && typeof value !== 'boolean') continue
    if (key.endsWith('_name')) parts.push(String(value))
    else if (key === 'from' || key === 'to' || key === 'amount' || key === 'status' || key === 'rows' || key === 'mode' || key === 'reason') parts.push(`${key}: ${value}`)
  }
  return parts.join(' · ')
}
