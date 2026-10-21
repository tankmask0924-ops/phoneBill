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

export const dashboardApi = {
  summary: () => http.get<AdminDashboard>('/admin/dashboard'),
  todos: () => http.get<AdminTodos>('/admin/dashboard/todos'),
}
