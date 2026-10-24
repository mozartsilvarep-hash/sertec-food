import { catalogApi, type Product } from '@sertec/api-client'

/** Lê todas as páginas (cursor) do catálogo do painel. */
export async function loadAllProducts(search?: string): Promise<Product[]> {
  const all: Product[] = []
  let cursor: string | null = null
  do {
    const page = await catalogApi.products({ search, cursor, per_page: 100 })
    all.push(...page.data)
    cursor = page.meta.next_cursor
  } while (cursor)
  return all
}
