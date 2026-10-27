import { computed, ref } from 'vue'
import {
  ApiError, cartApi, publicCatalogApi, type AddToCart, type BranchStatus, type CartView, type StorefrontInfo,
} from '@sertec/api-client'
import { applyBrand, deriveBrand } from '@sertec/ui'
import { readPref, removePref, writePref } from './storage'

/** Estado compartilhado do cardápio: loja, unidade escolhida e carrinho. */
export const store = ref<StorefrontInfo | null>(null)
export const branchId = ref<string | null>(null)
export const cart = ref<CartView | null>(null)
export const toast = ref<string | null>(null)

export const branch = computed<BranchStatus | null>(() => store.value?.branches.find((b) => b.id === branchId.value) ?? null)

let toastTimer: ReturnType<typeof setTimeout> | undefined
export function showToast(text: string) {
  toast.value = text
  clearTimeout(toastTimer)
  toastTimer = setTimeout(() => (toast.value = null), 2800)
}

const key = (name: string) => `${name}:${store.value?.slug ?? 'x'}`

export async function loadStore(): Promise<void> {
  store.value = await publicCatalogApi.storefront()
  document.title = `${store.value.name} · Cardápio`
  if (store.value.brand_color) applyBrand(deriveBrand(store.value.brand_color))
  const saved = readPref(key('branch'))
  branchId.value = store.value.branches.some((b) => b.id === saved) ? saved : (store.value.branches[0]?.id ?? null)
  // O cardápio já começa a carregar junto com o carrinho (uma ida a menos à rede).
  const branch = branchId.value
  const promise = publicCatalogApi.menus(branch ?? undefined)
  promise.catch(() => undefined)
  menusPrefetch = { branch, promise }
  await loadCart()
}

type MenusResponse = Awaited<ReturnType<typeof publicCatalogApi.menus>>
let menusPrefetch: { branch: string | null; promise: Promise<MenusResponse> } | null = null

/** Usa (uma vez) o cardápio pedido durante a abertura do site; senão, busca. */
export function fetchMenus(branch: string | null): Promise<MenusResponse> {
  const pre = menusPrefetch
  menusPrefetch = null
  return pre && pre.branch === branch ? pre.promise : publicCatalogApi.menus(branch ?? undefined)
}

export function selectBranch(id: string) {
  branchId.value = id
  writePref(key('branch'), id)
  // Carrinho é por unidade: trocar de unidade começa outro carrinho.
  if (cart.value && cart.value.branch_id !== id) forgetCart()
}

export async function loadCart(): Promise<void> {
  const token = readPref(key('cart'))
  if (!token) return
  try {
    const view = await cartApi.get(token)
    cart.value = view.branch_id === branchId.value ? { ...view, token } : null
    if (!cart.value) removePref(key('cart'))
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) forgetCart()
    else throw error
  }
}

export function forgetCart() {
  cart.value = null
  removePref(key('cart'))
}

async function ensureCart(): Promise<string> {
  if (cart.value?.token) return cart.value.token
  const created = await cartApi.create(branchId.value)
  writePref(key('cart'), created.token)
  cart.value = created
  return created.token
}

export async function addToCart(item: AddToCart): Promise<void> {
  let token = await ensureCart()
  try {
    cart.value = { ...(await cartApi.add(token, item)), token }
  } catch (error) {
    if (!(error instanceof ApiError && error.code === 'cart_not_found')) throw error
    forgetCart() // expirou: cria outro e tenta de novo uma vez
    token = await ensureCart()
    cart.value = { ...(await cartApi.add(token, item)), token }
  }
}

export async function updateLine(itemId: string, quantity: number): Promise<void> {
  if (!cart.value?.token) return
  const token = cart.value.token
  cart.value = { ...(await cartApi.update(token, itemId, { quantity })), token }
}

export function rememberOrder(trackingToken: string) {
  writePref(key('last-order'), trackingToken)
  forgetCart()
}

export function lastOrder(): string | null {
  return readPref(key('last-order'))
}

/**
 * Dados do cliente guardados SÓ neste aparelho (localStorage), para
 * preencher o próximo pedido. O cliente pode apagar em "Esquecer meus dados".
 */
export interface SavedCustomer {
  name: string
  phone: string
  address: { street: string; number: string; complement: string; neighborhood: string; reference: string } | null
}

export function savedCustomer(): SavedCustomer | null {
  try {
    const raw = readPref(key('customer'))
    const parsed = raw ? (JSON.parse(raw) as SavedCustomer) : null
    return parsed && typeof parsed.phone === 'string' && typeof parsed.name === 'string' ? parsed : null
  } catch {
    return null
  }
}

export function rememberCustomer(customer: SavedCustomer): void {
  writePref(key('customer'), JSON.stringify(customer))
}

export function forgetCustomer(): void {
  removePref(key('customer'))
}
