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
        {
          path: 'dashboard',
          name: 'dashboard',
          component: () => import('@/views/DashboardView.vue'),
          meta: { title: '首页' },
        },
        {
          path: 'order/orders',
          name: 'orders',
          component: () => import('@/views/order/OrderListView.vue'),
          meta: { title: '订单' },
        },
        {
          path: 'order/abnormal',
          name: 'abnormal-orders',
          component: () => import('@/views/order/OrderListView.vue'),
          props: { abnormal: true },
          meta: { title: '异常订单' },
        },
        {
          path: 'product/suppliers',
          name: 'suppliers',
          component: () => import('@/views/product/SupplierListView.vue'),
          meta: { title: '供应商' },
        },
        {
          path: 'product/supplier-products',
          name: 'supplier-products',
          component: () => import('@/views/product/SupplierProductListView.vue'),
          meta: { title: '供应商商品' },
        },
        {
          path: 'product/products',
          name: 'products',
          component: () => import('@/views/product/ProductListView.vue'),
          meta: { title: '平台商品' },
        },
        {
          path: 'product/stats',
          name: 'product-stats',
          component: () => import('@/views/product/ProductStatsView.vue'),
          meta: { title: '商品统计' },
        },
        {
          path: 'merchant/merchants',
          name: 'merchants',
          component: () => import('@/views/merchant/MerchantListView.vue'),
          meta: { title: '商户' },
        },
        {
          path: 'merchant/balance-logs',
          name: 'balance-logs',
          component: () => import('@/views/merchant/BalanceLogView.vue'),
          meta: { title: '资金流水' },
        },
        {
          path: 'risk/segments',
          name: 'segments',
          component: () => import('@/views/risk/SegmentLookupView.vue'),
          meta: { title: '号段查询' },
        },
        {
          path: 'risk/blacklist',
          name: 'blacklist',
          component: () => import('@/views/risk/BlacklistView.vue'),
          meta: { title: '号码黑名单' },
        },
        {
          path: 'risk/maintenances',
          name: 'maintenances',
          component: () => import('@/views/risk/MaintenanceView.vue'),
          meta: { title: '通道维护' },
        },
        {
          path: 'system/admin-users',
          name: 'admin-users',
          component: () => import('@/views/system/AdminUserListView.vue'),
          meta: { title: '管理员账号' },
        },
        {
          path: 'system/roles',
          name: 'roles',
          component: () => import('@/views/system/RoleListView.vue'),
          meta: { title: '角色权限' },
        },
        {
          path: 'system/operation-logs',
          name: 'operation-logs',
          component: () => import('@/views/system/OperationLogView.vue'),
          meta: { title: '操作日志' },
        },
      ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

const appTitle = import.meta.env.VITE_APP_TITLE

router.beforeEach((to) => {
  const loggedIn = useAuthStore().isLoggedIn
  if (to.meta.public) {
    // 已登录时访问登录页，直接回首页
    return to.name === 'login' && loggedIn ? { path: '/' } : true
  }
  return loggedIn ? true : { name: 'login', query: { redirect: to.fullPath } }
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} - ${appTitle}` : appTitle
})

export default router
