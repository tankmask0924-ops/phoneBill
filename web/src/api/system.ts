import type { Paged } from '@/utils/paged'
import { http } from './http'

type Query = Record<string, unknown>
type Ok = { success: boolean }

/** 管理员账号：App\Controller\Admin\AdminUserController */
export interface AdminUser {
  id: number
  username: string
  real_name: string
  /** 平台启用了短信时登录验证码发到这里；老账号可能还没有 */
  mobile: string | null
  role_id: number
  role_name: string | null
  status: string
  /** 连续输错密码次数，满 LOGIN_LOCK_FAILURES 次自动封禁 */
  login_failures: number
  last_login_at: string | null
  created_at: string | null
}

/** 管理后台、商户后台登录密码连续输错这么多次自动封禁（和后端 LoginLockoutService::MAX_FAILURES 一致） */
export const LOGIN_LOCK_FAILURES = 5

export interface RoleOption {
  id: number
  name: string
  is_super_admin: boolean
}

export const adminUserApi = {
  list: (params: Query) => http.get<Paged<AdminUser>>('/admin/admin-users', params),
  roleOptions: () => http.get<RoleOption[]>('/admin/admin-users/role-options'),
  create: (data: { username: string; password: string; real_name: string; mobile: string; role_id: number }) =>
    http.post<AdminUser>('/admin/admin-users', data),
  update: (id: number, data: { real_name?: string; mobile?: string; role_id?: number }) => http.put<AdminUser>(`/admin/admin-users/${id}`, data),
  changeStatus: (id: number, status: string) => http.post<AdminUser>(`/admin/admin-users/${id}/status`, { status }),
  resetPassword: (id: number, password: string) => http.post<Ok>(`/admin/admin-users/${id}/password`, { password }),
}

/** 角色权限：App\Controller\Admin\RoleController */
export interface Role {
  id: number
  name: string
  remark: string | null
  is_system: boolean
  is_super_admin: boolean
  permissions: string[]
  user_count: number
}

export interface Permission {
  code: string
  module: string
  name: string
}

export const roleApi = {
  list: () => http.get<Role[]>('/admin/roles'),
  permissions: () => http.get<Permission[]>('/admin/roles/permissions'),
  create: (data: { name: string; remark: string; permissions: string[] }) => http.post<Role>('/admin/roles', data),
  update: (id: number, data: { name?: string; remark?: string; permissions?: string[] }) => http.put<Role>(`/admin/roles/${id}`, data),
  remove: (id: number) => http.delete<Ok>(`/admin/roles/${id}`),
}

/** 操作日志：App\Controller\Admin\OperationLogController */
export interface OperationLog {
  id: number
  admin_user_id: number
  admin_username: string | null
  admin_real_name: string | null
  module: string
  action: string
  target_type: string | null
  target_id: number | null
  before_data: unknown
  after_data: unknown
  ip: string | null
  created_at: string | null
}

export const operationLogApi = {
  list: (params: Query) => http.get<Paged<OperationLog>>('/admin/operation-logs', params),
}

/** 登录日志：App\Controller\Admin\SecurityLogController */
export interface SecurityLog {
  id: number
  portal: 'admin' | 'merchant'
  event: string
  user_id: number | null
  username: string
  merchant_id: number | null
  merchant_name: string | null
  ip: string | null
  user_agent: string | null
  detail: Record<string, unknown> | null
  created_at: string | null
}

export const securityLogApi = {
  list: (params: Query) => http.get<Paged<SecurityLog>>('/admin/security-logs', params),
}
