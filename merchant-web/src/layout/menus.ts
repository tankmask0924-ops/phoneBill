import { HomeFilled, Money, Tickets, User } from '@element-plus/icons-vue'
import type { MenuItem } from '@/types'

export const menus: MenuItem[] = [
  { path: '/dashboard', title: '首页', icon: HomeFilled },
  { path: '/orders', title: '订单记录', icon: Tickets },
  { path: '/balance-logs', title: '资金流水', icon: Money },
  { path: '/account', title: '账户', icon: User },
]
