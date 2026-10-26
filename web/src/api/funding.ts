import type { Paged } from '@/utils/paged'
import { http } from './http'

type Query = Record<string, unknown>

/** 账户类型：只支持支付宝、对公，见 App\Enum\PaymentAccountType */
export type PaymentAccountType = 'alipay' | 'bank'

export interface PaymentAccountInfo {
  type: PaymentAccountType
  type_label: string
  account_name: string
  account_no: string
  /** 对公开户行，支付宝为 null */
  bank_name: string | null
}

export interface PaymentAccountForm {
  type: PaymentAccountType
  account_name: string
  account_no: string
  bank_name: string
}

/** 收款账户：App\Controller\Admin\ReceivingAccountController */
export interface ReceivingAccount extends PaymentAccountInfo {
  id: number
  status: string
  remark: string | null
  merchant_ids: number[]
  merchant_names: string[]
  created_at: string | null
  updated_at: string | null
}

export const receivingAccountApi = {
  list: () => http.get<ReceivingAccount[]>('/admin/receiving-accounts'),
  create: (data: PaymentAccountForm & { remark: string }) => http.post<ReceivingAccount>('/admin/receiving-accounts', data),
  update: (id: number, data: PaymentAccountForm & { remark: string }) => http.put<ReceivingAccount>(`/admin/receiving-accounts/${id}`, data),
  changeStatus: (id: number, status: string) => http.post<ReceivingAccount>(`/admin/receiving-accounts/${id}/status`, { status }),
  assignMerchants: (id: number, merchantIds: number[]) =>
    http.put<ReceivingAccount>(`/admin/receiving-accounts/${id}/merchants`, { merchant_ids: merchantIds }),
  /** 按商户的收款配置：全部收款账户，assigned 标出这个商户能用的 */
  forMerchant: (merchantId: number) =>
    http.get<(ReceivingAccount & { assigned: boolean })[]>(`/admin/merchants/${merchantId}/receiving-accounts`),
  assignToMerchant: (merchantId: number, accountIds: number[]) =>
    http.put<(ReceivingAccount & { assigned: boolean })[]>(`/admin/merchants/${merchantId}/receiving-accounts`, {
      receiving_account_ids: accountIds,
    }),
}

/** 商户付款账号（审核）：App\Controller\Admin\FundingController */
export interface PayerAccount extends PaymentAccountInfo {
  id: number
  merchant_id: number
  merchant_name: string | null
  status: string
  reject_reason: string | null
  reviewer_name: string | null
  reviewed_at: string | null
  created_at: string | null
  updated_at: string | null
}

/** 加款申请：付款方、收款方都是提交时的快照 */
export interface FundingRequest {
  id: number
  merchant_id: number
  merchant_name: string | null
  amount: string
  paid_at: string | null
  remark: string | null
  voucher_count: number
  status: string
  review_remark: string | null
  reviewer_name: string | null
  reviewed_at: string | null
  balance_log_id: number | null
  created_at: string | null
  payer: PaymentAccountInfo & { id: number | null }
  receiving: PaymentAccountInfo & { id: number | null }
}

export interface FundingRequestDetail extends FundingRequest {
  /** 凭证图片临时链接，10 分钟有效 */
  voucher_urls: string[]
  /** 存储没配好时的错误信息 */
  voucher_error: string | null
}

export const fundingApi = {
  merchantOptions: () => http.get<{ id: number; name: string; status: string }[]>('/admin/funding/merchant-options'),
  requests: (params: Query) => http.get<Paged<FundingRequest>>('/admin/funding/requests', params),
  request: (id: number) => http.get<FundingRequestDetail>(`/admin/funding/requests/${id}`),
  approveRequest: (id: number, remark: string) => http.post<FundingRequestDetail>(`/admin/funding/requests/${id}/approve`, { remark }),
  rejectRequest: (id: number, remark: string) => http.post<FundingRequestDetail>(`/admin/funding/requests/${id}/reject`, { remark }),
  payerAccounts: (params: Query) => http.get<Paged<PayerAccount>>('/admin/funding/payer-accounts', params),
  approvePayer: (id: number) => http.post<PayerAccount>(`/admin/funding/payer-accounts/${id}/approve`),
  rejectPayer: (id: number, reason: string) => http.post<PayerAccount>(`/admin/funding/payer-accounts/${id}/reject`, { reason }),
  onlinePayments: (params: Query) => http.get<Paged<OnlinePayment>>('/admin/funding/online-payments', params),
  onlinePayment: (id: number) => http.get<OnlinePayment>(`/admin/funding/online-payments/${id}`),
  onlinePaymentCandidates: (id: number) => http.post<TransferCandidate[]>(`/admin/funding/online-payments/${id}/candidates`),
  confirmOnlinePayment: (id: number, data: { account_log_id?: string; remark?: string }) =>
    http.post<OnlinePayment>(`/admin/funding/online-payments/${id}/confirm`, data),
}

