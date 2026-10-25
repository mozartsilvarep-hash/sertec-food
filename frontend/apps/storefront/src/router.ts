import { createRouter, createWebHistory } from 'vue-router'

export const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior: (to, _from, saved) => saved ?? (to.hash ? { el: to.hash, top: 64 } : { top: 0 }),
  routes: [
    { path: '/', name: 'menu', component: () => import('./pages/MenuPage.vue') },
    { path: '/carrinho', name: 'cart', component: () => import('./pages/CartPage.vue') },
    { path: '/finalizar', name: 'checkout', component: () => import('./pages/CheckoutPage.vue') },
    { path: '/pedido/:token', name: 'track', component: () => import('./pages/TrackPage.vue'), props: true },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})
