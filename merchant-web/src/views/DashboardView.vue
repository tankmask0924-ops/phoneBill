<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { type Dashboard, portalApi } from '@/api/portal'

const data = ref<Dashboard | null>(null)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    data.value = await portalApi.dashboard()
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div v-loading="loading">
    <el-row :gutter="16">
      <el-col :span="6">
        <el-card shadow="never">
          <el-statistic title="当前余额（元）" :value="Number(data?.balance ?? 0)" :precision="2" />
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never">
          <el-statistic title="今日订单" :value="data?.today.order_count ?? 0">
            <template #suffix>
              <span class="sub">充值中 {{ data?.today.processing_count ?? 0 }}</span>
            </template>
          </el-statistic>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never">
          <el-statistic title="今日成功" :value="data?.today.success_count ?? 0">
            <template #suffix>
              <span class="sub">失败 {{ data?.today.failed_count ?? 0 }}</span>
            </template>
          </el-statistic>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never">
          <el-statistic title="今日成功金额（元）" :value="Number(data?.today.success_amount ?? 0)" :precision="2" />
        </el-card>
      </el-col>
    </el-row>
    <div class="actions">
      <el-button @click="load">刷新</el-button>
      <router-link to="/orders"><el-button type="primary" plain>查看订单记录</el-button></router-link>
    </div>
  </div>
</template>

<style scoped>
.sub {
  margin-left: 8px;
  color: #909399;
  font-size: 13px;
}

.actions {
  margin-top: 16px;
  display: flex;
  gap: 8px;
}
</style>
