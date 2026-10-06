<script setup lang="ts">
import StatusTag from '@/components/StatusTag.vue'
import { recentDays } from '@/utils/date'
import { usePagedList } from '@/utils/paged'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Download } from '@element-plus/icons-vue'
import { computed, onMounted, ref } from 'vue'
import { downloadOrders, type Order, orderApi, type OrderDetail, type OrderFilterOptions } from '@/api/order'
import {
  attemptStatusLabels,
  balanceTypeLabels,
  labelOf,
  notifyStatusLabels,
  operatorLabels,
  orderStatusLabels,
  toOptions,
} from '@/utils/labels'
import { usePermissionStore } from '@/stores/permission'

/** 异常订单页：固定只看 abnormal */
const props = defineProps<{ abnormal?: boolean }>()

const permission = usePermissionStore()
const canManage = computed(() => permission.can('order.manage'))
const canReverse = computed(() => permission.can('order.reverse'))

type Filters = {
  keyword: string
  mobile: string
  merchant_id: number | ''
  supplier_id: number | ''
  status: string
  operator: string
  province: string
  dates: [string, string] | null
}

/** 日期范围拆成 created_from / created_to；异常订单页固定状态 */
function toParams(params: Record<string, unknown>): Record<string, unknown> {
  const { dates, ...rest } = params as Record<string, unknown> & { dates?: [string, string] }
  const result: Record<string, unknown> = dates ? { ...rest, created_from: dates[0], created_to: dates[1] } : rest
  if (props.abnormal) {
    result.status = 'abnormal'
  }
  return result
}

const list = usePagedList<Order, Filters>(
  (params) => orderApi.list(toParams(params)),
  // 异常订单页不限时间：异常订单不管多久都要处理
  { keyword: '', mobile: '', merchant_id: '', supplier_id: '', status: '', operator: '', province: '', dates: props.abnormal ? null : recentDays() },
  20,
)
const { rows, total, page, perPage, loading, filters } = list

const options = ref<OrderFilterOptions>({ merchants: [], suppliers: [], provinces: [] })
const exporting = ref(false)

async function exportCsv() {
  exporting.value = true
  try {
    await downloadOrders(toParams({ ...filters }))
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    exporting.value = false
  }
}

// ---- 详情与人工处理 ----
const detailVisible = ref(false)
const detail = ref<OrderDetail | null>(null)
const detailLoading = ref(false)
const acting = ref(false)

async function openDetail(row: Order) {
  detailVisible.value = true
  detail.value = null
  detailLoading.value = true
  try {
    detail.value = await orderApi.detail(row.id)
  } finally {
    detailLoading.value = false
  }
}

/** 执行一个人工操作，成功后刷新详情和列表 */
async function act(run: () => Promise<OrderDetail>, success: string) {
  acting.value = true
  try {
    detail.value = await run()
    ElMessage.success(success)
    await list.load()
  } finally {
    acting.value = false
  }
}

async function askRemark(title: string, message: string): Promise<string | null> {
  try {
    const result = await ElMessageBox.prompt(message, title, {
      type: 'warning',
      inputPlaceholder: '必填，例如供应商的核实结果',
      inputValidator: (v) => (!!v && v.trim() !== '') || '请填写备注',
    })
    return result.value.trim()
  } catch {
    return null
  }
}

async function query() {
  await act(() => orderApi.query(detail.value!.id), '已查单')
}

async function confirmSuccess() {
  const remark = await askRemark('置为成功', '确认供应商已经充值到账？订单将改为成功并通知商户，不退款。')
  if (remark) {
    await act(() => orderApi.confirmSuccess(detail.value!.id, remark), '已置为成功')
  }
}

async function confirmFailed() {
  const remark = await askRemark('置为失败', `确认没有充值到账？订单将改为失败，退给商户 ${detail.value!.sale_price} 元并通知商户。`)
  if (remark) {
    await act(() => orderApi.confirmFailed(detail.value!.id, remark), '已置为失败并退款')
  }
}

async function reverse() {
  const remark = await askRemark('冲正', `成功的订单将改为失败，退给商户 ${detail.value!.sale_price} 元并重新通知商户。请填写冲正原因：`)
  if (remark) {
    await act(() => orderApi.reverse(detail.value!.id, remark), '已冲正并退款')
  }
}

