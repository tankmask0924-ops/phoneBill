<script setup lang="ts">
import StatusTag from '@/components/StatusTag.vue'
import { Download } from '@element-plus/icons-vue'
import { formatDuration, recentDays } from '@/utils/date'
import { cleanParams, submitExport } from '@/utils/export'
import { usePagedList } from '@/utils/paged'
import { onMounted, ref } from 'vue'
import { type Order, type OrderDetail, type OrderFilterOptions, type OrderSummary, portalApi } from '@/api/portal'
import { balanceTypeLabels, labelOf, operatorLabels, orderStatusLabels, toOptions } from '@/utils/labels'

type Filters = {
  keyword: string
  mobile: string
  status: string
  operator: string
  province: string
  face_value: number | ''
  category_id: number | ''
  dates: [string, string] | null
}

/** 日期范围拆成 created_from / created_to */
function toParams(params: Record<string, unknown>): Record<string, unknown> {
  const { dates, ...rest } = params as Record<string, unknown> & { dates?: [string, string] | null }
  return dates ? { ...rest, created_from: dates[0], created_to: dates[1] } : rest
}

/** 当前筛选条件下全部订单的汇总，跟列表一起返回 */
const summary = ref<OrderSummary | null>(null)

const list = usePagedList<Order, Filters>(
  (params) =>
    portalApi.orders(toParams(params)).then((result) => {
      summary.value = result.summary
      return result
    }),
  { keyword: '', mobile: '', status: '', operator: '', province: '', face_value: '', category_id: '', dates: recentDays() },
  20,
)
const { rows, total, page, perPage, loading, filters } = list

const exporting = ref(false)

async function exportOrders() {
  exporting.value = true
  try {
    await submitExport('orders', toParams(cleanParams({ ...filters })))
  } finally {
    exporting.value = false
  }
}

const detailVisible = ref(false)
const detail = ref<OrderDetail | null>(null)

async function openDetail(row: Order) {
  detail.value = null
  detailVisible.value = true
  detail.value = await portalApi.order(row.id)
}

const options = ref<OrderFilterOptions>({ provinces: [], face_values: [], categories: [] })

onMounted(async () => {
  list.load()
  options.value = await portalApi.orderFilterOptions()
})
</script>

