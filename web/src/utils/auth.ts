import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

/** 创建登录态 store，token 和用户名存在 localStorage 的 `${id}:*` 下 */
export function defineAuthStore(id: string) {
  const tokenKey = `${id}:token`
  const usernameKey = `${id}:username`

  return defineStore(id, () => {
    const token = ref<string | null>(localStorage.getItem(tokenKey))
    const username = ref(localStorage.getItem(usernameKey) ?? '')
    const isLoggedIn = computed(() => token.value !== null)

    function setSession(newToken: string, name: string) {
      token.value = newToken
      username.value = name
      localStorage.setItem(tokenKey, newToken)
      localStorage.setItem(usernameKey, name)
    }

    /** 改密码后换成新 token，用户名不变 */
    function setToken(newToken: string) {
      token.value = newToken
      localStorage.setItem(tokenKey, newToken)
    }

    function clear() {
      token.value = null
      username.value = ''
      localStorage.removeItem(tokenKey)
      localStorage.removeItem(usernameKey)
    }

    return { token, username, isLoggedIn, setSession, setToken, clear }
  })
}
