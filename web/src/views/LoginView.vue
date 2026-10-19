<script setup lang="ts">
import LoginPanel from '@/components/LoginPanel.vue'
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { login } from '@/api/auth'
import type { LoginForm } from '@/types'
import { useAuthStore } from '@/stores/auth'
import { usePermissionStore } from '@/stores/permission'
import { useSiteStore } from '@/stores/site'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const loading = ref(false)
const site = useSiteStore()

async function onSubmit(form: LoginForm) {
  loading.value = true
  try {
    const result = await login(form)
    auth.setSession(result.token, result.username)
    // 换了账号登录，权限要重新加载
    usePermissionStore().reset()
    const redirect = route.query.redirect
    await router.replace(typeof redirect === 'string' && redirect.startsWith('/') ? redirect : '/')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <LoginPanel :title="site.name" :loading="loading" @submit="onSubmit" />
</template>
