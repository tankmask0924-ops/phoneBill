import { defineAuthStore } from '@/utils/auth'

/** 和管理端用不同的 localStorage 前缀，同一个浏览器两边各登各的 */
export const useAuthStore = defineAuthStore('phone-bill-merchant-auth')
