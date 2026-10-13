import { CreditCard, Goods, HomeFilled, Money, Tickets, Upload, User, Wallet } from '@element-plus/icons-vue'
import type { MenuItem } from '@/types'

export const menus: MenuItem[] = [
  { path: '/dashboard', title: '首页', icon: HomeFilled },
  { path: '/orders', title: '订单记录', icon: Tickets },
  { path: '/batch-order', title: '批量下单', icon: Upload },
  { path: '/products', title: '可下单商品', icon: Goods },
  { path: '/balance-logs', title: '资金流水', icon: Money },
  { path: '/funding', title: '加款申请', icon: Wallet },
  { path: '/payer-accounts', title: '付款账号', icon: CreditCard },
  { path: '/account', title: '账户', icon: User },
]
