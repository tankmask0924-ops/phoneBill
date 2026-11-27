import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { fetchMe, type Me } from '@/api/auth'
import { useAuthStore } from './auth'

/**
 * 当前登录账号（/merchant/auth/me），按 token 缓存：换了账号登录会重新取。
 * 路由守卫靠它判断要不要先去绑定手机。
 */
export const useMeStore = defineStore('phone-bill-merchant-me', () => {
  const me = ref<Me | null>(null)
  let loadedFor: string | null = null

  /** 平台启用了短信、账号还没绑定手机：后端除了查自己、绑定手机、改密码，其他接口都返回 403 */
  const mobileRequired = computed(() => me.value !== null && me.value.sms_available && !me.value.mobile)

  async function load(force = false): Promise<Me> {
    const token = useAuthStore().token
    if (!force && me.value && loadedFor === token) {
      return me.value
    }
    me.value = await fetchMe()
    loadedFor = token
    return me.value
  }

  function setMobile(mobile: string) {
    if (me.value) {
      me.value.mobile = mobile
    }
  }

  function clear() {
    me.value = null
    loadedFor = null
  }

  return { me, mobileRequired, load, setMobile, clear }
})
