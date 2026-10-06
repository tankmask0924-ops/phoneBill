<script setup lang="ts">
import { onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { usePermissionStore } from '@/stores/permission'
import { roleLabel } from '@/utils/labels'

const auth = useAuthStore()
const permission = usePermissionStore()

onMounted(() => permission.load())
</script>

<template>
  <el-card shadow="never">
    <h2 class="welcome">欢迎回来，{{ permission.me?.real_name ?? auth.username }}</h2>
    <el-descriptions v-if="permission.me" :column="3" border>
      <el-descriptions-item label="账号">{{ permission.me.username }}</el-descriptions-item>
      <el-descriptions-item label="角色">{{ roleLabel(permission.me.role_name) }}</el-descriptions-item>
      <el-descriptions-item label="权限数">
        {{ permission.me.is_super_admin ? '全部' : permission.me.permissions.length }}
      </el-descriptions-item>
    </el-descriptions>
  </el-card>
</template>

<style scoped>
.welcome {
  margin: 0 0 16px;
  font-size: 20px;
}
</style>
