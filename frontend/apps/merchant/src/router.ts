import { createRouter, createWebHistory } from 'vue-router'
import PanelLayout from './layouts/PanelLayout.vue'
import { can, has, loadSession, me } from './session'

export const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/login', name: 'login', component: () => import('./pages/LoginPage.vue'), meta: { guest: true } },
    { path: '/esqueci-senha', name: 'forgot', component: () => import('./pages/ForgotPasswordPage.vue'), meta: { guest: true } },
    { path: '/redefinir-senha', name: 'reset', component: () => import('./pages/ResetPasswordPage.vue'), meta: { guest: true } },
    // Comanda: fora do layout (sem menu na impressão).
    { path: '/pedidos/:id/imprimir', name: 'order-print', component: () => import('./pages/PrintPage.vue'), props: true },
    { path: '/caixa/:id/fechamento', name: 'cash-print', component: () => import('./pages/CashPrintPage.vue'), props: true, meta: { feature: 'cashbook' } },
    { path: '/pedidos/:id/recibo', name: 'order-receipt', component: () => import('./pages/PrintPage.vue'), props: (r) => ({ id: String(r.params.id), kind: 'receipt' }) },
    { path: '/mesas/:id/cozinha', name: 'tab-kitchen', component: () => import('./pages/TabKitchenPrintPage.vue'), props: true, meta: { feature: 'tabs' } },
    // PDV em tela própria (sem o menu lateral), pensado para tela cheia.
    { path: '/pdv', name: 'pos', component: () => import('./pages/PosPage.vue'), meta: { feature: 'cashbook' } },
    {
      path: '/',
      component: PanelLayout,
      children: [
        { path: '', name: 'home', component: () => import('./pages/HomePage.vue') },
        { path: 'pedidos', name: 'orders', component: () => import('./pages/OrdersPage.vue') },
        { path: 'historico', name: 'history', component: () => import('./pages/HistoryPage.vue') },
        { path: 'caixa', name: 'cash', component: () => import('./pages/CashPage.vue'), meta: { feature: 'cashbook' } },
        { path: 'mesas', name: 'tabs', component: () => import('./pages/TabsPage.vue'), meta: { feature: 'tabs' } },
        { path: 'estoque', name: 'stock', component: () => import('./pages/catalog/StockPage.vue'), meta: { feature: 'stock' } },
        { path: 'financeiro', name: 'finance', component: () => import('./pages/FinancePage.vue'), meta: { feature: 'cashbook' } },
        { path: 'relatorios', name: 'reports', component: () => import('./pages/ReportsPage.vue') },
        { path: 'promocoes', name: 'marketing', component: () => import('./pages/MarketingPage.vue') },
        { path: 'auditoria', name: 'audit', component: () => import('./pages/AuditPage.vue') },
        { path: 'equipe', name: 'team', component: () => import('./pages/TeamPage.vue') },
        { path: 'minha-conta', name: 'account', component: () => import('./pages/AccountPage.vue') },
        { path: 'privacidade', name: 'privacy', component: () => import('./pages/PrivacyToolPage.vue') },
        { path: 'entrega', name: 'delivery', component: () => import('./pages/DeliveryPage.vue'), meta: { feature: 'online_ordering' } },
        { path: 'cardapio', name: 'catalog', component: () => import('./pages/catalog/CatalogPage.vue') },
        { path: 'cardapio/produtos/novo', name: 'product-new', component: () => import('./pages/catalog/ProductEditPage.vue') },
        { path: 'cardapio/produtos/:id', name: 'product-edit', component: () => import('./pages/catalog/ProductEditPage.vue'), props: true },
        { path: 'opcoes', name: 'option-groups', component: () => import('./pages/catalog/OptionGroupsPage.vue') },
        { path: 'opcoes/novo', name: 'option-group-new', component: () => import('./pages/catalog/OptionGroupEditPage.vue') },
        { path: 'opcoes/:id', name: 'option-group-edit', component: () => import('./pages/catalog/OptionGroupEditPage.vue'), props: true },
        { path: 'esgotados', name: 'availability', component: () => import('./pages/catalog/AvailabilityPage.vue') },
        { path: 'horarios', name: 'hours', component: () => import('./pages/HoursPage.vue'), meta: { feature: 'online_ordering' } },
        { path: 'marca', name: 'brand', component: () => import('./pages/BrandPage.vue') },
        { path: 'assinatura', name: 'billing', component: () => import('./pages/BillingPage.vue') },
        { path: 'pagamentos', name: 'payments', component: () => import('./pages/PaymentsPage.vue'), meta: { feature: 'online_ordering' } },
        { path: 'bloqueado', name: 'blocked', component: () => import('./pages/ModuleLockedPage.vue'), props: { modulo: 'blocked' } },
        { path: 'modulo/:modulo', name: 'module-locked', component: () => import('./pages/ModuleLockedPage.vue'), props: true },
        // Endereço desconhecido: página 404 dentro do painel (com o menu).
        { path: ':pathMatch(.*)*', name: 'not-found', component: () => import('./pages/NotFoundPage.vue') },
      ],
    },
  ],
})

router.beforeEach(async (to) => {
  if (me.value === null && !to.meta.guest) {
    await loadSession().catch(() => null)
  }
  if (!to.meta.guest && me.value === null) return { name: 'login' }
  if (to.meta.guest && me.value !== null) return { name: 'home' }
  // Bloqueado por atraso: só Assinatura e Minha conta abrem.
  if (me.value?.blocked && !['billing', 'account', 'blocked'].includes(String(to.name))) {
    return { name: can('billing.manage') ? 'billing' : 'blocked' }
  }
  // Módulo não contratado: explica em vez de abrir uma tela que não funciona.
  const feature = to.meta.feature
  if (typeof feature === 'string' && !has(feature)) return { name: 'module-locked', params: { modulo: feature } }
  return true
})
