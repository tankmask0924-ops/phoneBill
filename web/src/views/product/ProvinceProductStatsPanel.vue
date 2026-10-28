<script setup lang="ts">
import { recentDays } from '@/utils/date'
import { onMounted, reactive, ref } from 'vue'
import {
  type ProvinceProductItemStat,
  type ProvinceProductStatsResult,
  type ProvinceStatTotals,
  provinceProductStatsApi,
} from '@/api/product'
import { operatorLabels, toOptions } from '@/utils/labels'

const filters = reactive({ date: recentDays(1)[1], operator: '' })
const result = ref<ProvinceProductStatsResult | null>(null)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    result.value = await provinceProductStatsApi.get({ date: filters.date || undefined, operator: filters.operator || undefined })
  } finally {
    loading.value = false
  }
}

const rate = (value: string | null | undefined) => (value != null ? `${value}%` : '-')
const byNumber = (key: keyof ProvinceStatTotals) => (a: ProvinceStatTotals, b: ProvinceStatTotals) =>
  Number(a[key] ?? -1) - Number(b[key] ?? -1)
const productName = (row: ProvinceProductItemStat) => row.product_name ?? `#${row.product_id}`

onMounted(load)
</script>

<template>
  <div>
    <el-form inline @submit.prevent="load">
      <el-form-item>
        <el-date-picker
          v-model="filters.date"
          type="date"
          value-format="YYYY-MM-DD"
          placeholder="默认今天"
          style="width: 150px"
          @change="load"
        />
      </el-form-item>
      <el-form-item>
        <el-radio-group v-model="filters.operator" @change="load">
          <el-radio-button value="">全部</el-radio-button>
          <el-radio-button v-for="o in toOptions(operatorLabels)" :key="o.value" :value="o.value">{{ o.label }}</el-radio-button>
        </el-radio-group>
      </el-form-item>
      <el-form-item>
        <el-button type="primary" native-type="submit">查询</el-button>
      </el-form-item>
      <span class="muted">
        每小时整点重算当天，{{ result?.computed_at ? `数据更新于 ${result.computed_at}` : '这一天还没有统计数据' }}；
        成功金额是售价合计
      </span>
    </el-form>

    <el-descriptions v-if="result" :column="7" border class="summary">
      <el-descriptions-item label="订单数">{{ result.summary.order_count }}</el-descriptions-item>
      <el-descriptions-item label="成功">{{ result.summary.success_count }}</el-descriptions-item>
      <el-descriptions-item label="失败">{{ result.summary.failed_count }}</el-descriptions-item>
      <el-descriptions-item label="未出结果">{{ result.summary.unfinished_count }}</el-descriptions-item>
      <el-descriptions-item label="成功率">{{ rate(result.summary.success_rate) }}</el-descriptions-item>
      <el-descriptions-item label="成功金额（元）">{{ result.summary.success_amount }}</el-descriptions-item>
      <el-descriptions-item label="利润（元）">{{ result.summary.profit }}</el-descriptions-item>
    </el-descriptions>

    <el-table v-loading="loading" :data="result?.provinces ?? []" row-key="province" border>
      <el-table-column type="expand">
        <template #default="{ row }">
          <el-table :data="row.products" size="small" class="products">
            <el-table-column label="商品" min-width="200">
              <template #default="{ row: p }">
                {{ productName(p as ProvinceProductItemStat) }}
                <span class="muted">{{ p.product_code }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="face_value" label="面值" width="80" align="right" />
            <el-table-column prop="order_count" label="订单数" width="90" align="right" />
            <el-table-column prop="success_count" label="成功" width="80" align="right" />
            <el-table-column prop="failed_count" label="失败" width="80" align="right" />
            <el-table-column label="未出结果" width="90" align="right">
              <template #default="{ row: p }">
                <span :class="{ warn: p.unfinished_count > 0 }">{{ p.unfinished_count }}</span>
              </template>
            </el-table-column>
            <el-table-column label="成功率" width="90" align="right">
              <template #default="{ row: p }">{{ rate(p.success_rate) }}</template>
            </el-table-column>
            <el-table-column prop="success_amount" label="成功金额（元）" width="130" align="right" />
            <el-table-column prop="profit" label="利润（元）" width="110" align="right" />
          </el-table>
        </template>
      </el-table-column>
      <el-table-column prop="province" label="省份" width="90" />
      <el-table-column label="商品" min-width="160" show-overflow-tooltip>
        <template #default="{ row }">{{ row.products.map(productName).join('、') }}</template>
      </el-table-column>
      <el-table-column prop="order_count" label="订单数" width="100" align="right" sortable />
      <el-table-column prop="success_count" label="成功" width="80" align="right" />
      <el-table-column prop="failed_count" label="失败" width="80" align="right" />
      <el-table-column label="未出结果" width="90" align="right">
        <template #default="{ row }">
          <span :class="{ warn: row.unfinished_count > 0 }">{{ row.unfinished_count }}</span>
        </template>
      </el-table-column>
      <el-table-column label="成功率" width="100" align="right" sortable :sort-method="byNumber('success_rate')">
        <template #default="{ row }">{{ rate(row.success_rate) }}</template>
      </el-table-column>
      <el-table-column
        prop="success_amount"
        label="成功金额（元）"
        width="140"
        align="right"
        sortable
        :sort-method="byNumber('success_amount')"
      />
      <el-table-column prop="profit" label="利润（元）" width="120" align="right" sortable :sort-method="byNumber('profit')" />
    </el-table>
  </div>
</template>

<style scoped>
.muted {
  color: #909399;
  font-size: 12px;
}

.summary {
  margin-bottom: 16px;
}

.products {
  margin: 0 16px 0 48px;
  width: auto;
}

.warn {
  color: #e6a23c;
}
</style>
