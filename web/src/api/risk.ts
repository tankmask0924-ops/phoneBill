import type { Paged } from '@/utils/paged'
import type { OperatorCode, SupplierOption } from './product'
import { http } from './http'

type Query = Record<string, unknown>

/** 号码黑名单：App\Controller\Admin\RiskController */
export interface BlacklistItem {
  id: number
  mobile: string
  reason: string | null
  admin_name: string | null
  created_at: string | null
}

/** 通道维护：App\Controller\Admin\RiskController */
export interface Maintenance {
  id: number
  supplier_id: number
  supplier_name: string | null
  /** 空表示所有运营商 */
  operator: OperatorCode | null
  /** 空表示所有省份 */
  province: string | null
  start_at: string
  end_at: string
  /** active 维护中 / upcoming 未开始 / ended 已结束 */
  state: string
  reason: string | null
  admin_name: string | null
  created_at: string | null
}

export const riskApi = {
  blacklist: (params: Query) => http.get<Paged<BlacklistItem>>('/admin/risk/blacklist', params),
  addBlacklist: (data: { mobiles: string; reason: string }) =>
    http.post<{ added: number; skipped: number }>('/admin/risk/blacklist', data),
  removeBlacklist: (id: number) => http.delete<{ success: boolean }>(`/admin/risk/blacklist/${id}`),
  maintenances: (params: Query) => http.get<Paged<Maintenance>>('/admin/risk/maintenances', params),
  maintenanceOptions: () => http.get<{ suppliers: SupplierOption[]; provinces: string[] }>('/admin/risk/maintenances/options'),
  addMaintenance: (data: {
    supplier_id: number
    operator: string
    province: string
    start_at: string
    end_at: string
    reason: string
  }) => http.post<Maintenance>('/admin/risk/maintenances', data),
  finishMaintenance: (id: number) => http.post<Maintenance>(`/admin/risk/maintenances/${id}/finish`),
}
