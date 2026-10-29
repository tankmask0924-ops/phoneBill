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
  /** 回调地址：系统配置的回调域名 + /notify/supplier/{编码}；没配回调域名时是以前手填的，可能为空 */
  notify_url: string | null
  /** 回调地址是否按回调域名自动生成 */
  notify_url_generated: boolean
  /** 非密钥参数；密钥不回显，已填过的列在 configured_secrets 里 */
  config: Record<string, string>
  configured_secrets: string[]
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
  remark: string
}

export const supplierApi = {
  meta: () => http.get<{ drivers: SupplierDriver[]; provinces: string[] }>('/admin/suppliers/meta'),
  list: (params: Query) => http.get<Paged<Supplier>>('/admin/suppliers', params),
  create: (data: SupplierForm) => http.post<Supplier>('/admin/suppliers', data),
  update: (id: number, data: Partial<SupplierForm>) => http.put<Supplier>(`/admin/suppliers/${id}`, data),
  changeStatus: (id: number, status: string) => http.post<Supplier>(`/admin/suppliers/${id}/status`, { status }),
  /** 软删除：名下还有供应商商品、或有充值中的订单时后端拒绝 */
  remove: (id: number) => http.delete<{ success: boolean }>(`/admin/suppliers/${id}`),
  /** 实时查余额；查询出错不报错，放在 error 里 */
  balance: (id: number) =>
    http.get<{ supported: boolean; balance: string | null; error: string | null }>(`/admin/suppliers/${id}/balance`),
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
  /** 覆盖的省份，['*'] 表示全国 */
  provinces: string[]
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
  provinces: string[]
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
  meta: () => http.get<{ provinces: string[] }>('/admin/supplier-products/meta'),
  supplierOptions: () => http.get<SupplierOption[]>('/admin/supplier-products/supplier-options'),
  upstreamProducts: (supplierId: number) =>
    http.get<UpstreamProduct[]>('/admin/supplier-products/upstream-products', { supplier_id: supplierId }),
  list: (params: Query) => http.get<Paged<SupplierProduct>>('/admin/supplier-products', params),
  create: (data: SupplierProductForm) => http.post<SupplierProduct>('/admin/supplier-products', data),
  update: (id: number, data: Partial<SupplierProductForm>) => http.put<SupplierProduct>(`/admin/supplier-products/${id}`, data),
  changeStatus: (id: number, status: string) => http.post<SupplierProduct>(`/admin/supplier-products/${id}/status`, { status }),
  /** 软删除：被平台商品绑定着、或有充值中的订单时后端拒绝 */
  remove: (id: number) => http.delete<{ success: boolean }>(`/admin/supplier-products/${id}`),
  /** 绑定了它的平台商品 */
  bindings: (id: number) => http.get<SupplierProductBinding[]>(`/admin/supplier-products/${id}/bindings`),
  /** 一键解绑（product.manage），返回解绑了几个平台商品 */
  unbind: (id: number) => http.delete<{ unbound: number }>(`/admin/supplier-products/${id}/bindings`),
}

export interface SupplierProductBinding {
  product_id: number
  code: string
  name: string
  status: string
  /** 这个供应商商品在该平台商品里的优先级 */
  priority: number
  /** 解绑后这个平台商品还剩几个绑定，0 表示商户下单会被拒绝 */
  remaining_routes: number
}

/** 商品分类：App\Controller\Admin\ProductCategoryController，只给平台商品用 */
export interface ProductCategory {
  id: number
  name: string
  /** 越小越靠前 */
  sort: number
  /** 号码限制（0 不限）：周期（自然日）内同一号码最多几单（所有商户合计、所有状态），同时最多几单充值中 */
  mobile_limit_days: number
  mobile_limit_orders: number
  mobile_limit_processing: number
  remark: string | null
  /** 用这个分类的平台商品数，大于 0 时不能删除 */
  product_count: number
  created_at: string | null
  updated_at: string | null
}

export interface ProductCategoryForm {
  name: string
  sort: number
  mobile_limit_days: number
  mobile_limit_orders: number
  mobile_limit_processing: number
  remark: string
}

export const productCategoryApi = {
  list: () => http.get<ProductCategory[]>('/admin/product-categories'),
  create: (data: ProductCategoryForm) => http.post<ProductCategory>('/admin/product-categories', data),
  update: (id: number, data: ProductCategoryForm) => http.put<ProductCategory>(`/admin/product-categories/${id}`, data),
  remove: (id: number) => http.delete<{ success: boolean }>(`/admin/product-categories/${id}`),
}

/** 平台商品：App\Controller\Admin\ProductController */
export interface ProductRoute {
  supplier_product_id: number
  priority: number
  supplier_product: SupplierProduct | null
}

export interface Product {
  id: number
  /** 老商品可能没有分类，编辑时补上 */
  category_id: number | null
  category_name: string | null
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
  category_id?: number
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
  /** 软删除：还有商户开通着、或有充值中的订单时后端拒绝 */
  remove: (id: number) => http.delete<{ success: boolean }>(`/admin/products/${id}`),
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

/** 省份统计：GET /admin/product-stats/provinces，每小时整点重算当天 */
export interface ProvinceStatTotals {
  order_count: number
  success_count: number
  failed_count: number
  /** 统计时还没出结果的（待提交、处理中、异常） */
  unfinished_count: number
  /** 成功 / (成功 + 失败) 的百分比，两位小数；没有出结果的单时为 null */
  success_rate: string | null
  /** 成功订单售价合计 */
  success_amount: string
  profit: string
}

export interface ProvinceSupplierStat extends ProvinceStatTotals {
  /** 0 = 没提交过供应商 */
  supplier_id: number
  supplier_name: string | null
}

export interface ProvinceStat extends ProvinceStatTotals {
  province: string
  suppliers: ProvinceSupplierStat[]
}

export interface ProvinceStatsResult {
  stat_date: string
  /** 这一天最近一次统计的时间，还没统计过为 null */
  computed_at: string | null
  summary: ProvinceStatTotals
  provinces: ProvinceStat[]
}

export const provinceStatsApi = {
  get: (params: Query) => http.get<ProvinceStatsResult>('/admin/product-stats/provinces', params),
}

/** 省份商品统计：GET /admin/product-stats/province-products，跟省份统计一起每小时整点重算当天 */
export interface ProvinceProductItemStat extends ProvinceStatTotals {
  product_id: number
  /** 商品不存在了为 null */
  product_code: string | null
  product_name: string | null
  face_value: number | null
  /** 同一商品不同运营商各一行 */
  operator: OperatorCode
}

export interface ProvinceProductStat extends ProvinceStatTotals {
  province: string
  products: ProvinceProductItemStat[]
}

export interface ProvinceProductStatsResult {
  stat_date: string
  /** 这一天最近一次统计的时间，还没统计过为 null */
  computed_at: string | null
  summary: ProvinceStatTotals
  provinces: ProvinceProductStat[]
}

export const provinceProductStatsApi = {
  get: (params: Query) => http.get<ProvinceProductStatsResult>('/admin/product-stats/province-products', params),
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
