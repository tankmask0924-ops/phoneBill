import { createRouter, createWebHistory } from 'vue-router'
import Layout from '@/layout/index.vue'
import { useAuthStore } from '@/stores/auth'

declare module 'vue-router' {
  interface RouteMeta {
    /** 显示在顶栏和浏览器标题 */
    title?: string
    /** 无需登录即可访问 */
    public?: boolean
  }
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { title: '登录', public: true },
    },
    {
      path: '/',
      component: Layout,
      redirect: '/dashboard',
      children: [
        { path: 'dashboard', name: 'dashboard', component: () => import('@/views/DashboardView.vue'), meta: { title: '首页' } },
        { path: 'orders', name: 'orders', component: () => import('@/views/OrderListView.vue'), meta: { title: '订单记录' } },
        {
          path: 'balance-logs',
          name: 'balance-logs',
          component: () => import('@/views/BalanceLogView.vue'),
          meta: { title: '资金流水' },
        },
        { path: 'account', name: 'account', component: () => import('@/views/AccountView.vue'), meta: { title: '账户' } },
      ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

const appTitle = import.meta.env.VITE_APP_TITLE

router.beforeEach((to) => {
  const loggedIn = useAuthStore().isLoggedIn
  if (to.meta.public) {
    return to.name === 'login' && loggedIn ? { path: '/' } : true
  }
  return loggedIn ? true : { name: 'login', query: { redirect: to.fullPath } }
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} - ${appTitle}` : appTitle
})

export default router
