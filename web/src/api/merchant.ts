import type { Paged } from '@/utils/paged'
import type { OperatorCode } from './product'
import { http } from './http'

type Query = Record<string, unknown>

/** 商户：App\Controller\Admin\MerchantController */
export interface Merchant {
  id: number
  name: string
  contact: string | null
  phone: string | null
  app_key: string
  balance: string
  notify_url: string | null
  /** IP 或 CIDR，空数组表示不限制 */
  ip_whitelist: string[]
  status: string
  remark: string | null
  product_count: number
  created_at: string | null
  updated_at: string | null
}

export interface MerchantForm {
  name: string
  contact: string
  phone: string
  notify_url: string
  ip_whitelist: string
  remark: string
}

export interface MerchantOption {
  id: number
  name: string
  status: string
}

/** 商户开通的商品 */
export interface MerchantProduct {
  id: number
  merchant_id: number
  product_id: number
  product_code: string | null
  product_name: string | null
  product_status: string | null
  face_value: number | null
  /** 商品覆盖的运营商 */
  operators: OperatorCode[]
  default_prices: Partial<Record<OperatorCode, string>>
  prices: Partial<Record<OperatorCode, string>>
  status: string
  warnings: string[]
  updated_at: string | null
}

/** 还没开通的商品 */
export interface AvailableProduct {
  id: number
  code: string
  name: string
  face_value: number
  operators: OperatorCode[]
  prices: Partial<Record<OperatorCode, string>>
}

/** 商户后台登录账号 */
export interface MerchantUser {
  id: number
  merchant_id: number
  username: string
  real_name: string | null
  status: string
  last_login_at: string | null
  created_at: string | null
}

export const merchantApi = {
  list: (params: Query) => http.get<Paged<Merchant>>('/admin/merchants', params),
  options: () => http.get<MerchantOption[]>('/admin/merchants/options'),
  /** 返回值里的 app_secret 只有这一次 */
  create: (data: MerchantForm) => http.post<Merchant & { app_secret: string }>('/admin/merchants', data),
  update: (id: number, data: Partial<MerchantForm>) => http.put<Merchant>(`/admin/merchants/${id}`, data),
  changeStatus: (id: number, status: string) => http.post<Merchant>(`/admin/merchants/${id}/status`, { status }),
  resetSecret: (id: number) => http.post<{ app_key: string; app_secret: string }>(`/admin/merchants/${id}/secret`),
  adjustBalance: (id: number, data: { type: 'recharge' | 'deduct'; amount: string; remark: string }) =>
    http.post<Merchant>(`/admin/merchants/${id}/balance`, data),
  products: (id: number) => http.get<MerchantProduct[]>(`/admin/merchants/${id}/products`),
  availableProducts: (id: number) => http.get<AvailableProduct[]>(`/admin/merchants/${id}/available-products`),
  saveProduct: (id: number, productId: number, data: { status?: string; prices: Partial<Record<OperatorCode, string>> }) =>
    http.put<MerchantProduct>(`/admin/merchants/${id}/products/${productId}`, data),
  users: (id: number) => http.get<MerchantUser[]>(`/admin/merchants/${id}/users`),
  createUser: (id: number, data: { username: string; password: string; real_name: string }) =>
    http.post<MerchantUser>(`/admin/merchants/${id}/users`, data),
  changeUserStatus: (id: number, userId: number, status: string) =>
    http.post<MerchantUser>(`/admin/merchants/${id}/users/${userId}/status`, { status }),
  resetUserPassword: (id: number, userId: number, password: string) =>
    http.post<{ success: boolean }>(`/admin/merchants/${id}/users/${userId}/password`, { password }),
}

/** 资金流水：App\Controller\Admin\BalanceLogController */
export interface BalanceLog {
  id: number
  merchant_id: number
  merchant_name: string | null
  type: string
  amount: string
  balance_after: string
  order_id: number | null
  order_no: string | null
  remark: string | null
  admin_user_id: number | null
  admin_name: string | null
  created_at: string | null
}

export const balanceLogApi = {
  list: (params: Query) => http.get<Paged<BalanceLog>>('/admin/balance-logs', params),
}
