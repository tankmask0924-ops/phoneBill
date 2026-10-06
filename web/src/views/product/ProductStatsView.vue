<script setup lang="ts">
import { formatDuration } from '@/utils/date'
import { usePagedList } from '@/utils/paged'
import { onMounted, ref } from 'vue'
import { type ProductStat, productStatsApi, type StatSummary } from '@/api/product'
import { labelOf, operatorLabels, toOptions } from '@/utils/labels'

/** 默认最近 7 天，到昨天为止（今天的数据明天凌晨才汇总） */
function lastDays(days: number): [string, string] {
  const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  const today = new Date()
  return [
    ymd(new Date(today.getFullYear(), today.getMonth(), today.getDate() - days)),
    ymd(new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1)),
  ]
}

const summary = ref<(StatSummary & { unfinished_count: number }) | null>(null)

const list = usePagedList<ProductStat, { keyword: string; operator: string; dates: [string, string] | null }>(
  async (params) => {
    const { dates, ...rest } = params as Record<string, unknown> & { dates?: [string, string] }
    const result = await productStatsApi.list(dates ? { ...rest, date_from: dates[0], date_to: dates[1] } : rest)
    summary.value = result.summary
    return result
  },
  { keyword: '', operator: '', dates: lastDays(7) },
  20,
)
const { rows, total, page, perPage, loading, filters } = list

const rate = (value: string | null | undefined) => (value != null ? `${value}%` : '-')

onMounted(() => list.load())
</script>

<template>
  <el-card shadow="never">
    <el-form inline @submit.prevent="list.search">
      <el-form-item>
        <el-input v-model="filters.keyword" placeholder="商品名称 / 编码" clearable style="width: 170px" />
      </el-form-item>
      <el-form-item>
        <el-select v-model="filters.operator" placeholder="运营商" clearable style="width: 100px">
          <el-option v-for="o in toOptions(operatorLabels)" :key="o.value" v-bind="o" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-date-picker
          v-model="filters.dates"
          type="daterange"
          value-format="YYYY-MM-DD"
          start-placeholder="默认最近 7 天"
          end-placeholder="结束日期"
          style="width: 240px"
        />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" native-type="submit">查询</el-button>
      </el-form-item>
      <span class="muted">每天凌晨 3 点汇总前一天；订单按下单时间归到当天，成功率 = 成功 ÷（成功 + 失败）</span>
    </el-form>

    <el-descriptions v-if="summary" :column="6" border class="summary">
      <el-descriptions-item label="订单数">{{ summary.order_count }}</el-descriptions-item>
      <el-descriptions-item label="成功">{{ summary.success_count }}</el-descriptions-item>
      <el-descriptions-item label="失败">{{ summary.failed_count }}</el-descriptions-item>
      <el-descriptions-item label="未出结果">{{ summary.unfinished_count }}</el-descriptions-item>
      <el-descriptions-item label="成功率">{{ rate(summary.success_rate) }}</el-descriptions-item>
      <el-descriptions-item label="平均耗时">{{ formatDuration(summary.avg_duration) }}</el-descriptions-item>
    </el-descriptions>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="stat_date" label="日期" width="110" />
      <el-table-column label="商品" min-width="160">
        <template #default="{ row }">
          {{ row.product_name ?? `#${row.product_id}` }}
          <span class="muted">{{ row.product_code }}</span>
        </template>
      </el-table-column>
      <el-table-column label="运营商" width="80">
        <template #default="{ row }">{{ labelOf(operatorLabels, row.operator) }}</template>
      </el-table-column>
      <el-table-column prop="order_count" label="订单数" width="90" align="right" />
      <el-table-column prop="success_count" label="成功" width="80" align="right" />
      <el-table-column prop="failed_count" label="失败" width="80" align="right" />
      <el-table-column label="未出结果" width="90" align="right">
        <template #default="{ row }">
          <span :class="{ warn: row.unfinished_count > 0 }">{{ row.unfinished_count }}</span>
        </template>
      </el-table-column>
      <el-table-column label="成功率" width="90" align="right">
        <template #default="{ row }">{{ rate(row.success_rate) }}</template>
      </el-table-column>
      <el-table-column label="平均耗时" width="110" align="right">
        <template #default="{ row }">{{ formatDuration(row.avg_duration) }}</template>
      </el-table-column>
      <el-table-column label="中位数" width="110" align="right">
        <template #default="{ row }">{{ formatDuration(row.p50_duration) }}</template>
      </el-table-column>
      <el-table-column label="90% 在此内到账" width="130" align="right">
        <template #default="{ row }">{{ formatDuration(row.p90_duration) }}</template>
      </el-table-column>
    </el-table>

    <el-pagination
      v-model:current-page="page"
      v-model:page-size="perPage"
      class="pagination"
      layout="total, sizes, prev, pager, next"
      :total="total"
      @current-change="list.load"
      @size-change="list.search"
    />
  </el-card>
</template>

<style scoped>
.muted {
  color: #909399;
  font-size: 12px;
}

.summary {
  margin-bottom: 16px;
}

.warn {
  color: #e6a23c;
}

.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}
</style>
