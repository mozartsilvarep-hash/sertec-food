import { createRouter, createWebHistory } from 'vue-router'
import PanelLayout from './layouts/PanelLayout.vue'
import { loadSession, me } from './session'

export const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/login', name: 'login', component: () => import('./pages/LoginPage.vue'), meta: { guest: true } },
    // Comanda: fora do layout (sem menu na impressão).
    { path: '/pedidos/:id/imprimir', name: 'order-print', component: () => import('./pages/PrintPage.vue'), props: true },
    {
      path: '/',
      component: PanelLayout,
      children: [
        { path: '', name: 'home', component: () => import('./pages/HomePage.vue') },
        { path: 'pedidos', name: 'orders', component: () => import('./pages/OrdersPage.vue') },
        { path: 'historico', name: 'history', component: () => import('./pages/HistoryPage.vue') },
        { path: 'auditoria', name: 'audit', component: () => import('./pages/AuditPage.vue') },
        { path: 'equipe', name: 'team', component: () => import('./pages/TeamPage.vue') },
        { path: 'minha-conta', name: 'account', component: () => import('./pages/AccountPage.vue') },
        { path: 'entrega', name: 'delivery', component: () => import('./pages/DeliveryPage.vue') },
        { path: 'cardapio', name: 'catalog', component: () => import('./pages/catalog/CatalogPage.vue') },
        { path: 'cardapio/produtos/novo', name: 'product-new', component: () => import('./pages/catalog/ProductEditPage.vue') },
        { path: 'cardapio/produtos/:id', name: 'product-edit', component: () => import('./pages/catalog/ProductEditPage.vue'), props: true },
        { path: 'opcoes', name: 'option-groups', component: () => import('./pages/catalog/OptionGroupsPage.vue') },
        { path: 'opcoes/novo', name: 'option-group-new', component: () => import('./pages/catalog/OptionGroupEditPage.vue') },
        { path: 'opcoes/:id', name: 'option-group-edit', component: () => import('./pages/catalog/OptionGroupEditPage.vue'), props: true },
        { path: 'esgotados', name: 'availability', component: () => import('./pages/catalog/AvailabilityPage.vue') },
        { path: 'horarios', name: 'hours', component: () => import('./pages/HoursPage.vue') },
        { path: 'marca', name: 'brand', component: () => import('./pages/BrandPage.vue') },
        { path: 'pagamentos', name: 'payments', component: () => import('./pages/PaymentsPage.vue') },
      ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  if (me.value === null && !to.meta.guest) {
    await loadSession().catch(() => null)
  }
  if (!to.meta.guest && me.value === null) return { name: 'login' }
  if (to.meta.guest && me.value !== null) return { name: 'home' }
  return true
})
