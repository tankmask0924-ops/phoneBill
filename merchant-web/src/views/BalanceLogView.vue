<script setup lang="ts">
import { Download } from '@element-plus/icons-vue'
import { recentDays } from '@/utils/date'
import { cleanParams, submitExport } from '@/utils/export'
import { usePagedList } from '@/utils/paged'
import { onMounted, ref } from 'vue'
import { type BalanceLog, portalApi } from '@/api/portal'
import { balanceTypeLabels, labelOf, toOptions } from '@/utils/labels'

/** 按订单号查时不限日期 */
function toParams(params: Record<string, unknown>): Record<string, unknown> {
  const { dates, ...rest } = params as Record<string, unknown> & { dates?: [string, string] | null; order_no?: string }
  return dates && !rest.order_no ? { ...rest, created_from: dates[0], created_to: dates[1] } : rest
}

const list = usePagedList<BalanceLog, { order_no: string; type: string; dates: [string, string] | null }>(
  (params) => portalApi.balanceLogs(toParams(params)),
  { order_no: '', type: '', dates: recentDays() },
  20,
)
const { rows, total, page, perPage, loading, filters } = list

const exporting = ref(false)

async function exportLogs() {
  exporting.value = true
  try {
    await submitExport('balance_logs', toParams(cleanParams({ ...filters })))
  } finally {
    exporting.value = false
  }
}

onMounted(list.load)
</script>

<template>
  <el-card shadow="never">
    <el-form inline @submit.prevent="list.search">
      <el-form-item>
        <el-input v-model.trim="filters.order_no" placeholder="平台 / 商户订单号（不限日期）" clearable style="width: 230px" />
      </el-form-item>
      <el-form-item>
        <el-select v-model="filters.type" placeholder="全部类型" clearable style="width: 130px">
          <el-option v-for="o in toOptions(balanceTypeLabels)" :key="o.value" v-bind="o" />
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
        <el-button :icon="Download" :loading="exporting" @click="exportLogs">导出</el-button>
      </el-form-item>
    </el-form>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="created_at" label="时间" width="170" />
      <el-table-column label="类型" width="110">
        <template #default="{ row }">{{ labelOf(balanceTypeLabels, row.type) }}</template>
      </el-table-column>
      <el-table-column label="金额（元）" width="130" align="right">
        <template #default="{ row }">
          <span :class="row.amount.startsWith('-') ? 'minus' : 'plus'">{{ row.amount.startsWith('-') ? row.amount : `+${row.amount}` }}</span>
        </template>
      </el-table-column>
      <el-table-column prop="balance_after" label="变动后余额" width="130" align="right" />
      <el-table-column label="平台订单号" width="210">
        <template #default="{ row }">{{ row.order_no ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="商户订单号" min-width="160">
        <template #default="{ row }">{{ row.merchant_order_no ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="手机号" width="120">
        <template #default="{ row }">{{ row.mobile ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="备注" min-width="140">
        <template #default="{ row }">{{ row.remark ?? '-' }}</template>
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
.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}

.plus {
  color: #67c23a;
}

.minus {
  color: #f56c6c;
}
</style>
