<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { type AdminDashboard, type AdminTodos, dashboardApi } from '@/api/dashboard'
import { useAuthStore } from '@/stores/auth'
import { usePermissionStore } from '@/stores/permission'
import { roleLabel } from '@/utils/labels'

const auth = useAuthStore()
const permission = usePermissionStore()

/** 汇总里有销售额和利润，跟订单列表一样要 order.view */
const canViewStats = computed(() => permission.can('order.view'))
/** 待办：待审核的付款账号、加款申请，要 funding.view */
const canViewTodos = computed(() => permission.can('funding.view'))
const router = useRouter()
const data = ref<AdminDashboard | null>(null)
const todos = ref<AdminTodos | null>(null)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    data.value = await dashboardApi.summary()
  } finally {
    loading.value = false
  }
}

/** 有待办时数字标橙色 */
function todoStyle(count: number | undefined) {
  return (count ?? 0) > 0 ? { color: '#e6a23c' } : undefined
}

async function loadTodos() {
  todos.value = await dashboardApi.todos()
}

/** 点待办卡片：打开加款申请页对应的页签（默认筛待审核） */
function openFunding(tab: 'requests' | 'payers') {
  router.push({ path: '/merchant/funding-requests', query: { tab } })
}

onMounted(async () => {
  await permission.load()
  if (canViewTodos.value) {
    loadTodos()
  }
  if (canViewStats.value) {
    load()
  }
})
</script>

<template>
  <el-row v-if="canViewTodos" :gutter="16" class="todos">
    <el-col :span="6">
      <el-card shadow="hover" class="todo" @click="openFunding('requests')">
        <el-statistic title="待审批加款" :value="todos?.pending_funding_requests ?? 0" :value-style="todoStyle(todos?.pending_funding_requests)">
          <template #suffix><span class="todo-link">去审批 ›</span></template>
        </el-statistic>
      </el-card>
    </el-col>
    <el-col :span="6">
      <el-card shadow="hover" class="todo" @click="openFunding('payers')">
        <el-statistic title="待审核付款账号" :value="todos?.pending_payer_accounts ?? 0" :value-style="todoStyle(todos?.pending_payer_accounts)">
          <template #suffix><span class="todo-link">去审核 ›</span></template>
        </el-statistic>
      </el-card>
    </el-col>
  </el-row>

  <div v-if="canViewStats" v-loading="loading" class="stats">
    <el-row :gutter="16">
      <el-col :span="6">
        <el-card shadow="never">
          <el-statistic title="总销售额（元）" :value="Number(data?.total.sales ?? 0)" :precision="2" />
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never">
          <el-statistic title="总商户数" :value="data?.total.merchant_count ?? 0" />
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never">
          <el-statistic title="总订单数" :value="data?.total.order_count ?? 0" />
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never">
          <el-statistic title="总利润（元）" :value="Number(data?.total.profit ?? 0)" :precision="2" />
        </el-card>
      </el-col>
    </el-row>
    <el-row :gutter="16" class="row">
      <el-col :span="6">
        <el-card shadow="never">
          <el-statistic title="今日订单" :value="data?.today.order_count ?? 0" />
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never">
          <el-statistic title="今日成功" :value="data?.today.success_count ?? 0" />
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never">
          <el-statistic title="今日销售（元）" :value="Number(data?.today.sales ?? 0)" :precision="2" />
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never">
          <el-statistic title="今日利润（元）" :value="Number(data?.today.profit ?? 0)" :precision="2" />
        </el-card>
      </el-col>
    </el-row>
    <div class="actions">
      <el-button @click="load(); canViewTodos && loadTodos()">刷新</el-button>
      <router-link to="/order/orders"><el-button type="primary" plain>查看订单</el-button></router-link>
      <span class="tip">销售额、利润只算成功的订单；累计数据每分钟更新一次</span>
    </div>
  </div>

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
.todos {
  margin-bottom: 16px;
}

.todo {
  cursor: pointer;
}

.todo-link {
  margin-left: 8px;
  color: #409eff;
  font-size: 13px;
}

.welcome {
  margin: 0 0 16px;
  font-size: 20px;
}

.stats {
  margin-bottom: 16px;
}

.row {
  margin-top: 16px;
}

.actions {
  margin-top: 16px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.tip {
  color: #909399;
  font-size: 13px;
}
</style>
