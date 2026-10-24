import { createRouter, createWebHistory } from 'vue-router'
import { loadSession, me } from './session'

export const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/login', name: 'login', component: () => import('./pages/LoginPage.vue'), meta: { guest: true } },
    { path: '/', name: 'home', component: () => import('./pages/HomePage.vue') },
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
