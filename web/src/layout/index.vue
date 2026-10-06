<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { changePassword } from '@/api/auth'
import ChangePasswordDialog from '@/components/ChangePasswordDialog.vue'
import { useAuthStore } from '@/stores/auth'
import { usePermissionStore } from '@/stores/permission'
import { filterMenus } from '@/utils/menus'
import AppLayout from './AppLayout.vue'
import { menus } from './menus'

const auth = useAuthStore()
const permission = usePermissionStore()
const router = useRouter()
const title = import.meta.env.VITE_APP_TITLE
const passwordDialog = ref(false)

// 权限加载完之前只显示不需要权限的菜单
const visibleMenus = computed(() => filterMenus(menus, permission.can))

function logout() {
  auth.clear()
  permission.reset()
  router.push({ name: 'login' })
}

onMounted(() => permission.load())
</script>

<template>
  <AppLayout
    :title="title"
    :menus="visibleMenus"
    :username="auth.username"
    @logout="logout"
    @change-password="passwordDialog = true"
  />
  <ChangePasswordDialog v-model="passwordDialog" :submit="changePassword" @changed="auth.setToken" />
</template>
