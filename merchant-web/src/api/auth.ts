import type { LoginForm } from '@/types'
import { http } from './http'

export interface LoginResult {
  token: string
  username: string
}

export const login = (form: LoginForm) => http.post<LoginResult>('/merchant/auth/login', form)

/** 当前登录账号：App\Service\MerchantPortal\MerchantAuthService::me() */
export interface Me {
  id: number
  username: string
  real_name: string | null
  merchant_id: number
  merchant_name: string
  merchant_status: string
}

export const fetchMe = () => http.get<Me>('/merchant/auth/me')

export const changePassword = (oldPassword: string, newPassword: string) =>
  http.put<{ token: string }>('/merchant/auth/password', { old_password: oldPassword, new_password: newPassword })
