<script setup lang="ts">
import StatusTag from '@/components/StatusTag.vue'
import { usePagedList } from '@/utils/paged'
import { onMounted, ref } from 'vue'
import { type Order, type OrderDetail, portalApi } from '@/api/portal'
import { balanceTypeLabels, labelOf, operatorLabels, orderStatusLabels, toOptions } from '@/utils/labels'

const list = usePagedList<Order, { keyword: string; mobile: string; status: string; dates: [string, string] | null }>(
  (params) => {
    // 日期范围拆成 created_from / created_to
    const { dates, ...rest } = params as Record<string, unknown> & { dates?: [string, string] }
    return portalApi.orders(dates ? { ...rest, created_from: dates[0], created_to: dates[1] } : rest)
  },
  { keyword: '', mobile: '', status: '', dates: null },
  20,
)
const { rows, total, page, perPage, loading, filters } = list

const detailVisible = ref(false)
const detail = ref<OrderDetail | null>(null)

async function openDetail(row: Order) {
  detail.value = null
  detailVisible.value = true
  detail.value = await portalApi.order(row.id)
}

onMounted(list.load)
</script>

<template>
  <el-card shadow="never">
    <el-form inline @submit.prevent="list.search">
      <el-form-item>
        <el-input v-model="filters.keyword" placeholder="平台 / 商户订单号" clearable style="width: 200px" />
      </el-form-item>
      <el-form-item>
        <el-input v-model="filters.mobile" placeholder="手机号" clearable style="width: 140px" />
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
          start-placeholder="下单开始"
          end-placeholder="下单结束"
          style="width: 240px"
        />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" native-type="submit">查询</el-button>
      </el-form-item>
    </el-form>

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
