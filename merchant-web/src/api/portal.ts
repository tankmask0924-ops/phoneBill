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
  /** 关联订单的手机号，平台加款 / 扣款没有 */
  mobile: string | null
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

/** 付款账号、收款账户类型：只支持支付宝、对公 */
export type PaymentAccountType = 'alipay' | 'bank'

export interface PaymentAccountInfo {
  type: PaymentAccountType
  type_label: string
  account_name: string
  account_no: string
  bank_name: string | null
}

export interface PaymentAccountForm {
  type: PaymentAccountType
  account_name: string
  account_no: string
  bank_name: string
}

/** 付款账号：新增、修改后都要平台审核，通过后才能用来提交加款申请 */
export interface PayerAccount extends PaymentAccountInfo {
  id: number
  status: string
  reject_reason: string | null
  reviewed_at: string | null
  created_at: string | null
  updated_at: string | null
}

/** 平台分配给你的收款账户（打款到这里） */
export interface ReceivingAccount extends PaymentAccountInfo {
  id: number
  remark: string | null
}

export interface FundingRequest {
  id: number
  amount: string
  paid_at: string | null
  remark: string | null
  voucher_count: number
  status: string
  review_remark: string | null
  reviewed_at: string | null
  created_at: string | null
  payer: PaymentAccountInfo & { id: number | null }
  receiving: PaymentAccountInfo & { id: number | null }
  /** 详情才有，10 分钟有效 */
  voucher_urls?: string[]
}

export interface FundingRequestForm {
  amount: string
  payer_account_id: number
  receiving_account_id: number
  /** YYYY-MM-DD HH:mm */
  paid_at: string
  remark: string
  /** 上传凭证返回的 key */
  vouchers: string[]
}

export const fundingApi = {
  payerAccounts: () => http.get<PayerAccount[]>('/merchant/payer-accounts'),
  createPayer: (data: PaymentAccountForm) => http.post<PayerAccount>('/merchant/payer-accounts', data),
  updatePayer: (id: number, data: PaymentAccountForm) => http.put<PayerAccount>(`/merchant/payer-accounts/${id}`, data),
  removePayer: (id: number) => http.delete<{ success: boolean }>(`/merchant/payer-accounts/${id}`),
  receivingAccounts: () => http.get<ReceivingAccount[]>('/merchant/receiving-accounts'),
  uploadVoucher: (file: File) => {
    const data = new FormData()
    data.append('file', file)
    return http.post<{ key: string; url: string }>('/merchant/funding-vouchers', data)
  },
  requests: (params: Query) => http.get<Paged<FundingRequest>>('/merchant/funding-requests', params),
  request: (id: number) => http.get<FundingRequest>(`/merchant/funding-requests/${id}`),
  createRequest: (data: FundingRequestForm) => http.post<FundingRequest>('/merchant/funding-requests', data),
}

export const portalApi = {
  dashboard: () => http.get<Dashboard>('/merchant/dashboard'),
  orders: (params: Query) => http.get<Paged<Order>>('/merchant/orders', params),
  order: (id: number) => http.get<OrderDetail>(`/merchant/orders/${id}`),
  balanceLogs: (params: Query) => http.get<Paged<BalanceLog>>('/merchant/balance-logs', params),
  account: () => http.get<Account>('/merchant/account'),
  /** 改 IP 白名单、重置密钥都要再输一次登录密码 */
  updateIpWhitelist: (password: string, ipWhitelist: string) =>
    http.put<{ ip_whitelist: string[] }>('/merchant/account/ip-whitelist', { password, ip_whitelist: ipWhitelist }),
  resetSecret: (password: string) =>
    http.post<{ app_key: string; app_secret: string }>('/merchant/account/reset-secret', { password }),
}
