<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { changePassword } from '@/api/auth'
import ChangePasswordDialog from '@/components/ChangePasswordDialog.vue'
import { useAuthStore } from '@/stores/auth'
import { useMeStore } from '@/stores/me'
import { useSiteStore } from '@/stores/site'
import AppLayout from './AppLayout.vue'
import { menus } from './menus'

const auth = useAuthStore()
const router = useRouter()
const site = useSiteStore()
const passwordDialog = ref(false)
const meStore = useMeStore()
/** 路由守卫进来之前已经取过 */
const me = computed(() => meStore.me)

/** 右上角显示「商户名 · 账号」 */
const displayName = computed(() => (me.value ? `${me.value.merchant_name} · ${auth.username}` : auth.username))

function logout() {
  auth.clear()
  meStore.clear()
  router.push({ name: 'login' })
}
</script>

<template>
  <el-alert
    v-if="me?.merchant_status === 'disabled'"
    type="error"
    :closable="false"
    center
    title="你的商户已被停用，暂时不能下单，历史数据仍可查看。如有疑问请联系平台。"
  />
  <AppLayout
    :title="site.name"
    :menus="menus"
    :username="displayName"
    @logout="logout"
    @change-password="passwordDialog = true"
  />
  <ChangePasswordDialog v-model="passwordDialog" :submit="changePassword" @changed="auth.setToken" />
</template>
