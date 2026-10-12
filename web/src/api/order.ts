import type { Paged } from '@/utils/paged'
import { useAuthStore } from '@/stores/auth'
import type { OperatorCode } from './product'
import { http } from './http'

type Query = Record<string, unknown>

/** 订单：App\Controller\Admin\OrderController */
export interface Order {
  id: number
  order_no: string
  merchant_id: number
  merchant_name: string | null
  merchant_order_no: string
  product_id: number
  product_code: string
  product_name: string
  mobile: string
  operator: OperatorCode
  province: string
  face_value: number
  sale_price: string
  cost_price: string | null
  /** 利润 = 扣款 - 成本，成功时写入；失败（含冲正）为空 */
  profit: string | null
  supplier_id: number | null
  supplier_name: string | null
  /** pending / processing / abnormal / success / failed */
  status: string
  fail_reason: string | null
  notify_url: string | null
  notify_status: string
  notified_at: string | null
  /** 人工处理单：提交时接口异常，上游结果只记录，状态和回调全部人工 */
  manual_only: boolean
  /** 最近一次手动回调；回调过之后上游结果不再推给商户 */
  manual_notified_at: string | null
  created_at: string | null
  finished_at: string | null
  /** 下单受理到出结果的秒数，还没结果时为 null */
  duration: number | null
}

/** 订单列表汇总：成功率 = 成功 /（成功 + 失败）；扣款、成本、利润、平均耗时只算成功的订单 */
export interface OrderSummary {
  order_count: number
  success_count: number
  failed_count: number
  /** 充值中、等待中、异常 */
  processing_count: number
  success_rate: string | null
  amount: string
  cost: string
  profit: string
  /** 秒 */
  avg_duration: number | null
}

export interface OrderAttempt {
  id: number
  attempt_no: string
  supplier_id: number
  supplier_name: string | null
  supplier_product_name: string | null
  cost_price: string
  supplier_order_no: string | null
  status: string
  message: string | null
  request: string | null
  response: string | null
  submitted_at: string | null
  last_queried_at: string | null
  finished_at: string | null
  /** 提交给供应商到出结果（回调或查单）的秒数 */
  duration: number | null
}

export interface OrderDetail extends Order {
  /** 标红提醒：上游结果和订单矛盾、人工处理单待办 */
  warnings: string[]
  attempts: OrderAttempt[]
  notify_logs: {
    attempt_no: number
    url: string
    http_status: number | null
    response: string | null
    success: boolean
    created_at: string | null
  }[]
  balance_logs: { type: string; amount: string; balance_after: string; created_at: string | null }[]
}

/** 手动补单可选的供应商商品 */
export interface ResupplyOption {
  supplier_product_id: number
  supplier_product_name: string
  supplier_name: string
  cost_price: string
  priority: number
  /** 这笔订单之前提交过 */
  tried: boolean
}

export interface OrderFilterOptions {
  merchants: { id: number; name: string }[]
  suppliers: { id: number; name: string }[]
  provinces: string[]
}

export const orderApi = {
  /** summary 是当前筛选条件下全部订单（不只这一页）的汇总 */
  list: (params: Query) => http.get<Paged<Order> & { summary: OrderSummary }>('/admin/orders', params),
  filterOptions: () => http.get<OrderFilterOptions>('/admin/orders/filter-options'),
  detail: (id: number) => http.get<OrderDetail>(`/admin/orders/${id}`),
  /** 人工查单，query_results 是这次每个处理中的提交查到的结果 */
  query: (id: number) =>
    http.post<OrderDetail & { query_results: { attempt_no: string; status: string; message: string | null }[] }>(`/admin/orders/${id}/query`),
  confirmSuccess: (id: number, remark: string) => http.post<OrderDetail>(`/admin/orders/${id}/success`, { remark }),
  confirmFailed: (id: number, remark: string) => http.post<OrderDetail>(`/admin/orders/${id}/fail`, { remark }),
  reverse: (id: number, remark: string) => http.post<OrderDetail>(`/admin/orders/${id}/reverse`, { remark }),
  /** 手动回调：把当前结果推给商户 */
  manualNotify: (id: number) => http.post<OrderDetail>(`/admin/orders/${id}/notify`),
  resupplyOptions: (id: number) => http.get<ResupplyOption[]>(`/admin/orders/${id}/resupply-options`),
  /** 手动补单：同一笔订单重新提交给指定的供应商商品 */
  resupply: (id: number, supplierProductId: number, remark: string) =>
    http.post<OrderDetail>(`/admin/orders/${id}/resupply`, { supplier_product_id: supplierProductId, remark }),
}

/**
 * 按当前筛选条件下载 CSV。走 fetch 而不是 http 封装，因为要拿文件流。
 */
export async function downloadOrders(params: Query): Promise<void> {
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value !== '' && value !== null && value !== undefined) {
      query.set(key, String(value))
    }
  }
  const response = await fetch(`${import.meta.env.VITE_API_BASE_URL}/admin/orders/export?${query}`, {
    headers: { Authorization: `Bearer ${useAuthStore().token ?? ''}` },
  })
  if (!response.ok) {
    throw new Error((await response.text()) || '导出失败')
  }
  const blob = await response.blob()
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = response.headers.get('Content-Disposition')?.match(/filename="(.+)"/)?.[1] ?? 'orders.csv'
  link.click()
  URL.revokeObjectURL(url)
}
