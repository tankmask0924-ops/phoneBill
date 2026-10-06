import { Box, Document, Goods, HomeFilled, Key, Lock, Search, Setting, Shop, ShoppingBag, UserFilled } from '@element-plus/icons-vue'
import type { MenuItem } from '@/types'

/** permission 对应后端 #[RequiresPermission] 的查看权限，没有权限的菜单不显示 */
export const menus: MenuItem[] = [
  { path: '/dashboard', title: '首页', icon: HomeFilled },
  {
    path: '/product',
    title: '商品中心',
    icon: Goods,
    children: [
      { path: '/product/suppliers', title: '供应商', icon: Shop, permission: 'supplier.view' },
      { path: '/product/supplier-products', title: '供应商商品', icon: Box, permission: 'supplier_product.view' },
      { path: '/product/products', title: '平台商品', icon: ShoppingBag, permission: 'product.view' },
    ],
  },
  {
    path: '/risk',
    title: '风控',
    icon: Lock,
    children: [{ path: '/risk/segments', title: '号段查询', icon: Search, permission: 'risk.view' }],
  },
  {
    path: '/system',
    title: '系统设置',
    icon: Setting,
    children: [
      { path: '/system/admin-users', title: '管理员账号', icon: UserFilled, permission: 'admin_user.view' },
      { path: '/system/roles', title: '角色权限', icon: Key, permission: 'role.view' },
      { path: '/system/operation-logs', title: '操作日志', icon: Document, permission: 'operation_log.view' },
    ],
  },
]
