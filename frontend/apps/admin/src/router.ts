import { createRouter, createWebHistory } from 'vue-router'
import AdminLayout from './layouts/AdminLayout.vue'
import { loadSession, me } from './session'

export const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/login', name: 'login', component: () => import('./pages/LoginPage.vue'), meta: { guest: true } },
    { path: '/esqueci-senha', name: 'forgot', component: () => import('./pages/ForgotPasswordPage.vue'), meta: { guest: true } },
    { path: '/redefinir-senha', name: 'reset', component: () => import('./pages/ResetPasswordPage.vue'), meta: { guest: true } },
    {
      path: '/',
      component: AdminLayout,
      children: [
        { path: '', name: 'home', component: () => import('./pages/TenantsPage.vue') },
        { path: 'novo', name: 'tenant-new', component: () => import('./pages/TenantNewPage.vue') },
        { path: 'estabelecimentos/:id', name: 'tenant', component: () => import('./pages/TenantPage.vue'), props: true },
        { path: 'auditoria', name: 'audit', component: () => import('./pages/AuditPage.vue') },
        { path: 'sistema', name: 'system', component: () => import('./pages/SystemPage.vue') },
      ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  if (me.value === null && !to.meta.guest) {
    await loadSession().catch(() => null)
  }
  if (!to.meta.guest && me.value === null) {
    return { name: 'login' }
  }
  if (to.meta.guest && me.value !== null) {
    return { name: 'home' }
  }
  return true
})
