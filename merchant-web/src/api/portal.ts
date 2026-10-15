import { ElMessage } from 'element-plus'
import type { Paged } from '@/utils/paged'
import { useAuthStore } from '@/stores/auth'
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
  /** 充值中但平台这边处理异常（上游返回不明确、超时或接口异常），平台正在人工核实；只有订单列表和详情返回 */
  abnormal?: boolean
  fail_reason: string | null
  created_at: string | null
  finished_at: string | null
}

/** 当前筛选条件下全部订单（不只这一页）的汇总；成功、失败按你看到的状态算 */
export interface OrderSummary {
  order_count: number
  success_count: number
  failed_count: number
  processing_count: number
  /** 成功 /（成功 + 失败）的百分比，没有出结果的单时为 null */
  success_rate: string | null
  /** 成功订单的扣款合计 */
  amount: string
  /** 成功订单的平均耗时（秒） */
  avg_duration: number | null
}

export interface OrderFilterOptions {
  provinces: string[]
  /** 已开通商品的面值（元） */
  face_values: number[]
  /** 已开通商品的分类 */
  categories: { id: number; name: string }[]
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
}

/** 可下单商品：按运营商的价格和平台整体近 7 天的统计 */
export interface CatalogProduct {
  product_code: string
  name: string
  face_value: number
  /** 老商品可能没有分类 */
  category_id: number | null
  category_name: string | null
  operators: {
    operator: string
    price: string
    order_count: number
    /** 成功 /（成功 + 失败）的百分比，没有出结果的单时为 null */
    success_rate: string | null
    /** 成功订单平均耗时（秒） */
    avg_duration: number | null
  }[]
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

export interface BatchPreviewItem {
  mobile: string
  ok: boolean
  operator: string | null
  province: string | null
  price: string | null
  /** 不能下单的原因 */
  error: string | null
}

export interface BatchPreview {
  items: BatchPreviewItem[]
  valid_count: number
  total_amount: string
  balance: string | null
}

export interface BatchResultItem {
  mobile: string
  /** 批次号-序号 */
  merchant_order_no: string
  ok: boolean
  order_no: string | null
  status: string | null
  amount: string | null
  error: string | null
}

export interface BatchResult {
  batch_no: string
  items: BatchResultItem[]
  success_count: number
  failed_count: number
  balance: string | null
}

/** 批量下单：App\Controller\Merchant\BatchOrderController，一批最多 200 个号码 */
export const batchOrderApi = {
  preview: (productCode: string, mobiles: string[]) =>
    http.post<BatchPreview>('/merchant/batch-orders/preview', { product_code: productCode, mobiles }),
  /** 同步逐个下单，号码多时要十几秒以上，超时放宽到 2 分钟；同一个 batchNo 重复提交不会重复下单 */
  submit: (productCode: string, mobiles: string[], batchNo: string) =>
    http.post<BatchResult>('/merchant/batch-orders', { product_code: productCode, mobiles, batch_no: batchNo }, 120000),
}

export const portalApi = {
  dashboard: () => http.get<Dashboard>('/merchant/dashboard'),
  /** mobile 可以是多个手机号（换行、空格、逗号分隔，最多 200 个） */
  orders: (params: Query) => http.get<Paged<Order> & { summary: OrderSummary }>('/merchant/orders', params),
  orderFilterOptions: () => http.get<OrderFilterOptions>('/merchant/order-filter-options'),
  order: (id: number) => http.get<OrderDetail>(`/merchant/orders/${id}`),
  balanceLogs: (params: Query) => http.get<Paged<BalanceLog>>('/merchant/balance-logs', params),
  account: () => http.get<Account>('/merchant/account'),
  /** from / to 是统计的日期范围（近 7 天，不含今天） */
  products: () =>
    http.get<{ from: string; to: string; products: CatalogProduct[]; categories: { id: number; name: string }[] }>(
      '/merchant/products',
    ),
  /** 改 IP 白名单、重置密钥都要再输一次登录密码 */
  updateIpWhitelist: (password: string, ipWhitelist: string) =>
    http.put<{ ip_whitelist: string[] }>('/merchant/account/ip-whitelist', { password, ip_whitelist: ipWhitelist }),
  resetSecret: (password: string) =>
    http.post<{ app_key: string; app_secret: string }>('/merchant/account/reset-secret', { password }),
}

export type ExportType = 'orders' | 'balance_logs'

/** 导出记录：App\Controller\Merchant\ExportController，文件保留 7 天 */
export interface ExportRecord {
  id: number
  type: ExportType
  type_label: string
  filters: Record<string, string | number>
  /** pending 生成中 / success 已完成 / failed 失败 */
  status: string
  file_name: string
  row_count: number | null
  error: string | null
  created_at: string | null
  finished_at: string | null
  expires_at: string | null
}

export const exportApi = {
  /** filters 跟对应列表接口的筛选参数一样，异步生成，到导出列表下载 */
  create: (type: ExportType, filters: Query) => http.post<ExportRecord>('/merchant/exports', { type, filters }),
  list: (params: Query) => http.get<Paged<ExportRecord>>('/merchant/exports', params),
  /** 走 fetch 而不是 http 封装，因为要拿文件流 */
  download: async (record: ExportRecord): Promise<void> => {
    const response = await fetch(`${import.meta.env.VITE_API_BASE_URL}/merchant/exports/${record.id}/download`, {
      headers: { Authorization: `Bearer ${useAuthStore().token ?? ''}` },
    })
    if (!response.ok) {
      ElMessage.error((await response.text()) || '下载失败')
      return
    }
    const url = URL.createObjectURL(await response.blob())
    const link = document.createElement('a')
    link.href = url
    link.download = record.file_name
    link.click()
    URL.revokeObjectURL(url)
  },
}
