import { http } from './http'

/** 首页汇总：App\Controller\Admin\DashboardController，需要 order.view。销售额、利润只算成功订单 */
export interface AdminDashboard {
  /** 全部订单累计（缓存 1 分钟） */
  total: {
    sales: string
    merchant_count: number
    order_count: number
    profit: string
  }
  today: {
    order_count: number
    success_count: number
    sales: string
    profit: string
  }
}

/** 待办数量，需要 funding.view，实时 */
export interface AdminTodos {
  pending_payer_accounts: number
  pending_funding_requests: number
}

/** 订单待办，需要 order.view，实时 */
export interface AdminOrderTodos {
  /** 异常订单（等人工处理）的数量 */
  abnormal_orders: number
}

export const dashboardApi = {
  summary: () => http.get<AdminDashboard>('/admin/dashboard'),
  /** silent：右下角提醒每分钟轮询，出错不弹提示 */
  todos: (silent = false) => http.get<AdminTodos>('/admin/dashboard/todos', undefined, { silent }),
  orderTodos: (silent = false) => http.get<AdminOrderTodos>('/admin/dashboard/order-todos', undefined, { silent }),
}