<template>
  <el-card shadow="never">
    <el-form inline @submit.prevent="list.search">
      <el-form-item>
        <el-input v-model="filters.keyword" placeholder="平台 / 商户订单号" clearable style="width: 200px" />
      </el-form-item>
      <el-form-item>
        <el-input
          v-model="filters.mobile"
          type="textarea"
          :autosize="{ minRows: 1, maxRows: 6 }"
          resize="none"
          placeholder="手机号，可批量粘贴（每行一个）"
          style="width: 220px"
        />
      </el-form-item>
      <el-form-item>
        <el-select v-model="filters.operator" placeholder="运营商" clearable style="width: 100px">
          <el-option v-for="o in toOptions(operatorLabels)" :key="o.value" v-bind="o" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-select v-model="filters.province" placeholder="地区" clearable filterable style="width: 110px">
          <el-option v-for="p in options.provinces" :key="p" :label="p" :value="p" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-select v-model="filters.face_value" placeholder="面值" clearable style="width: 100px">
          <el-option v-for="v in options.face_values" :key="v" :label="`${v} 元`" :value="v" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-select v-model="filters.category_id" placeholder="分类" clearable style="width: 120px">
          <el-option v-for="c in options.categories" :key="c.id" :label="c.name" :value="c.id" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-select v-model="filters.status" placeholder="全部状态" clearable style="width: 130px">
          <el-option v-for="o in toOptions(orderStatusLabels)" :key="o.value" v-bind="o" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-date-picker
          v-model="filters.dates"
          type="daterange"
          value-format="YYYY-MM-DD"
          start-placeholder="默认最近 7 天"
          end-placeholder="下单结束"
          style="width: 240px"
        />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" native-type="submit">查询</el-button>
        <el-button :icon="Download" :loading="exporting" @click="exportOrders">导出</el-button>
      </el-form-item>
    </el-form>

    <div v-if="summary" class="summary">
      <div class="summary-item">
        <div class="summary-label">订单数</div>
        <div class="summary-value">{{ summary.order_count }}</div>
        <div class="summary-sub">成功 {{ summary.success_count }} · 失败 {{ summary.failed_count }} · 充值中 {{ summary.processing_count }}</div>
      </div>
      <div class="summary-item">
        <div class="summary-label">成功率</div>
        <div class="summary-value">{{ summary.success_rate === null ? '-' : `${summary.success_rate}%` }}</div>
        <div class="summary-sub">成功 /（成功 + 失败）</div>
      </div>
      <div class="summary-item">
        <div class="summary-label">消费金额（元）</div>
        <div class="summary-value">{{ summary.amount }}</div>
        <div class="summary-sub">成功订单的扣款合计</div>
      </div>
      <div class="summary-item">
        <div class="summary-label">平均耗时</div>
        <div class="summary-value">{{ formatDuration(summary.avg_duration) }}</div>
        <div class="summary-sub">成功订单：下单到成功</div>
      </div>
    </div>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="order_no" label="平台订单号" width="210" />
      <el-table-column prop="merchant_order_no" label="商户订单号" min-width="160" />
      <el-table-column prop="product_name" label="商品" min-width="110" />
      <el-table-column label="号码" width="180">
        <template #default="{ row }">
          {{ row.mobile }}
          <span class="muted">{{ labelOf(operatorLabels, row.operator) }} {{ row.province }}</span>
        </template>
      </el-table-column>
      <el-table-column label="面值 / 扣款" width="120" align="right">
        <template #default="{ row }">{{ row.face_value }} / {{ row.amount }}</template>
      </el-table-column>
      <el-table-column label="状态" width="130">
        <template #default="{ row }"><StatusTag :map="orderStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column prop="created_at" label="下单时间" width="170" />
      <el-table-column label="完成时间" width="170">
        <template #default="{ row }">{{ row.finished_at ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="操作" width="80" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openDetail(row as Order)">详情</el-button>
        </template>
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

  <el-dialog v-model="detailVisible" title="订单详情" width="680px">
    <div v-loading="!detail">
      <template v-if="detail">
        <el-descriptions :column="2" border size="small">
          <el-descriptions-item label="平台订单号">{{ detail.order_no }}</el-descriptions-item>
          <el-descriptions-item label="商户订单号">{{ detail.merchant_order_no }}</el-descriptions-item>
          <el-descriptions-item label="商品">{{ detail.product_name }}（{{ detail.product_code }}）</el-descriptions-item>
          <el-descriptions-item label="号码">
            {{ detail.mobile }} {{ labelOf(operatorLabels, detail.operator) }} {{ detail.province }}
          </el-descriptions-item>
          <el-descriptions-item label="面值">{{ detail.face_value }} 元</el-descriptions-item>
          <el-descriptions-item label="扣款">{{ detail.amount }} 元</el-descriptions-item>
          <el-descriptions-item label="状态"><StatusTag :map="orderStatusLabels" :value="detail.status" /></el-descriptions-item>
          <el-descriptions-item label="完成时间">{{ detail.finished_at ?? '-' }}</el-descriptions-item>
          <el-descriptions-item v-if="detail.fail_reason" label="失败原因" :span="2">{{ detail.fail_reason }}</el-descriptions-item>
        </el-descriptions>
        <h4>资金变动</h4>
        <el-table :data="detail.balance_logs" border size="small">
          <el-table-column label="类型" width="120">
            <template #default="{ row }">{{ labelOf(balanceTypeLabels, row.type) }}</template>
          </el-table-column>
          <el-table-column prop="amount" label="金额（元）" width="120" align="right" />
          <el-table-column prop="balance_after" label="变动后余额" width="120" align="right" />
          <el-table-column prop="created_at" label="时间" />
        </el-table>
      </template>
    </div>
  </el-dialog>
</template>

<style scoped>
.summary {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}

.summary-item {
  padding: 12px 16px;
  background: #f5f7fa;
  border-radius: 4px;
}

.summary-label {
  color: #909399;
  font-size: 13px;
}

.summary-value {
  margin: 4px 0;
  font-size: 22px;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.summary-sub {
  color: #909399;
  font-size: 12px;
}

.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}

.muted {
  color: #909399;
  font-size: 12px;
}

h4 {
  margin: 20px 0 8px;
}
</style>
