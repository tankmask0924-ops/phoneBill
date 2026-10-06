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
  supplier_id: number | null
  supplier_name: string | null
  /** pending / processing / abnormal / success / failed */
  status: string
  fail_reason: string | null
  notify_url: string | null
  notify_status: string
  notified_at: string | null
  created_at: string | null
  finished_at: string | null
  /** 下单受理到出结果的秒数，还没结果时为 null */
  duration: number | null
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

export interface OrderFilterOptions {
  merchants: { id: number; name: string }[]
  suppliers: { id: number; name: string }[]
  provinces: string[]
}

export const orderApi = {
  list: (params: Query) => http.get<Paged<Order>>('/admin/orders', params),
  filterOptions: () => http.get<OrderFilterOptions>('/admin/orders/filter-options'),
  detail: (id: number) => http.get<OrderDetail>(`/admin/orders/${id}`),
  query: (id: number) => http.post<OrderDetail>(`/admin/orders/${id}/query`),
  confirmSuccess: (id: number, remark: string) => http.post<OrderDetail>(`/admin/orders/${id}/success`, { remark }),
  confirmFailed: (id: number, remark: string) => http.post<OrderDetail>(`/admin/orders/${id}/fail`, { remark }),
  reverse: (id: number, remark: string) => http.post<OrderDetail>(`/admin/orders/${id}/reverse`, { remark }),
  resendNotify: (id: number) => http.post<OrderDetail>(`/admin/orders/${id}/notify`),
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