async function resendNotify() {
  await act(() => orderApi.resendNotify(detail.value!.id), '已重新推送通知')
}

onMounted(async () => {
  await permission.load()
  list.load()
  options.value = await orderApi.filterOptions()
})
</script>

<template>
  <el-card shadow="never">
    <el-alert
      v-if="props.abnormal"
      type="warning"
      :closable="false"
      show-icon
      class="tip-bar"
      title="下单超过 2 小时还没有结果的订单。请先到供应商后台核实，再在详情里「置为成功」或「置为失败」（失败会退款）。"
    />
    <el-form inline @submit.prevent="list.search">
      <el-form-item>
        <el-input v-model="filters.keyword" placeholder="平台 / 商户订单号" clearable style="width: 190px" />
      </el-form-item>
      <el-form-item>
        <el-input v-model="filters.mobile" placeholder="手机号" clearable style="width: 130px" />
      </el-form-item>
      <el-form-item>
        <el-select v-model="filters.merchant_id" placeholder="商户" clearable filterable style="width: 140px">
          <el-option v-for="m in options.merchants" :key="m.id" :label="m.name" :value="m.id" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-select v-model="filters.supplier_id" placeholder="成功的供应商" clearable filterable style="width: 140px">
          <el-option v-for="s in options.suppliers" :key="s.id" :label="s.name" :value="s.id" />
        </el-select>
      </el-form-item>
      <el-form-item v-if="!props.abnormal">
        <el-select v-model="filters.status" placeholder="状态" clearable style="width: 120px">
          <el-option v-for="o in toOptions(orderStatusLabels)" :key="o.value" v-bind="o" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-select v-model="filters.operator" placeholder="运营商" clearable style="width: 90px">
          <el-option v-for="o in toOptions(operatorLabels)" :key="o.value" v-bind="o" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-select v-model="filters.province" placeholder="省份" clearable filterable style="width: 100px">
          <el-option v-for="p in options.provinces" :key="p" :label="p" :value="p" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-date-picker
          v-model="filters.dates"
          type="daterange"
          value-format="YYYY-MM-DD"
          :start-placeholder="props.abnormal ? '下单开始' : '默认最近 7 天'"
          end-placeholder="下单结束"
          style="width: 240px"
        />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" native-type="submit">查询</el-button>
        <el-button :icon="Download" :loading="exporting" @click="exportCsv">导出</el-button>
      </el-form-item>
    </el-form>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="order_no" label="平台订单号" width="210" />
      <el-table-column prop="merchant_name" label="商户" min-width="110" />
      <el-table-column prop="merchant_order_no" label="商户订单号" min-width="150" />
      <el-table-column label="号码" width="170">
        <template #default="{ row }">
          {{ row.mobile }}
          <span class="muted">{{ labelOf(operatorLabels, row.operator) }} {{ row.province }}</span>
        </template>
      </el-table-column>
      <el-table-column label="面值 / 扣款" width="120" align="right">
        <template #default="{ row }">{{ row.face_value }} / {{ row.sale_price }}</template>
      </el-table-column>
      <el-table-column label="供应商" min-width="110">
        <template #default="{ row }">{{ row.supplier_name ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="状态" width="120">
        <template #default="{ row }"><StatusTag :map="orderStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column prop="created_at" label="下单时间" width="170" />
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

  <el-drawer v-model="detailVisible" title="订单详情" size="920px">
    <div v-loading="detailLoading || acting">
      <template v-if="detail">
        <div v-if="canManage || canReverse" class="actions">
          <el-button v-if="canManage && detail.attempts.some((a) => a.status === 'processing')" @click="query">向供应商查单</el-button>
          <template v-if="canManage && detail.status === 'abnormal'">
            <el-button type="success" @click="confirmSuccess">置为成功</el-button>
            <el-button type="danger" @click="confirmFailed">置为失败并退款</el-button>
          </template>
          <el-button v-if="canReverse && detail.status === 'success'" type="danger" plain @click="reverse">冲正</el-button>
          <el-button v-if="canManage && ['success', 'failed'].includes(detail.status)" @click="resendNotify">重发通知</el-button>
        </div>

        <el-descriptions :column="3" border size="small">
          <el-descriptions-item label="平台订单号">{{ detail.order_no }}</el-descriptions-item>
          <el-descriptions-item label="商户订单号">{{ detail.merchant_order_no }}</el-descriptions-item>
          <el-descriptions-item label="状态"><StatusTag :map="orderStatusLabels" :value="detail.status" /></el-descriptions-item>
          <el-descriptions-item label="商户">{{ detail.merchant_name }}</el-descriptions-item>
          <el-descriptions-item label="商品">{{ detail.product_name }}（{{ detail.product_code }}）</el-descriptions-item>
          <el-descriptions-item label="号码">
            {{ detail.mobile }} {{ labelOf(operatorLabels, detail.operator) }} {{ detail.province }}
          </el-descriptions-item>
          <el-descriptions-item label="面值">{{ detail.face_value }} 元</el-descriptions-item>
          <el-descriptions-item label="扣款">{{ detail.sale_price }} 元</el-descriptions-item>
          <el-descriptions-item label="成本">{{ detail.cost_price ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="下单时间">{{ detail.created_at }}</el-descriptions-item>
          <el-descriptions-item label="完成时间">{{ detail.finished_at ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="通知">
            <StatusTag :map="notifyStatusLabels" :value="detail.notify_status" />
          </el-descriptions-item>
          <el-descriptions-item v-if="detail.fail_reason" label="失败原因" :span="3">{{ detail.fail_reason }}</el-descriptions-item>
        </el-descriptions>

        <h4>供应商提交</h4>
        <el-table :data="detail.attempts" border size="small" empty-text="还没有提交给供应商">
          <el-table-column type="expand">
            <template #default="{ row }">
              <div class="raw">
                <div><b>请求：</b>{{ row.request ?? '-' }}</div>
                <div><b>响应：</b>{{ row.response ?? '-' }}</div>
                <div><b>最后查单：</b>{{ row.last_queried_at ?? '-' }}</div>
              </div>
            </template>
          </el-table-column>
          <el-table-column prop="attempt_no" label="提交单号" width="200" />
          <el-table-column label="供应商 / 商品" min-width="160">
            <template #default="{ row }">{{ row.supplier_name }} · {{ row.supplier_product_name }}</template>
          </el-table-column>
          <el-table-column prop="cost_price" label="成本" width="80" align="right" />
          <el-table-column label="状态" width="90">
            <template #default="{ row }"><StatusTag :map="attemptStatusLabels" :value="row.status" /></template>
          </el-table-column>
          <el-table-column label="说明" min-width="160">
            <template #default="{ row }">{{ row.message ?? '-' }}</template>
          </el-table-column>
          <el-table-column prop="submitted_at" label="提交时间" width="160" />
        </el-table>

        <h4>资金变动</h4>
        <el-table :data="detail.balance_logs" border size="small">
          <el-table-column label="类型" width="120">
            <template #default="{ row }">{{ labelOf(balanceTypeLabels, row.type) }}</template>
          </el-table-column>
          <el-table-column prop="amount" label="金额" width="120" align="right" />
          <el-table-column prop="balance_after" label="变动后余额" width="120" align="right" />
          <el-table-column prop="created_at" label="时间" />
        </el-table>

        <h4>通知商户</h4>
        <el-table :data="detail.notify_logs" border size="small" empty-text="还没有通知">
          <el-table-column prop="attempt_no" label="第几次" width="70" align="center" />
          <el-table-column prop="created_at" label="时间" width="160" />
          <el-table-column label="结果" width="80">
            <template #default="{ row }">
              <el-tag :type="row.success ? 'success' : 'danger'" size="small">{{ row.success ? '送达' : '失败' }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="HTTP" width="70">
            <template #default="{ row }">{{ row.http_status ?? '-' }}</template>
          </el-table-column>
          <el-table-column label="商户响应" min-width="200">
            <template #default="{ row }">{{ row.response ?? '-' }}</template>
          </el-table-column>
        </el-table>
      </template>
    </div>
  </el-drawer>
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

.tip-bar {
  margin-bottom: 16px;
}

.actions {
  display: flex;
  gap: 8px;
  margin-bottom: 16px;
}

.actions .el-button + .el-button {
  margin-left: 0;
}

h4 {
  margin: 20px 0 8px;
}

.raw {
  padding: 0 16px;
  font-size: 12px;
  word-break: break-all;
  line-height: 20px;
}
</style>
