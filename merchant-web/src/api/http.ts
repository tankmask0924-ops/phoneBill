import router from '@/router'
import { useAuthStore } from '@/stores/auth'
import { useMeStore } from '@/stores/me'
import { createHttp } from '@/utils/http'

export const http = createHttp({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  getToken: () => useAuthStore().token,
  onUnauthorized: () => {
    useAuthStore().clear()
    useMeStore().clear()
    router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } })
  },
})
