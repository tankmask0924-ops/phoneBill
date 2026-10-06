/**
 * 后端枚举值的中文名和标签颜色。
 * 值以后端校验白名单为准（见各 Service 里的常量）。
 */

export type TagType = 'primary' | 'success' | 'info' | 'warning' | 'danger'

export interface LabelItem {
  label: string
  type?: TagType
}

export type LabelMap = Record<string, LabelItem>

/** 转成 el-select 的选项 */
export function toOptions(map: LabelMap): { value: string; label: string }[] {
  return Object.entries(map).map(([value, item]) => ({ value, label: item.label }))
}

export function labelOf(map: LabelMap, value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') {
    return '-'
  }
  return map[value]?.label ?? value
}

export const adminUserStatusLabels: LabelMap = {
  active: { label: '启用', type: 'success' },
  disabled: { label: '禁用', type: 'info' },
}

/** 角色名：超级管理员角色在库里叫 super_admin */
export function roleLabel(name: string | null | undefined): string {
  return name === 'super_admin' ? '超级管理员' : (name ?? '-')
}

/** 权限 / 操作日志的模块，见 AdminBootstrapService::KNOWN_PERMISSIONS 的 module 和权限编码前缀 */
export const moduleLabels: LabelMap = {
  system: { label: '系统设置' },
  admin_user: { label: '管理员账号' },
  role: { label: '角色权限' },
}

/** 操作日志 action：Service 手动记的 + AdminOperationLogAspect 按方法名自动生成的 */
export const operationActionLabels: LabelMap = {
  create_admin_user: { label: '新建管理员' },
  update_admin_user: { label: '修改管理员' },
  enable_admin_user: { label: '启用管理员' },
  disable_admin_user: { label: '禁用管理员' },
  reset_admin_password: { label: '重置管理员密码' },
  create_role: { label: '新建角色' },
  update_role: { label: '修改角色' },
  delete_role: { label: '删除角色' },
}
