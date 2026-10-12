<script setup lang="ts">
import StatusTag from '@/components/StatusTag.vue'
import { formatDuration, recentDays } from '@/utils/date'
import { usePagedList } from '@/utils/paged'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Download } from '@element-plus/icons-vue'
import { computed, onMounted, ref } from 'vue'
import {
  downloadOrders,
  type Order,
  orderApi,
  type OrderDetail,
  type OrderFilterOptions,
  type OrderSummary,
  type ResupplyOption,
} from '@/api/order'
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

/** 当前筛选条件下全部订单的汇总，跟列表一起返回 */
const summary = ref<OrderSummary | null>(null)

const list = usePagedList<Order, Filters>(
  (params) =>
    orderApi.list(toParams(params)).then((result) => {
      summary.value = result.summary
      return result
    }),
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

/** 查单后告诉管理员供应商返回了什么、订单接下来怎么处理 */
async function query() {
  acting.value = true
  let result
  try {
    result = await orderApi.query(detail.value!.id)
    detail.value = result
    await list.load()
  } finally {
    acting.value = false
  }
  const lines = result.query_results.map((r) => {
    const reason = r.message ? `（${r.message}）` : ''
    if (r.status === 'success') {
      return `${r.attempt_no}：供应商确认成功${reason}`
    }
    if (r.status === 'failed') {
      return `${r.attempt_no}：供应商确认失败${reason}`
    }
    return `${r.attempt_no}：供应商还没有明确结果${reason}，订单状态不变`
  })
  let next = ''
  if (result.manual_only) {
    next = '这是人工处理单：上游结果只记录，订单状态不会自动变。请核实后置为成功 / 失败，再手动回调；确认上游没有充上也可以手动补单。'
  } else if (result.status === 'success') {
    next = '订单已改为成功，会通知商户。'
  } else if (result.status === 'abnormal' && result.query_results.some((r) => r.status === 'failed')) {
    next = '异常订单不会自动退款：请到供应商后台核实没有充值到账后，点「置为失败并退款」。'
  } else if (result.status === 'abnormal') {
    next = '订单仍是异常，可以稍后再查，或到供应商后台核实后人工处理。'
  }
  await ElMessageBox.alert([...lines, next].filter(Boolean).join('\n'), '查单结果', {
    type: result.status === 'success' ? 'success' : 'info',
    customStyle: { whiteSpace: 'pre-line' },
  }).catch(() => undefined)
}

/** 人工处理单置成功 / 失败后不自动通知商户 */
function notifyHint(): string {
  return detail.value!.manual_only ? '这是人工处理单，不会自动通知商户，处理完请点「手动回调」。' : '并通知商户。'
}

async function confirmSuccess() {
  const remark = await askRemark('置为成功', `确认供应商已经充值到账？订单将改为成功，不退款，${notifyHint()}`)
  if (remark) {
    await act(() => orderApi.confirmSuccess(detail.value!.id, remark), '已置为成功')
  }
}

async function confirmFailed() {
  const remark = await askRemark('置为失败', `确认没有充值到账？订单将改为失败，退给商户 ${detail.value!.sale_price} 元，${notifyHint()}`)
  if (remark) {
    await act(() => orderApi.confirmFailed(detail.value!.id, remark), '已置为失败并退款')
  }
}

async function reverse() {
  const remark = await askRemark('冲正', `成功的订单将改为失败，退给商户 ${detail.value!.sale_price} 元，${notifyHint()}请填写冲正原因：`)
  if (remark) {
    await act(() => orderApi.reverse(detail.value!.id, remark), '已冲正并退款')
  }
}

async function manualNotify() {
  const d = detail.value!
  const result = d.status === 'success' ? '成功' : `失败（${d.fail_reason ?? ''}）`
  try {
    await ElMessageBox.confirm(
      `将把「${result}」推送给商户（没收到 success 会按间隔重试）。回调之后，上游再来的结果都只记录、不会再推给商户。确定回调吗？`,
      '手动回调',
      { type: 'warning' },
    )
  } catch {
    return
  }
  await act(() => orderApi.manualNotify(d.id), '已推送给商户')
}

// ---- 手动补单 ----
const resupplyVisible = ref(false)
const resupplyOptions = ref<ResupplyOption[]>([])
const resupplyForm = ref<{ supplier_product_id: number | undefined; remark: string }>({ supplier_product_id: undefined, remark: '' })

async function openResupply() {
  resupplyOptions.value = await orderApi.resupplyOptions(detail.value!.id)
  if (resupplyOptions.value.length === 0) {
    ElMessage.warning('这笔订单现在没有能走的供应商商品（可能都下架、停用或在维护）')
    return
  }
  // 默认选还没试过的第一个，都试过就选第一个
  const first = resupplyOptions.value.find((o) => !o.tried) ?? resupplyOptions.value[0]
  resupplyForm.value = { supplier_product_id: first.supplier_product_id, remark: '' }
  resupplyVisible.value = true
}

async function resupply() {
  const { supplier_product_id: spId, remark } = resupplyForm.value
  if (!spId || !remark.trim()) {
    ElMessage.warning('请选择供应商商品并填写备注')
    return
  }
  resupplyVisible.value = false
  await act(() => orderApi.resupply(detail.value!.id, spId, remark.trim()), '已补单，正在提交给供应商，稍后点「刷新」看结果')
}

/** 只重新读取详情，看处理进度和耗时 */
async function refreshDetail() {
  detailLoading.value = true
  try {
    detail.value = await orderApi.detail(detail.value!.id)
  } finally {
    detailLoading.value = false
  }
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
      title="两种情况会进这里：① 提交给供应商时接口异常（标「人工」）：上游结果只记录，状态和回调全部人工，置为成功 / 失败后要再点「手动回调」商户才会知道；② 下单超过 2 小时还没有结果：上游后来明确成功的会自动改为成功并通知商户。上游确认没有充上的，可以「手动补单」重新提交（同一笔订单，不重复扣款）。"
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

    <div v-if="!props.abnormal && summary" class="summary">
      <div class="summary-item">
        <div class="summary-label">订单数</div>
        <div class="summary-value">{{ summary.order_count }}</div>
        <div class="summary-sub">成功 {{ summary.success_count }} · 失败 {{ summary.failed_count }} · 处理中 {{ summary.processing_count }}</div>
      </div>
      <div class="summary-item">
        <div class="summary-label">成功率</div>
        <div class="summary-value">{{ summary.success_rate === null ? '-' : `${summary.success_rate}%` }}</div>
        <div class="summary-sub">成功 /（成功 + 失败）</div>
      </div>
      <div class="summary-item">
        <div class="summary-label">成本（元）</div>
        <div class="summary-value">{{ summary.cost }}</div>
        <div class="summary-sub">成功订单扣款 {{ summary.amount }}</div>
      </div>
      <div class="summary-item">
        <div class="summary-label">利润（元）</div>
        <div class="summary-value" :class="summary.profit.startsWith('-') ? 'loss' : 'gain'">{{ summary.profit }}</div>
        <div class="summary-sub">成功订单的扣款 − 成本</div>
      </div>
      <div class="summary-item">
        <div class="summary-label">平均耗时</div>
        <div class="summary-value">{{ formatDuration(summary.avg_duration) }}</div>
        <div class="summary-sub">成功订单：下单到成功</div>
      </div>
    </div>

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
      <el-table-column label="成本 / 利润" width="130" align="right">
        <template #default="{ row }">
          <template v-if="row.cost_price">
            {{ row.cost_price }} /
            <span :class="row.profit && row.profit.startsWith('-') ? 'loss' : 'gain'">{{ row.profit ?? '-' }}</span>
          </template>
          <span v-else class="muted">-</span>
        </template>
      </el-table-column>
      <el-table-column label="供应商" min-width="110">
        <template #default="{ row }">{{ row.supplier_name ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="状态" width="120">
        <template #default="{ row }">
          <StatusTag :map="orderStatusLabels" :value="row.status" />
          <el-tag v-if="row.manual_only" type="danger" size="small" class="manual-tag">人工</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="created_at" label="下单时间" width="170" />
      <el-table-column label="耗时" width="100" align="right">
        <template #default="{ row }">{{ formatDuration(row.duration) }}</template>
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

  <el-drawer v-model="detailVisible" title="订单详情" size="920px">
    <div v-loading="detailLoading || acting">
      <template v-if="detail">
        <div class="actions">
          <el-button @click="refreshDetail">刷新</el-button>
          <el-button v-if="canManage && detail.attempts.some((a) => a.status === 'processing')" @click="query">向供应商查单</el-button>
          <template v-if="canManage && detail.status === 'abnormal'">
            <el-button type="success" @click="confirmSuccess">置为成功</el-button>
            <el-button type="danger" @click="confirmFailed">置为失败并退款</el-button>
          </template>
          <el-button v-if="canReverse && detail.status === 'success'" type="danger" plain @click="reverse">冲正</el-button>
          <el-button v-if="canManage && detail.status === 'abnormal'" type="primary" plain @click="openResupply">手动补单</el-button>
          <el-button v-if="canManage && ['success', 'failed'].includes(detail.status)" type="primary" plain @click="manualNotify">手动回调</el-button>
        </div>

        <el-alert v-for="w in detail.warnings" :key="w" type="error" :closable="false" show-icon :title="w" class="warning" />

        <el-descriptions :column="3" border size="small">
          <el-descriptions-item label="平台订单号">{{ detail.order_no }}</el-descriptions-item>
          <el-descriptions-item label="商户订单号">{{ detail.merchant_order_no }}</el-descriptions-item>
          <el-descriptions-item label="状态">
            <StatusTag :map="orderStatusLabels" :value="detail.status" />
            <el-tag v-if="detail.manual_only" type="danger" size="small" class="manual-tag">人工处理单</el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="商户">{{ detail.merchant_name }}</el-descriptions-item>
          <el-descriptions-item label="商品">{{ detail.product_name }}（{{ detail.product_code }}）</el-descriptions-item>
          <el-descriptions-item label="号码">
            {{ detail.mobile }} {{ labelOf(operatorLabels, detail.operator) }} {{ detail.province }}
          </el-descriptions-item>
          <el-descriptions-item label="面值">{{ detail.face_value }} 元</el-descriptions-item>
          <el-descriptions-item label="扣款">{{ detail.sale_price }} 元</el-descriptions-item>
          <el-descriptions-item label="成本 / 利润">
            {{ detail.cost_price ?? '-' }} /
            <span :class="detail.profit && detail.profit.startsWith('-') ? 'loss' : 'gain'">{{ detail.profit ?? '-' }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="下单时间">{{ detail.created_at }}</el-descriptions-item>
          <el-descriptions-item label="完成时间">{{ detail.finished_at ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="耗时">{{ formatDuration(detail.duration) }}</el-descriptions-item>
          <el-descriptions-item label="通知">
            <StatusTag :map="notifyStatusLabels" :value="detail.notify_status" />
            <span v-if="detail.manual_notified_at" class="muted">（{{ detail.manual_notified_at }} 手动回调）</span>
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
          <el-table-column label="供应商耗时" width="100" align="right">
            <template #default="{ row }">{{ formatDuration(row.duration) }}</template>
          </el-table-column>
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

  <el-dialog v-model="resupplyVisible" title="手动补单" width="620px">
    <el-alert type="warning" :closable="false" show-icon class="warning">
      补单会把这笔订单（同一个订单号、不重复扣款）重新提交给下面选的供应商商品。
      <b>请先到上游后台确认原来的提交确实没有充上</b>，否则会重复充值。原来还在处理中的提交会记为「人工确认上游未成功」。
    </el-alert>
    <el-form label-width="96px">
      <el-form-item label="供应商商品">
        <el-select v-model="resupplyForm.supplier_product_id" style="width: 100%">
          <el-option
            v-for="o in resupplyOptions"
            :key="o.supplier_product_id"
            :value="o.supplier_product_id"
            :label="`${o.supplier_name} · ${o.supplier_product_name} · 成本 ${o.cost_price}${o.tried ? '（这笔订单提交过）' : ''}`"
          />
        </el-select>
      </el-form-item>
      <el-form-item label="备注" required>
        <el-input v-model="resupplyForm.remark" type="textarea" :rows="2" maxlength="200" placeholder="必填，如：已在上游后台确认没有这笔单" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="resupplyVisible = false">取消</el-button>
      <el-button type="primary" @click="resupply">确认补单</el-button>
    </template>
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

.tip-bar {
  margin-bottom: 16px;
}

.actions {
  display: flex;
  gap: 8px;
  margin-bottom: 16px;
}

.warning {
  margin-bottom: 12px;
}

.manual-tag {
  margin-left: 4px;
}

.gain {
  color: #67c23a;
}

.summary {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}

.summary-item {
  padding: 12px 16px;
  background: #f5f7fa;
  border-radius: 4px;
}

.summary-label,
.summary-sub {
  color: #909399;
  font-size: 12px;
}

.summary-value {
  margin: 4px 0;
  font-size: 22px;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.loss {
  color: #f56c6c;
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
