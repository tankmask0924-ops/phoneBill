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

/** 供应商：启用 / 停用 */
export const supplierStatusLabels: LabelMap = {
  active: { label: '启用', type: 'success' },
  disabled: { label: '停用', type: 'info' },
}

/** 供应商商品、平台商品：上架 / 下架 */
export const shelfStatusLabels: LabelMap = {
  active: { label: '上架', type: 'success' },
  disabled: { label: '下架', type: 'info' },
}

/** 运营商，见 App\Enum\Operator */
export const operatorLabels: LabelMap = {
  cmcc: { label: '移动' },
  cucc: { label: '联通' },
  ctcc: { label: '电信' },
  cbn: { label: '广电' },
}

/** 资金流水类型，见 App\Model\MerchantBalanceLog */
export const balanceTypeLabels: LabelMap = {
  recharge: { label: '加款', type: 'success' },
  deduct: { label: '扣款', type: 'warning' },
  order_pay: { label: '下单扣款' },
  order_refund: { label: '失败退款', type: 'success' },
}

/** 通道维护状态 */
export const maintenanceStateLabels: LabelMap = {
  active: { label: '维护中', type: 'danger' },
  upcoming: { label: '未开始', type: 'warning' },
  ended: { label: '已结束', type: 'info' },
}

/** 供应商覆盖省份：* 表示全国 */
export function provincesLabel(provinces: string[]): string {
  return provinces.includes('*') ? '全国' : provinces.join('、')
}

/** 权限 / 操作日志的模块，见 AdminBootstrapService::KNOWN_PERMISSIONS 的 module 和权限编码前缀 */
export const moduleLabels: LabelMap = {
  system: { label: '系统设置' },
  product: { label: '商品中心' },
  risk: { label: '风控' },
  merchant: { label: '商户中心' },
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
  create_supplier: { label: '新建供应商' },
  update_supplier: { label: '修改供应商' },
  enable_supplier: { label: '启用供应商' },
  disable_supplier: { label: '停用供应商' },
  create_supplier_product: { label: '新建供应商商品' },
  update_supplier_product: { label: '修改供应商商品' },
  enable_supplier_product: { label: '上架供应商商品' },
  disable_supplier_product: { label: '下架供应商商品' },
  create_product: { label: '新建平台商品' },
  update_product: { label: '修改平台商品' },
  enable_product: { label: '上架平台商品' },
  disable_product: { label: '下架平台商品' },
  create_merchant: { label: '新建商户' },
  update_merchant: { label: '修改商户' },
  enable_merchant: { label: '启用商户' },
  disable_merchant: { label: '停用商户' },
  reset_merchant_secret: { label: '重置商户密钥' },
  recharge_merchant: { label: '商户加款' },
  deduct_merchant: { label: '商户扣款' },
  open_merchant_product: { label: '给商户开通商品' },
  update_merchant_product: { label: '修改商户商品价格' },
  add_blacklist: { label: '加入黑名单' },
  remove_blacklist: { label: '移出黑名单' },
  create_maintenance: { label: '添加通道维护' },
  finish_maintenance: { label: '结束通道维护' },
}
