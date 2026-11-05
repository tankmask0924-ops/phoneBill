import { defineStore } from 'pinia'
import { ref } from 'vue'
import { fetchSiteInfo } from '@/api/site'

/** 打包时的默认名称，管理后台没设置系统名称时用 */
const DEFAULT_NAME = import.meta.env.VITE_APP_TITLE

/** 系统名称：跟管理后台一致，左上角、登录页、浏览器标题都用它，打开页面时加载一次 */
export const useSiteStore = defineStore('phone-bill-merchant-site', () => {
  const name = ref(DEFAULT_NAME)
  let loaded = false

  async function load() {
    if (loaded) {
      return
    }
    loaded = true
    try {
      name.value = (await fetchSiteInfo()).site_name || DEFAULT_NAME
    } catch {
      loaded = false
    }
  }

  return { name, load }
})
