import { defineStore } from 'pinia'
import { ref } from 'vue'
import { settingApi } from '@/api/funding'

/** 打包时的默认名称，后台没设置系统名称时用 */
const DEFAULT_NAME = import.meta.env.VITE_APP_TITLE

/** 系统名称（系统配置 - 基本设置）：左上角、登录页、浏览器标题都用它，打开页面时加载一次 */
export const useSiteStore = defineStore('phone-bill-admin-site', () => {
  const name = ref(DEFAULT_NAME)
  let loaded = false

  async function load() {
    if (loaded) {
      return
    }
    loaded = true
    try {
      set((await settingApi.publicInfo()).site_name)
    } catch {
      loaded = false
    }
  }

  /** 设置成空表示用默认名称 */
  function set(siteName: string) {
    name.value = siteName || DEFAULT_NAME
  }

  return { name, load, set }
})
