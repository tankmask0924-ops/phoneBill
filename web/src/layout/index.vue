<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { changePassword } from '@/api/auth'
import ChangePasswordDialog from '@/components/ChangePasswordDialog.vue'
import { useAuthStore } from '@/stores/auth'
import { usePermissionStore } from '@/stores/permission'
import { useSiteStore } from '@/stores/site'
import { useTodoStore } from '@/stores/todo'
import type { MenuItem } from '@/types'
import { filterMenus } from '@/utils/menus'
import AppLayout from './AppLayout.vue'
import { menus } from './menus'
import { useTodoReminder } from './todoReminder'

const auth = useAuthStore()
const permission = usePermissionStore()
const router = useRouter()
const site = useSiteStore()
const todo = useTodoStore()
const passwordDialog = ref(false)

/** 菜单上的待办角标 */
const badges = computed<Record<string, number>>(() => ({ '/merchant/funding-requests': todo.total }))

function withBadges(items: MenuItem[]): MenuItem[] {
  return items.map((item) => ({ ...item, badge: badges.value[item.path], children: item.children && withBadges(item.children) }))
}

// 权限加载完之前只显示不需要权限的菜单
const visibleMenus = computed(() => withBadges(filterMenus(menus, permission.can)))

useTodoReminder()

function logout() {
  auth.clear()
  permission.reset()
  router.push({ name: 'login' })
}

onMounted(() => permission.load())
</script>

<template>
  <AppLayout
    :title="site.name"
    :menus="visibleMenus"
    :username="auth.username"
    @logout="logout"
    @change-password="passwordDialog = true"
  />
  <ChangePasswordDialog v-model="passwordDialog" :submit="changePassword" @changed="auth.setToken" />
</template>
