import type { LoginForm } from '@/types'
import { http } from './http'

export interface LoginResult {
  token: string
  username: string
}

/** 绑定了手机时登录要再验证短信：先拿 ticket（10 分钟有效），再用 ticket + 验证码换 token */
export interface SmsChallenge {
  need_sms: true
  ticket: string
  /** 打码后的手机号 */
  mobile: string
  /** false：60 秒内刚发过，没有再发（之前那条仍有效） */
  code_sent: boolean
}

export const login = (form: LoginForm) => http.post<LoginResult | SmsChallenge>('/merchant/auth/login', form)

export const resendLoginCode = (ticket: string) => http.post<{ mobile: string }>('/merchant/auth/login/sms-code', { ticket })

export const verifyLogin = (ticket: string, code: string) => http.post<LoginResult>('/merchant/auth/login/verify', { ticket, code })

/** 当前登录账号：App\Service\MerchantPortal\MerchantAuthService::me() */
export interface Me {
  id: number
  username: string
  real_name: string | null
  /** 绑定的手机号 */
  mobile: string | null
  /** 平台是否启用了短信（没启用不能绑定手机） */
  sms_available: boolean
  merchant_id: number
  merchant_name: string
  merchant_status: string
}

export const fetchMe = () => http.get<Me>('/merchant/auth/me')

export const changePassword = (oldPassword: string, newPassword: string) =>
  http.put<{ token: string }>('/merchant/auth/password', { old_password: oldPassword, new_password: newPassword })

/** 给要绑定的新手机号发验证码 */
export const sendBindCode = (mobile: string) => http.post<{ success: boolean }>('/merchant/auth/mobile/code', { mobile })

/** 绑定 / 修改手机：登录密码 + 新手机号的验证码 */
export const bindMobile = (data: { mobile: string; code: string; password: string }) =>
  http.put<{ mobile: string }>('/merchant/auth/mobile', data)
