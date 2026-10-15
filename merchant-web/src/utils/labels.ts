/**
 * 后端枚举值的中文名和标签颜色。
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

/** 订单状态（商户视角）。abnormal 只在商户后台显示，开放接口查单、回调仍然是 processing */
export const orderStatusLabels: LabelMap = {
  processing: { label: '充值中', type: 'primary' },
  abnormal: { label: '异常', type: 'danger' },
  success: { label: '成功', type: 'success' },
  failed: { label: '失败（已退款）', type: 'warning' },
}

export const operatorLabels: LabelMap = {
  cmcc: { label: '移动' },
  cucc: { label: '联通' },
  ctcc: { label: '电信' },
  cbn: { label: '广电' },
}

export const balanceTypeLabels: LabelMap = {
  recharge: { label: '加款', type: 'success' },
  deduct: { label: '扣款', type: 'warning' },
  order_pay: { label: '下单扣款' },
  order_refund: { label: '失败退款', type: 'success' },
}

/** 加款申请、付款账号的审核状态 */
export const fundingStatusLabels: LabelMap = {
  pending: { label: '待审核', type: 'warning' },
  approved: { label: '已通过', type: 'success' },
  rejected: { label: '已驳回', type: 'danger' },
}

/** 付款账号、收款账户类型 */
export const paymentAccountTypeLabels: LabelMap = {
  alipay: { label: '支付宝' },
  bank: { label: '对公账户' },
}
