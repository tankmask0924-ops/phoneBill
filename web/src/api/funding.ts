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
  storage: () => http.get<StorageSetting>('/admin/settings/storage'),
  saveStorage: (data: Omit<StorageSetting, 'secret_key_set'> & { secret_key: string }) =>
    http.put<StorageSetting>('/admin/settings/storage', data),
  testStorage: () => http.post<{ success: boolean; message: string }>('/admin/settings/storage/test'),
}
