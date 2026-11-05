import { http } from './http'

/** 不用登录：系统名称，跟管理后台「系统配置 - 基本设置」一致（空字符串表示用默认名称） */
export const fetchSiteInfo = () => http.get<{ site_name: string }>('/merchant/site')
