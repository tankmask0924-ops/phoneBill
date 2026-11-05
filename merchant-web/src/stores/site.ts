import { defineStore } from 'pinia'
import { ref } from 'vue'
import { fetchSiteInfo } from '@/api/site'

/** 打包时的默认名称（本身带「商户后台」），管理后台没设置系统名称时用 */
const DEFAULT_NAME = import.meta.env.VITE_APP_TITLE

const SUFFIX = '商户后台'

/** 管理后台的系统名称后面加「商户后台」，已经以它结尾的不再加 */
function portalName(siteName: string): string {
  if (!siteName) {
    return DEFAULT_NAME
  }
  return siteName.endsWith(SUFFIX) ? siteName : `${siteName} ${SUFFIX}`
}

/** 系统名称：管理后台的系统名称 + 「商户后台」，左上角、登录页、浏览器标题都用它，打开页面时加载一次 */
export const useSiteStore = defineStore('phone-bill-merchant-site', () => {
  const name = ref(DEFAULT_NAME)
  let loaded = false

  async function load() {
    if (loaded) {
      return
    }
    loaded = true
    try {
      name.value = portalName((await fetchSiteInfo()).site_name)
    } catch {
      loaded = false
    }
  }

  return { name, load }
})
