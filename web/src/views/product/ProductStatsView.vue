<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { usePermissionStore } from '@/stores/permission'
import ProductDailyStatsPanel from './ProductDailyStatsPanel.vue'
import ProvinceProductStatsPanel from './ProvinceProductStatsPanel.vue'
import ProvinceStatsPanel from './ProvinceStatsPanel.vue'

const permission = usePermissionStore()
const tab = ref('daily')

/** 省份统计、省份商品统计里有成功金额和利润，跟订单列表一样要 order.view */
const canViewProvinces = computed(() => permission.can('order.view'))

onMounted(() => permission.load())
</script>

<template>
  <el-card shadow="never">
    <el-tabs v-model="tab">
      <el-tab-pane label="商品日统计" name="daily">
        <ProductDailyStatsPanel />
      </el-tab-pane>
      <el-tab-pane v-if="canViewProvinces" label="省份统计" name="provinces" lazy>
        <ProvinceStatsPanel />
      </el-tab-pane>
      <el-tab-pane v-if="canViewProvinces" label="省份商品统计" name="province-products" lazy>
        <ProvinceProductStatsPanel />
      </el-tab-pane>
    </el-tabs>
  </el-card>
</template>
