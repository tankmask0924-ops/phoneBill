<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { type DashboardStats, dashboardApi } from '@/api/dashboard'
import type { ProvinceStatTotals } from '@/api/product'
import { recentDays } from '@/utils/date'
import { operatorLabels, toOptions } from '@/utils/labels'

type Dimension = 'days' | 'suppliers' | 'products' | 'provinces'

const filters = reactive({ dates: recentDays(7) as [string, string] | null, operator: '' })
const tab = ref<Dimension>('days')
const result = ref<DashboardStats | null>(null)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    result.value = await dashboardApi.stats({
      date_from: filters.dates?.[0],
      date_to: filters.dates?.[1],
      operator: filters.operator || undefined,
    })
  } finally {
    loading.value = false
  }
}

/** 日期选择器的快捷范围，都含今天（本月从 1 号起） */
const shortcuts = [
  { text: '今天', value: () => rangeOf(1) },
  { text: '昨天', value: () => [daysAgo(1), daysAgo(1)] },
  { text: '近 7 天', value: () => rangeOf(7) },
  { text: '近 30 天', value: () => rangeOf(30) },
  { text: '本月', value: () => [new Date(new Date().getFullYear(), new Date().getMonth(), 1), new Date()] },
]

function daysAgo(n: number): Date {
  const d = new Date()
  d.setDate(d.getDate() - n)
  return d
}

function rangeOf(days: number): [Date, Date] {
  return [daysAgo(days - 1), new Date()]
}

const labels: Record<Dimension, string> = { days: '日期', suppliers: '供应商', products: '商品', provinces: '省份' }

type StatRow = ProvinceStatTotals & Record<string, unknown>
const rows = computed<StatRow[]>(() => (result.value?.[tab.value] ?? []) as StatRow[])

/** 第一列显示的名字 */
function nameOf(row: StatRow): string {
  switch (tab.value) {
    case 'days':
      return String(row.stat_date)
    case 'suppliers':
      return row.supplier_id === 0 ? '未提交供应商' : String(row.supplier_name ?? `#${row.supplier_id}`)
    case 'products':
      return String(row.product_name ?? `#${row.product_id}`)
    default:
      return String(row.province)
  }
}

const rate = (value: string | null | undefined) => (value != null ? `${value}%` : '-')
const byNumber = (key: keyof ProvinceStatTotals) => (a: ProvinceStatTotals, b: ProvinceStatTotals) =>
  Number(a[key] ?? -1) - Number(b[key] ?? -1)

onMounted(load)
</script>

<template>
  <el-card shadow="never" class="stats-card">
    <template #header>
      <div class="header">
        <span class="title">经营统计</span>
        <el-date-picker
          v-model="filters.dates"
          type="daterange"
          value-format="YYYY-MM-DD"
          :shortcuts="shortcuts"
          :clearable="false"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          style="width: 240px"
          @change="load"
        />
        <el-radio-group v-model="filters.operator" @change="load">
          <el-radio-button value="">全部</el-radio-button>
          <el-radio-button v-for="o in toOptions(operatorLabels)" :key="o.value" :value="o.value">{{ o.label }}</el-radio-button>
        </el-radio-group>
        <span class="muted">
          每小时整点统计，{{ result?.computed_at ? `数据更新于 ${result.computed_at}` : '这段时间还没有统计数据' }}；最多选 92 天
        </span>
        <router-link to="/product/stats" class="more">商品统计 ›</router-link>
      </div>
    </template>

    <div v-loading="loading">
      <el-descriptions v-if="result" :column="7" border class="summary">
        <el-descriptions-item label="订单数">{{ result.summary.order_count }}</el-descriptions-item>
        <el-descriptions-item label="成功">{{ result.summary.success_count }}</el-descriptions-item>
        <el-descriptions-item label="失败">{{ result.summary.failed_count }}</el-descriptions-item>
        <el-descriptions-item label="未出结果">{{ result.summary.unfinished_count }}</el-descriptions-item>
        <el-descriptions-item label="成功率">{{ rate(result.summary.success_rate) }}</el-descriptions-item>
        <el-descriptions-item label="成功金额（元）">{{ result.summary.success_amount }}</el-descriptions-item>
        <el-descriptions-item label="利润（元）">{{ result.summary.profit }}</el-descriptions-item>
      </el-descriptions>

      <el-tabs v-model="tab">
        <el-tab-pane v-for="(label, key) in labels" :key="key" :label="`按${label}`" :name="key" />
      </el-tabs>

      <!-- 切页签时换 key，重置上一个页签的排序 -->
      <el-table :key="tab" :data="rows" border max-height="480">
        <el-table-column :label="labels[tab]" min-width="180" show-overflow-tooltip>
          <template #default="{ row }">
            {{ nameOf(row as StatRow) }}
            <span v-if="tab === 'products'" class="muted">{{ row.product_code }}</span>
          </template>
        </el-table-column>
        <el-table-column v-if="tab === 'products'" prop="face_value" label="面值" width="80" align="right" />
        <el-table-column prop="order_count" label="订单数" width="100" align="right" sortable />
        <el-table-column prop="success_count" label="成功" width="90" align="right" sortable />
        <el-table-column prop="failed_count" label="失败" width="90" align="right" sortable />
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
  </el-card>
</template>

<style scoped>
.stats-card {
  margin-top: 16px;
}

.header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
}

.title {
  font-size: 16px;
  font-weight: 600;
}

.more {
  margin-left: auto;
  color: #409eff;
  font-size: 13px;
  text-decoration: none;
}

.muted {
  color: #909399;
  font-size: 12px;
}

.summary {
  margin-bottom: 8px;
}

.warn {
  color: #e6a23c;
}
</style>
