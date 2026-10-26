import { createRouter, createWebHistory } from 'vue-router'
import { lastOrder, loadStore, store } from './shop'

export const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior: (to, _from, saved) => saved ?? (to.hash ? { el: to.hash, top: 64 } : { top: 0 }),
  routes: [
    { path: '/', name: 'menu', component: () => import('./pages/MenuPage.vue') },
    { path: '/carrinho', name: 'cart', component: () => import('./pages/CartPage.vue') },
    { path: '/finalizar', name: 'checkout', component: () => import('./pages/CheckoutPage.vue') },
    // Retorno do Checkout Pro: a URL não carrega o token; o pedido está neste aparelho.
    // O status que o Mercado Pago põe na URL é ignorado: quem decide é o servidor.
    { path: '/pedido/retorno', name: 'payment-return', component: { render: () => null }, beforeEnter: async () => {
      if (!store.value) await loadStore().catch(() => null)
      const token = lastOrder()
      return token ? { path: `/pedido/${token}`, replace: true } : { path: '/', replace: true }
    } },
    { path: '/pedido/:token', name: 'track', component: () => import('./pages/TrackPage.vue'), props: true },
    { path: '/privacidade', name: 'privacy', component: () => import('./pages/PrivacyPage.vue') },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})
