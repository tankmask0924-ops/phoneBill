<script setup lang="ts">
import { usePagedList } from '@/utils/paged'
import { onMounted, ref } from 'vue'
import { type BalanceLog, balanceLogApi, merchantApi, type MerchantOption } from '@/api/merchant'
import { balanceTypeLabels, labelOf, toOptions } from '@/utils/labels'

const list = usePagedList<BalanceLog, { merchant_id: number | ''; type: string; dates: [string, string] | null }>(
  (params) => {
    // 日期范围拆成 created_from / created_to
    const { dates, ...rest } = params as Record<string, unknown> & { dates?: [string, string] }
    return balanceLogApi.list(dates ? { ...rest, created_from: dates[0], created_to: dates[1] } : rest)
  },
  { merchant_id: '', type: '', dates: null },
  20,
)
const { rows, total, page, perPage, loading, filters } = list

const merchants = ref<MerchantOption[]>([])

onMounted(async () => {
  list.load()
  merchants.value = await merchantApi.options()
})
</script>

<template>
  <el-card shadow="never">
    <el-form inline @submit.prevent="list.search">
      <el-form-item>
        <el-select v-model="filters.merchant_id" placeholder="全部商户" clearable filterable style="width: 180px">
          <el-option v-for="m in merchants" :key="m.id" :label="m.name" :value="m.id" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-select v-model="filters.type" placeholder="全部类型" clearable style="width: 120px">
          <el-option v-for="o in toOptions(balanceTypeLabels)" :key="o.value" v-bind="o" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-date-picker
          v-model="filters.dates"
          type="daterange"
          value-format="YYYY-MM-DD"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          style="width: 240px"
        />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" native-type="submit">查询</el-button>
      </el-form-item>
    </el-form>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="created_at" label="时间" width="170" />
      <el-table-column prop="merchant_name" label="商户" min-width="140" />
      <el-table-column label="类型" width="100">
        <template #default="{ row }">{{ labelOf(balanceTypeLabels, row.type) }}</template>
      </el-table-column>
      <el-table-column label="金额（元）" width="120" align="right">
        <template #default="{ row }">
          <span :class="row.amount.startsWith('-') ? 'minus' : 'plus'">{{ row.amount.startsWith('-') ? row.amount : `+${row.amount}` }}</span>
        </template>
      </el-table-column>
      <el-table-column prop="balance_after" label="变动后余额" width="120" align="right" />
      <el-table-column label="关联订单" width="210">
        <template #default="{ row }">{{ row.order_no ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="备注" min-width="160">
        <template #default="{ row }">{{ row.remark ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="操作人" width="110">
        <template #default="{ row }">{{ row.admin_name ?? (row.admin_user_id ? `#${row.admin_user_id}` : '系统') }}</template>
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
