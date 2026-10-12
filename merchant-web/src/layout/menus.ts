import { CreditCard, HomeFilled, Money, Tickets, User, Wallet } from '@element-plus/icons-vue'
import type { MenuItem } from '@/types'

export const menus: MenuItem[] = [
  { path: '/dashboard', title: '首页', icon: HomeFilled },
  { path: '/orders', title: '订单记录', icon: Tickets },
  { path: '/balance-logs', title: '资金流水', icon: Money },
  { path: '/funding', title: '加款申请', icon: Wallet },
  { path: '/payer-accounts', title: '付款账号', icon: CreditCard },
  { path: '/account', title: '账户', icon: User },
]
