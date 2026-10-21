<script setup lang="ts">
import { ArrowDown, Expand, Fold } from '@element-plus/icons-vue'
import { ref } from 'vue'
import { useRoute } from 'vue-router'
import type { MenuItem } from '@/types'

defineProps<{
  title: string
  menus: MenuItem[]
  username: string
}>()

const emit = defineEmits<{
  logout: []
  changePassword: []
}>()

const route = useRoute()
const collapsed = ref(false)

/** 分组收起时，子菜单有角标就在分组上显示一个红点 */
function hasBadge(item: MenuItem): boolean {
  return item.children?.some((child) => (child.badge ?? 0) > 0) ?? false
}
</script>

<template>
  <el-container class="layout">
    <el-aside :width="collapsed ? '64px' : '220px'" class="aside">
      <div class="logo">{{ collapsed ? title.slice(0, 1) : title }}</div>
      <el-menu
        :default-active="route.path"
        :collapse="collapsed"
        :collapse-transition="false"
        router
        background-color="#001529"
        text-color="#bfcbd9"
        active-text-color="#ffffff"
        class="menu"
      >
        <template v-for="item in menus" :key="item.path">
          <el-sub-menu v-if="item.children?.length" :index="item.path">
            <template #title>
              <el-icon v-if="item.icon"><component :is="item.icon" /></el-icon>
              <span>{{ item.title }}</span>
              <el-badge v-if="hasBadge(item)" is-dot class="group-dot" />
            </template>
            <el-menu-item v-for="child in item.children" :key="child.path" :index="child.path">
              <el-icon v-if="child.icon"><component :is="child.icon" /></el-icon>
              <template #title>
                {{ child.title }}
                <el-badge v-if="child.badge" :value="child.badge" :max="99" class="menu-badge" />
              </template>
            </el-menu-item>
          </el-sub-menu>
          <el-menu-item v-else :index="item.path">
            <el-icon v-if="item.icon"><component :is="item.icon" /></el-icon>
            <template #title>{{ item.title }}</template>
          </el-menu-item>
        </template>
      </el-menu>
    </el-aside>

    <el-container>
      <el-header class="header">
        <el-icon class="toggle" @click="collapsed = !collapsed">
          <Expand v-if="collapsed" />
          <Fold v-else />
        </el-icon>
        <span class="page-title">{{ route.meta.title }}</span>
        <el-dropdown trigger="click">
          <span class="user">
            {{ username }}
            <el-icon><ArrowDown /></el-icon>
          </span>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item @click="emit('changePassword')">修改密码</el-dropdown-item>
              <el-dropdown-item divided @click="emit('logout')">退出登录</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </el-header>

      <el-main class="main">
        <!-- 订单、异常订单共用一个页面组件：按路径重建，否则切换时沿用上一个页面的筛选条件、也不会重新查询 -->
        <router-view :key="$route.path" />
      </el-main>
    </el-container>
  </el-container>
</template>

<style scoped>
.layout {
  height: 100%;
}

.aside {
  background: #001529;
  overflow-x: hidden;
  transition: width 0.2s;
}

.logo {
  height: 60px;
  line-height: 60px;
  text-align: center;
  color: #fff;
  font-size: 18px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
}

.menu {
  border-right: none;
}

.menu-badge {
  margin-left: 8px;
  line-height: 1;
}

.menu-badge :deep(.el-badge__content) {
  border: none;
}

.group-dot {
  margin-left: 6px;
  line-height: 1;
}

.header {
  display: flex;
  align-items: center;
  gap: 16px;
  background: #fff;
  border-bottom: 1px solid #e4e7ed;
}

.toggle {
  font-size: 20px;
  cursor: pointer;
}

.page-title {
  flex: 1;
  font-size: 16px;
}

.user {
  display: flex;
  align-items: center;
  gap: 4px;
  cursor: pointer;
}

.main {
  padding: 20px;
}
</style>
