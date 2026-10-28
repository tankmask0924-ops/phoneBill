import type { ProvinceStatTotals } from './product'
import { http } from './http'

type Query = Record<string, unknown>

/** 首页汇总：App\Controller\Admin\DashboardController，需要 order.view。销售额、利润只算成功订单 */
export interface AdminDashboard {
  /** 全部订单累计：昨天及以前取自省份统计（整点重算），加上今天实时的 */
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

/** 经营统计：GET /admin/dashboard/stats，汇总省份统计、省份商品统计两张日表（每小时整点重算当天），需要 order.view */
export interface DashboardStats {
  date_from: string
  date_to: string
  /** 范围内最近一次统计的时间，没有数据为 null */
  computed_at: string | null
  summary: ProvinceStatTotals
  /** 每天一行，从新到旧，没订单的日子也有（全 0） */
  days: (ProvinceStatTotals & { stat_date: string })[]
  suppliers: (ProvinceStatTotals & { supplier_id: number; supplier_name: string | null })[]
  products: (ProvinceStatTotals & {
    product_id: number
    product_code: string | null
    product_name: string | null
    face_value: number | null
  })[]
  provinces: (ProvinceStatTotals & { province: string })[]
}

export const dashboardApi = {
  summary: () => http.get<AdminDashboard>('/admin/dashboard'),
  /** silent：右下角提醒每分钟轮询，出错不弹提示 */
  todos: (silent = false) => http.get<AdminTodos>('/admin/dashboard/todos', undefined, { silent }),
  orderTodos: (silent = false) => http.get<AdminOrderTodos>('/admin/dashboard/order-todos', undefined, { silent }),
  stats: (params: Query) => http.get<DashboardStats>('/admin/dashboard/stats', params),
}