/** 在线支付（支付宝扫码转账）：App\Service\Admin\OnlinePaymentAdminService */
export interface OnlinePayment {
  id: number
  order_no: string
  merchant_id: number
  merchant_name: string | null
  /** 商户填的转账金额 */
  amount: string
  /** 入账金额：自动入账时等于填的金额，按流水确认时是流水的金额 */
  pay_amount: string
  alipay_account_id: number | null
  /** 收款的支付宝账户名称 */
  alipay_account_name: string | null
  receive_account: string
  receive_name: string
  /** pending 未到账 / paid / expired（只有改版前的单）/ cancelled */
  status: string
  /** 最近一次查询支付宝的时间和结果 */
  checked_at: string | null
  check_message: string | null
  paid_at: string | null
  /** auto 自动入账 / manual 后台确认 */
  confirm_type: string | null
  account_log_id: string | null
  /** 商户填的支付宝订单号（改版前的单是流水上的） */
  alipay_order_no: string | null
  payer_account: string | null
  trans_memo: string | null
  trans_at: string | null
  remark: string | null
  confirmer_name: string | null
  balance_log_id: number | null
  created_at: string | null
}

/** 查找到账记录：这个支付宝订单号的（改版前的单是金额相等、下单之后的）、还没入过账的收入流水 */
export interface TransferCandidate {
  account_log_id: string
  amount: string
  trans_at: string | null
  alipay_order_no: string | null
  payer_account: string | null
  memo: string | null
  /** 订单号对得上，或者备注里是这单的支付单号 */
  memo_matches: boolean
}

/** 支付宝转账加款账户：App\Controller\Admin\AlipayAccountController，应用私钥不回显 */
export interface AlipayAccount {
  id: number
  name: string
  app_id: string
  alipay_public_key: string
  account: string
  account_name: string
  /** 固定收款码图片的 key 和预览链接 */
  qr_code: string | null
  qr_code_url: string | null
  min_amount: string
  max_amount: string
  status: string
  remark: string | null
  created_at: string | null
  updated_at: string | null
  /** 指定用这个账户的商户 */
  merchant_names: string[]
}

export type AlipayAccountForm = Omit<AlipayAccount, 'id' | 'status' | 'remark' | 'qr_code' | 'qr_code_url' | 'created_at' | 'updated_at' | 'merchant_names'> & {
  /** 新建必填，编辑时留空不改 */
  private_key: string
  /** 新上传的收款码 key，新建必填，编辑时留空不改 */
  qr_code: string
  remark: string
}

/** 商户指定的支付宝转账加款账户（一个），null 表示不能用 */
export interface MerchantAlipayAccount {
  alipay_account_id: number | null
  accounts: { id: number; name: string; account: string; status: string }[]
}

export const alipayAccountApi = {
  list: () => http.get<AlipayAccount[]>('/admin/alipay-accounts'),
  create: (data: AlipayAccountForm) => http.post<AlipayAccount>('/admin/alipay-accounts', data),
  update: (id: number, data: AlipayAccountForm) => http.put<AlipayAccount>(`/admin/alipay-accounts/${id}`, data),
  changeStatus: (id: number, status: string) => http.post<AlipayAccount>(`/admin/alipay-accounts/${id}/status`, { status }),
  test: (id: number) => http.post<{ success: boolean; message: string }>(`/admin/alipay-accounts/${id}/test`),
  uploadQrCode: (file: File) => {
    const data = new FormData()
    data.append('file', file)
    return http.post<{ key: string; url: string }>('/admin/alipay-accounts/qr-code', data)
  },
  forMerchant: (merchantId: number) => http.get<MerchantAlipayAccount>(`/admin/merchants/${merchantId}/alipay-account`),
  bindMerchant: (merchantId: number, accountId: number | null) =>
    http.put<MerchantAlipayAccount>(`/admin/merchants/${merchantId}/alipay-account`, { alipay_account_id: accountId }),
}

/** 存储配置：App\Controller\Admin\SettingController */
export interface StorageSetting {
  secret_id: string
  /** SecretKey 不回显，只告诉有没有设置过 */
  secret_key_set: boolean
  region: string
  bucket: string
  path_prefix: string
}

export const settingApi = {
  /** 不用登录：系统名称（空字符串表示用默认名称） */
  publicInfo: () => http.get<{ site_name: string }>('/admin/settings/public'),
  /** 基本设置：系统名称，留空用默认名称 */
  site: () => http.get<{ name: string }>('/admin/settings/site'),
  saveSite: (name: string) => http.put<{ name: string }>('/admin/settings/site', { name }),
  storage: () => http.get<StorageSetting>('/admin/settings/storage'),
  saveStorage: (data: Omit<StorageSetting, 'secret_key_set'> & { secret_key: string }) =>
    http.put<StorageSetting>('/admin/settings/storage', data),
  testStorage: () => http.post<{ success: boolean; message: string }>('/admin/settings/storage/test'),
  /** 回调域名：供应商回调地址 = 回调域名 + /notify/supplier/{供应商编码} */
  notify: () => http.get<{ base_url: string }>('/admin/settings/notify'),
  saveNotify: (baseUrl: string) => http.put<{ base_url: string }>('/admin/settings/notify', { base_url: baseUrl }),
  /** 下单设置：同一号码同一面值在这么多分钟内有进行中的订单就拒绝下单，0 表示不拦截 */
  order: () => http.get<{ duplicate_window_minutes: number }>('/admin/settings/order'),
  saveOrder: (minutes: number) =>
    http.put<{ duplicate_window_minutes: number }>('/admin/settings/order', { duplicate_window_minutes: minutes }),
}
