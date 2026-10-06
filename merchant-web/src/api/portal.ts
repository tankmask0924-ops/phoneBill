import type { Paged } from '@/utils/paged'
import { http } from './http'

type Query = Record<string, unknown>

/** 商户后台：App\Controller\Merchant\PortalController */
export interface Dashboard {
  balance: string
  merchant_status: string
  today: {
    order_count: number
    processing_count: number
    success_count: number
    failed_count: number
    success_amount: string
  }
}

export interface Order {
  id: number
  order_no: string
  merchant_order_no: string
  product_code: string
  product_name: string
  mobile: string
  operator: string
  province: string
  face_value: number
  amount: string
  /** processing 充值中 / success 成功 / failed 失败（已退款） */
  status: string
  fail_reason: string | null
  created_at: string | null
  finished_at: string | null
}

export interface OrderDetail extends Order {
  balance_logs: { type: string; amount: string; balance_after: string; created_at: string | null }[]
}

export interface BalanceLog {
  id: number
  type: string
  amount: string
  balance_after: string
  order_id: number | null
  order_no: string | null
  merchant_order_no: string | null
  remark: string | null
  created_at: string | null
}

export interface Account {
  name: string
  status: string
  app_key: string
  notify_url: string | null
  ip_whitelist: string[]
  products: { product_code: string; name: string; face_value: number; prices: Record<string, string> }[]
}

export const portalApi = {
  dashboard: () => http.get<Dashboard>('/merchant/dashboard'),
  orders: (params: Query) => http.get<Paged<Order>>('/merchant/orders', params),
  order: (id: number) => http.get<OrderDetail>(`/merchant/orders/${id}`),
  balanceLogs: (params: Query) => http.get<Paged<BalanceLog>>('/merchant/balance-logs', params),
  account: () => http.get<Account>('/merchant/account'),
}
