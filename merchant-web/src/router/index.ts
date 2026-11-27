import { createRouter, createWebHistory } from 'vue-router'
import Layout from '@/layout/index.vue'
import { useAuthStore } from '@/stores/auth'
import { useMeStore } from '@/stores/me'

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
      path: '/bind-mobile',
      name: 'bind-mobile',
      component: () => import('@/views/BindMobileView.vue'),
      meta: { title: '绑定手机' },
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

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (to.meta.public) {
    return to.name === 'login' && auth.isLoggedIn ? { path: '/' } : true
  }
  if (!auth.isLoggedIn) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  // 平台启用了短信、账号没绑定手机：先去绑定（后端同样拦截其他接口）
  const meStore = useMeStore()
  try {
    await meStore.load()
  } catch {
    // 401 已由 http 拦截器跳转登录页；其他错误先放行，页面自己会报错
    return auth.isLoggedIn ? true : { name: 'login', query: { redirect: to.fullPath } }
  }
  if (meStore.mobileRequired) {
    return to.name === 'bind-mobile' ? true : { name: 'bind-mobile', query: { redirect: to.fullPath } }
  }
  return to.name === 'bind-mobile' ? { path: '/' } : true
})

export default router
