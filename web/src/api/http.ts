import router from '@/router'
import { useAuthStore } from '@/stores/auth'
import { createHttp } from '@/utils/http'

export const http = createHttp({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  getToken: () => useAuthStore().token,
  onUnauthorized: () => {
    useAuthStore().clear()
    router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } })
  },
})
