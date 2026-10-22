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

/**
 * 按筛选条件下载 CSV。走 fetch 而不是 http 封装，因为要拿文件流。
 */
export async function downloadCsv(path: string, params: Record<string, unknown>, fallbackName: string): Promise<void> {
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value !== '' && value !== null && value !== undefined) {
      query.set(key, String(value))
    }
  }
  const response = await fetch(`${import.meta.env.VITE_API_BASE_URL}${path}?${query}`, {
    headers: { Authorization: `Bearer ${useAuthStore().token ?? ''}` },
  })
  if (!response.ok) {
    throw new Error((await response.text()) || '导出失败')
  }
  const blob = await response.blob()
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = response.headers.get('Content-Disposition')?.match(/filename="(.+)"/)?.[1] ?? fallbackName
  link.click()
  URL.revokeObjectURL(url)
}
