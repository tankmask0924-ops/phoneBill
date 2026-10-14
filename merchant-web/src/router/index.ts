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
        { path: 'batch-order', name: 'batch-order', component: () => import('@/views/BatchOrderView.vue'), meta: { title: '批量下单' } },
        {
          path: 'balance-logs',
          name: 'balance-logs',
          component: () => import('@/views/BalanceLogView.vue'),
          meta: { title: '资金流水' },
        },
        { path: 'exports', name: 'exports', component: () => import('@/views/ExportListView.vue'), meta: { title: '导出列表' } },
        { path: 'products', name: 'products', component: () => import('@/views/ProductsView.vue'), meta: { title: '可下单商品' } },
        { path: 'funding', name: 'funding', component: () => import('@/views/FundingView.vue'), meta: { title: '加款申请' } },
        { path: 'payer-accounts', name: 'payer-accounts', component: () => import('@/views/PayerAccountView.vue'), meta: { title: '付款账号' } },
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
