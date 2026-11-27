import type { LoginForm } from '@/types'
import { http } from './http'

export interface LoginResult {
  token: string
  username: string
}

/** 平台启用了短信时登录要再验证短信：先拿 ticket（10 分钟有效），再用 ticket + 验证码换 token */
export interface SmsChallenge {
  need_sms: true
  ticket: string
  /** 打码后的手机号 */
  mobile: string
  /** false：60 秒内刚发过，没有再发（之前那条仍有效） */
  code_sent: boolean
}

export const login = (form: LoginForm) => http.post<LoginResult | SmsChallenge>('/admin/auth/login', form)

export const resendLoginCode = (ticket: string) => http.post<{ mobile: string }>('/admin/auth/login/sms-code', { ticket })

export const verifyLogin = (ticket: string, code: string) => http.post<LoginResult>('/admin/auth/login/verify', { ticket, code })

/** 当前登录管理员：App\Service\Admin\AuthService::me() */
export interface Me {
  id: number
  username: string
  real_name: string
  /** 手机号，平台启用了短信时登录验证码发到这里 */
  mobile: string | null
  role_id: number
  role_name: string | null
  is_super_admin: boolean
  status: string
  permissions: string[]
}

export const fetchMe = () => http.get<Me>('/admin/auth/me')

export const changePassword = (oldPassword: string, newPassword: string) =>
  http.put<{ token: string }>('/admin/auth/password', { old_password: oldPassword, new_password: newPassword })
