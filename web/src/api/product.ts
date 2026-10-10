import type { Paged } from '@/utils/paged'
import { http } from './http'

type Query = Record<string, unknown>

/** 运营商编码：cmcc 移动 / cucc 联通 / ctcc 电信 / cbn 广电，见 App\Enum\Operator */
export type OperatorCode = 'cmcc' | 'cucc' | 'ctcc' | 'cbn'

/** 供应商驱动声明的接口参数，见 App\Supplier\SupplierDriverInterface::configSchema() */
export interface ConfigField {
  key: string
  label: string
  type: 'text' | 'secret' | 'select'
  required: boolean
  placeholder?: string
  options?: { value: string; label: string }[]
}

export interface SupplierDriver {
  code: string
  name: string
  config_schema: ConfigField[]
}

/** 供应商：App\Controller\Admin\SupplierController */
export interface Supplier {
  id: number
  name: string
  code: string
  driver: string
  driver_name: string
  /** 非密钥参数；密钥不回显，已填过的列在 configured_secrets 里 */
  config: Record<string, string>
  configured_secrets: string[]
  /** 省份简称，['*'] 表示全国 */
  provinces: string[]
  status: string
  remark: string | null
  product_count: number
  created_at: string | null
  updated_at: string | null
}

export interface SupplierForm {
  name: string
  code?: string
  driver: string
  config: Record<string, string>
  provinces: string[]
  remark: string
}

export const supplierApi = {
  meta: () => http.get<{ drivers: SupplierDriver[]; provinces: string[] }>('/admin/suppliers/meta'),
  list: (params: Query) => http.get<Paged<Supplier>>('/admin/suppliers', params),
  create: (data: SupplierForm) => http.post<Supplier>('/admin/suppliers', data),
  update: (id: number, data: Partial<SupplierForm>) => http.put<Supplier>(`/admin/suppliers/${id}`, data),
  changeStatus: (id: number, status: string) => http.post<Supplier>(`/admin/suppliers/${id}/status`, { status }),
}

/** 供应商商品：App\Controller\Admin\SupplierProductController */
export interface SupplierProduct {
  id: number
  supplier_id: number
  supplier_name: string | null
  supplier_status: string | null
  name: string
  face_value: number
  cost_price: string
  external_code: string
  operators: OperatorCode[]
  status: string
  remark: string | null
  bound_product_count: number
  created_at: string | null
  updated_at: string | null
}

export interface SupplierProductForm {
  supplier_id?: number
  name: string
  face_value: number
  cost_price: string
  external_code: string
  operators: OperatorCode[]
  remark: string
}

export interface SupplierOption {
  id: number
  name: string
  status: string
  /** 驱动支持查询上游商品，建供应商商品时可以从列表里选 */
  supports_upstream_products: boolean
}

/** 上游可售商品，上游没给的字段为 null / 空数组 */
export interface UpstreamProduct {
  code: string
  name: string
  price: string | null
  face_value: number | null
  operators: OperatorCode[]
  on_sale: boolean
  note: string | null
  /** 本供应商下已经建过这个编码的供应商商品 */
  added: boolean
}

export const supplierProductApi = {
  supplierOptions: () => http.get<SupplierOption[]>('/admin/supplier-products/supplier-options'),
  upstreamProducts: (supplierId: number) =>
    http.get<UpstreamProduct[]>('/admin/supplier-products/upstream-products', { supplier_id: supplierId }),
  list: (params: Query) => http.get<Paged<SupplierProduct>>('/admin/supplier-products', params),
  create: (data: SupplierProductForm) => http.post<SupplierProduct>('/admin/supplier-products', data),
  update: (id: number, data: Partial<SupplierProductForm>) => http.put<SupplierProduct>(`/admin/supplier-products/${id}`, data),
  changeStatus: (id: number, status: string) => http.post<SupplierProduct>(`/admin/supplier-products/${id}/status`, { status }),
}

/** 平台商品：App\Controller\Admin\ProductController */
export interface ProductRoute {
  supplier_product_id: number
  priority: number
  supplier_product: SupplierProduct | null
}

export interface Product {
  id: number
  code: string
  name: string
  face_value: number
  status: string
  remark: string | null
  /** 覆盖的运营商，由绑定的供应商商品汇总 */
  operators: OperatorCode[]
  /** 运营商 => 默认售价 */
  prices: Partial<Record<OperatorCode, string>>
  routes: ProductRoute[]
  /** 配置提醒，不影响保存 */
  warnings: string[]
  /** 昨天各运营商合起来的统计，没有订单时为 null */
  yesterday_stats: StatSummary | null
  created_at: string | null
  updated_at: string | null
}

export interface ProductForm {
  code?: string
  name: string
  face_value?: number
  remark: string
  routes: { supplier_product_id: number; priority: number }[]
  prices: Partial<Record<OperatorCode, string>>
}

export const productApi = {
  list: (params: Query) => http.get<Paged<Product>>('/admin/products', params),
  supplierProductOptions: (faceValue: number) =>
    http.get<SupplierProduct[]>('/admin/products/supplier-product-options', { face_value: faceValue }),
  create: (data: ProductForm) => http.post<Product>('/admin/products', data),
  update: (id: number, data: Partial<ProductForm>) => http.put<Product>(`/admin/products/${id}`, data),
  changeStatus: (id: number, status: string) => http.post<Product>(`/admin/products/${id}/status`, { status }),
}

/** 商品统计：App\Controller\Admin\ProductStatsController，每天凌晨汇总前一天 */
export interface StatSummary {
  order_count: number
  success_count: number
  failed_count: number
  /** 成功 / (成功 + 失败) 的百分比，两位小数；没有出结果的单时为 null */
  success_rate: string | null
  /** 成功订单平均耗时（秒） */
  avg_duration: number | null
}

export interface ProductStat extends StatSummary {
  stat_date: string
  product_id: number
  product_code: string | null
  product_name: string | null
  face_value: number | null
  operator: OperatorCode
  unfinished_count: number
  p50_duration: number | null
  p90_duration: number | null
  computed_at: string
}

export const productStatsApi = {
  list: (params: Query) =>
    http.get<Paged<ProductStat> & { summary: StatSummary & { unfinished_count: number } }>('/admin/product-stats', params),
}

/** 号段查询：App\Controller\Admin\SegmentController */
export interface RouteCandidate {
  supplier_product_id: number
  supplier_product_name: string
  external_code: string
  cost_price: string
  priority: number
  supplier_id: number
  supplier_name: string
}

export interface SegmentLookup {
  mobile: string
  segment: {
    segment: string
    operator: OperatorCode
    operator_name: string
    province: string
    city: string | null
    is_virtual: boolean
  } | null
  /** 不为空时表示这个号码下单会被拒绝 */
  rejected_reason: string | null
  products: { id: number; code: string; name: string; face_value: number; candidates: RouteCandidate[] }[]
}

export const segmentApi = {
  lookup: (mobile: string) => http.get<SegmentLookup>('/admin/segments/lookup', { mobile }),
}
