/**
 * Bairros de Aracaju (SE) para sugestão nas zonas de entrega. Só ajuda a
 * digitar: o restaurante pode escrever qualquer bairro.
 */
export const ARACAJU_NEIGHBORHOODS = [
  '13 de Julho', '17 de Março', '18 do Forte', 'Aeroporto', 'América', 'Areia Branca', 'Atalaia', 'Bugio',
  'Capucho', 'Centro', 'Cidade Nova', 'Cirurgia', 'Coroa do Meio', 'Farolândia', 'Getúlio Vargas', 'Grageru',
  'Inácio Barbosa', 'Industrial', 'Jabotiana', 'Japãozinho', 'Jardim Centenário', 'Jardins', 'José Conrado de Araújo',
  'Lamarão', 'Luzia', 'Mosqueiro', 'Novo Paraíso', 'Olaria', 'Palestina', 'Pereira Lobo', 'Ponto Novo',
  'Porto Dantas', 'Robalo', 'Salgado Filho', 'Santa Maria', 'Santo Antônio', 'Santos Dumont', 'São Conrado',
  'São José', 'Siqueira Campos', 'Soledade', 'Suíssa',
] as const

/** Acrescenta o bairro à lista "a, b, c" se ainda não estiver lá (sem diferenciar acentos/maiúsculas). */
export function addNeighborhood(list: string, name: string): string {
  const norm = (s: string) => s.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase().trim()
  const items = list.split(/[,\n]/).map((n) => n.trim()).filter(Boolean)
  if (items.some((n) => norm(n) === norm(name))) return items.join(', ')
  return [...items, name].join(', ')
}

/** Zona criada e deixada em branco: é descartada ao salvar em vez de travar o formulário. */
export function isBlankZone(z: { name: string; neighborhoods: string; fee: string; minimum_order: string; free_above: string }): boolean {
  return [z.name, z.neighborhoods, z.fee, z.minimum_order, z.free_above].every((v) => v.trim() === '')
}
