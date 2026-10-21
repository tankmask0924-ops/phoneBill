<script setup lang="ts">
import StatusTag from '@/components/StatusTag.vue'
import { recentDays } from '@/utils/date'
import { usePagedList } from '@/utils/paged'
import { ElMessage, ElMessageBox } from 'element-plus'
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import {
  type FundingRequest,
  type FundingRequestDetail,
  fundingApi,
  type OnlinePayment,
  type PayerAccount,
  type PaymentAccountInfo,
  type TransferCandidate,
} from '@/api/funding'
import { fundingStatusLabels, onlinePaymentStatusLabels, toOptions } from '@/utils/labels'
import { usePermissionStore } from '@/stores/permission'
import { useTodoStore } from '@/stores/todo'

const permission = usePermissionStore()
const canReview = computed(() => permission.can('funding.review'))
/** 审核完马上刷新菜单角标、首页卡片的待办数量 */
const todoStore = useTodoStore()

type Tab = 'requests' | 'online' | 'payers'
const TABS: Tab[] = ['requests', 'online', 'payers']
// 首页待办卡片带 ?tab=payers 之类直接打开对应的页签（列表默认就是筛待审核的）
const route = useRoute()
const tabFromQuery = (): Tab => (TABS.includes(route.query.tab as Tab) ? (route.query.tab as Tab) : 'requests')
const tab = ref<Tab>(tabFromQuery())
watch(() => route.query.tab, () => (tab.value = tabFromQuery()))
const merchants = ref<{ id: number; name: string }[]>([])

function accountText(a: PaymentAccountInfo): string {
  return [a.type_label, a.account_name, a.account_no, a.bank_name].filter(Boolean).join(' · ')
}

// ---- 加款申请 ----
type RequestFilters = { status: string; merchant_id: number | ''; dates: [string, string] | null }
const requests = usePagedList<FundingRequest, RequestFilters>(
  (params) => {
    // 看待审核的不限日期，免得漏审
    const { dates, ...rest } = params as Record<string, unknown> & { dates?: [string, string] }
    return fundingApi.requests(dates && rest.status !== 'pending' ? { ...rest, created_from: dates[0], created_to: dates[1] } : rest)
  },
  { status: 'pending', merchant_id: '', dates: recentDays() },
  20,
)

const detailVisible = ref(false)
const detail = ref<FundingRequestDetail | null>(null)
const detailLoading = ref(false)
const acting = ref(false)

async function openDetail(row: FundingRequest) {
  detailVisible.value = true
  detail.value = null
  detailLoading.value = true
  try {
    detail.value = await fundingApi.request(row.id)
  } finally {
    detailLoading.value = false
  }
}

async function approveRequest() {
  const d = detail.value!
  let remark = ''
  try {
    const result = await ElMessageBox.prompt(
      `确认已收到「${d.merchant_name}」打来的 ${d.amount} 元？通过后按这个金额给商户加款，不能撤回。`,
      '审核通过',
      { type: 'warning', inputPlaceholder: '备注（选填），如银行流水号', confirmButtonText: '确认入账' },
    )
    remark = result.value?.trim() ?? ''
  } catch {
    return
  }
  await reviewRequest(() => fundingApi.approveRequest(d.id, remark), '已入账')
}

async function rejectRequest() {
  const d = detail.value!
  let remark = ''
  try {
    const result = await ElMessageBox.prompt('请填写驳回原因，商户能看到：', '驳回', {
      type: 'warning',
      inputPlaceholder: '如：没有收到款 / 金额和凭证不一致',
      inputValidator: (v) => (!!v && v.trim() !== '') || '请填写驳回原因',
    })
    remark = result.value.trim()
  } catch {
    return
  }
  await reviewRequest(() => fundingApi.rejectRequest(d.id, remark), '已驳回')
}

async function reviewRequest(run: () => Promise<FundingRequestDetail>, success: string) {
  acting.value = true
  try {
    detail.value = await run()
    ElMessage.success(success)
    todoStore.refresh(true).catch(() => {})
    await requests.load()
  } finally {
    acting.value = false
  }
}

// ---- 付款账号审核 ----
const payers = usePagedList<PayerAccount, { status: string; merchant_id: number | '' }>(fundingApi.payerAccounts, {
  status: 'pending',
  merchant_id: '',
}, 20)

async function approvePayer(row: PayerAccount) {
  try {
    await ElMessageBox.confirm(`确认「${row.merchant_name}」的付款账号 ${accountText(row)} 无误？`, '审核通过', { type: 'warning' })
  } catch {
    return
  }
  await fundingApi.approvePayer(row.id)
  ElMessage.success('已通过')
  todoStore.refresh(true).catch(() => {})
  await payers.load()
}

async function rejectPayer(row: PayerAccount) {
  let reason = ''
  try {
    const result = await ElMessageBox.prompt('请填写驳回原因，商户能看到：', '驳回', {
      type: 'warning',
      inputPlaceholder: '如：户名和商户主体不一致',
      inputValidator: (v) => (!!v && v.trim() !== '') || '请填写驳回原因',
    })
    reason = result.value.trim()
  } catch {
    return
  }
  await fundingApi.rejectPayer(row.id, reason)
  ElMessage.success('已驳回')
  todoStore.refresh(true).catch(() => {})
  await payers.load()
}

// ---- 在线支付（支付宝扫码转账） ----
type OnlineFilters = { status: string; merchant_id: number | ''; keyword: string; dates: [string, string] | null }
const online = usePagedList<OnlinePayment, OnlineFilters>(
  (params) => {
    // 待支付的、按单号查的不限日期
    const { dates, ...rest } = params as Record<string, unknown> & { dates?: [string, string] }
    return fundingApi.onlinePayments(dates && rest.status !== 'pending' && !rest.keyword ? { ...rest, created_from: dates[0], created_to: dates[1] } : rest)
  },
  { status: '', merchant_id: '', keyword: '', dates: recentDays() },
  20,
)

const paymentVisible = ref(false)
const payment = ref<OnlinePayment | null>(null)
const candidates = ref<TransferCandidate[] | null>(null)
const paymentBusy = ref(false)

function openPayment(row: OnlinePayment) {
  payment.value = row
  candidates.value = null
  paymentVisible.value = true
}

async function findCandidates() {
  paymentBusy.value = true
  try {
    candidates.value = await fundingApi.onlinePaymentCandidates(payment.value!.id)
  } finally {
    paymentBusy.value = false
  }
}

async function confirmPayment(candidate: TransferCandidate | null) {
  const p = payment.value!
  let remark = ''
  try {
    const text = candidate
      ? `按流水 ${candidate.account_log_id}（${candidate.trans_at ?? '-'} 收到 ${candidate.amount} 元）给「${p.merchant_name}」入账 ${p.pay_amount} 元？`
      : `没有选到账记录，确认已在支付宝核实收到「${p.merchant_name}」的 ${p.pay_amount} 元？入账后不能撤回。`
    const result = await ElMessageBox.prompt(text, '确认入账', {
      type: 'warning',
      inputPlaceholder: candidate ? '备注（选填）' : '必填：在哪里核实的到账，如支付宝账单流水号',
      inputValidator: (v) => !!candidate || (!!v && v.trim() !== '') || '请写明在哪里核实的到账',
      confirmButtonText: '确认入账',
    })
    remark = result.value?.trim() ?? ''
  } catch {
    return
  }
  paymentBusy.value = true
  try {
    payment.value = await fundingApi.confirmOnlinePayment(p.id, { account_log_id: candidate?.account_log_id, remark })
    candidates.value = null
    ElMessage.success('已入账')
    await online.load()
  } finally {
    paymentBusy.value = false
  }
}

onMounted(async () => {
  await permission.load()
  requests.load()
  online.load()
  payers.load()
  merchants.value = await fundingApi.merchantOptions()
})
</script>

<template>
  <el-card shadow="never">
    <el-tabs v-model="tab">
      <el-tab-pane label="加款申请" name="requests">
        <el-form inline @submit.prevent="requests.search">
          <el-form-item>
            <el-select v-model="requests.filters.status" placeholder="全部状态" clearable style="width: 110px">
              <el-option v-for="o in toOptions(fundingStatusLabels)" :key="o.value" v-bind="o" />
            </el-select>
          </el-form-item>
          <el-form-item>
            <el-select v-model="requests.filters.merchant_id" placeholder="全部商户" clearable filterable style="width: 180px">
              <el-option v-for="m in merchants" :key="m.id" :label="m.name" :value="m.id" />
            </el-select>
          </el-form-item>
          <el-form-item>
            <el-date-picker
              v-model="requests.filters.dates"
              type="daterange"
              value-format="YYYY-MM-DD"
              start-placeholder="提交日期（待审核不限）"
              end-placeholder="结束日期"
              style="width: 260px"
            />
          </el-form-item>
          <el-form-item>
            <el-button type="primary" native-type="submit">查询</el-button>
          </el-form-item>
        </el-form>

        <el-table v-loading="requests.loading.value" :data="requests.rows.value" border>
          <el-table-column prop="id" label="ID" width="70" />
          <el-table-column prop="merchant_name" label="商户" min-width="130" />
          <el-table-column label="金额（元）" width="120" align="right">
            <template #default="{ row }">{{ row.amount }}</template>
          </el-table-column>
          <el-table-column label="付款账号" min-width="200">
            <template #default="{ row }">{{ accountText(row.payer) }}</template>
          </el-table-column>
          <el-table-column label="收款账户" min-width="200">
            <template #default="{ row }">{{ accountText(row.receiving) }}</template>
          </el-table-column>
          <el-table-column prop="paid_at" label="打款时间" width="170" />
          <el-table-column label="状态" width="90">
            <template #default="{ row }"><StatusTag :map="fundingStatusLabels" :value="row.status" /></template>
          </el-table-column>
          <el-table-column prop="created_at" label="提交时间" width="170" />
          <el-table-column label="操作" width="90" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" @click="openDetail(row as FundingRequest)">
                {{ canReview && row.status === 'pending' ? '审核' : '详情' }}
              </el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-pagination
          v-model:current-page="requests.page.value"
          v-model:page-size="requests.perPage.value"
          class="pagination"
          layout="total, sizes, prev, pager, next"
          :total="requests.total.value"
          @current-change="requests.load"
          @size-change="requests.search"
        />
      </el-tab-pane>

      <el-tab-pane label="在线支付" name="online">
        <el-form inline @submit.prevent="online.search">
          <el-form-item>
            <el-select v-model="online.filters.status" placeholder="全部状态" clearable style="width: 110px">
              <el-option v-for="o in toOptions(onlinePaymentStatusLabels)" :key="o.value" v-bind="o" />
            </el-select>
          </el-form-item>
          <el-form-item>
            <el-select v-model="online.filters.merchant_id" placeholder="全部商户" clearable filterable style="width: 180px">
              <el-option v-for="m in merchants" :key="m.id" :label="m.name" :value="m.id" />
            </el-select>
          </el-form-item>
          <el-form-item>
            <el-input v-model="online.filters.keyword" placeholder="支付单号" clearable style="width: 210px" />
          </el-form-item>
          <el-form-item>
            <el-date-picker
              v-model="online.filters.dates"
              type="daterange"
              value-format="YYYY-MM-DD"
              start-placeholder="发起日期（待支付不限）"
              end-placeholder="结束日期"
              style="width: 260px"
            />
          </el-form-item>
          <el-form-item>
            <el-button type="primary" native-type="submit">查询</el-button>
          </el-form-item>
        </el-form>

        <el-table v-loading="online.loading.value" :data="online.rows.value" border>
          <el-table-column prop="order_no" label="支付单号" width="210" />
          <el-table-column prop="merchant_name" label="商户" min-width="130" />
          <el-table-column label="收款账户" min-width="130">
            <template #default="{ row }">{{ row.alipay_account_name ?? '-' }}</template>
          </el-table-column>
          <el-table-column label="应付 / 输入金额" width="140" align="right">
            <template #default="{ row }">
              <b>{{ row.pay_amount }}</b>
              <div class="muted">{{ row.amount }}</div>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="90">
            <template #default="{ row }"><StatusTag :map="onlinePaymentStatusLabels" :value="row.status" /></template>
          </el-table-column>
          <el-table-column label="到账" min-width="200">
            <template #default="{ row }">
              <template v-if="row.status === 'paid'">
                {{ row.confirm_type === 'auto' ? '自动' : `后台确认（${row.confirmer_name ?? '-'}）` }} · {{ row.paid_at }}
                <div v-if="row.payer_account" class="muted">付款方 {{ row.payer_account }}</div>
              </template>
              <span v-else class="muted">-</span>
            </template>
          </el-table-column>
          <el-table-column prop="created_at" label="发起时间" width="170" />
          <el-table-column label="操作" width="90" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" @click="openPayment(row as OnlinePayment)">
                {{ canReview && row.status !== 'paid' ? '处理' : '详情' }}
              </el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-pagination
          v-model:current-page="online.page.value"
          v-model:page-size="online.perPage.value"
          class="pagination"
          layout="total, sizes, prev, pager, next"
          :total="online.total.value"
          @current-change="online.load"
          @size-change="online.search"
        />
      </el-tab-pane>

      <el-tab-pane label="付款账号审核" name="payers">
        <el-form inline @submit.prevent="payers.search">
          <el-form-item>
            <el-select v-model="payers.filters.status" placeholder="全部状态" clearable style="width: 110px">
              <el-option v-for="o in toOptions(fundingStatusLabels)" :key="o.value" v-bind="o" />
            </el-select>
          </el-form-item>
          <el-form-item>
            <el-select v-model="payers.filters.merchant_id" placeholder="全部商户" clearable filterable style="width: 180px">
              <el-option v-for="m in merchants" :key="m.id" :label="m.name" :value="m.id" />
            </el-select>
          </el-form-item>
          <el-form-item>
            <el-button type="primary" native-type="submit">查询</el-button>
          </el-form-item>
        </el-form>

        <el-table v-loading="payers.loading.value" :data="payers.rows.value" border>
          <el-table-column prop="merchant_name" label="商户" min-width="130" />
          <el-table-column prop="type_label" label="类型" width="90" />
          <el-table-column prop="account_name" label="户名 / 实名" min-width="140" />
          <el-table-column prop="account_no" label="账号" min-width="180" />
          <el-table-column label="开户行" min-width="140">
            <template #default="{ row }">{{ row.bank_name ?? '-' }}</template>
          </el-table-column>
          <el-table-column label="状态" width="90">
            <template #default="{ row }"><StatusTag :map="fundingStatusLabels" :value="row.status" /></template>
          </el-table-column>
          <el-table-column label="审核" min-width="160">
            <template #default="{ row }">
              <template v-if="row.reviewed_at">
                {{ row.reviewer_name ?? '-' }} · {{ row.reviewed_at }}
                <div v-if="row.reject_reason" class="muted">{{ row.reject_reason }}</div>
              </template>
              <span v-else class="muted">-</span>
            </template>
          </el-table-column>
          <el-table-column prop="updated_at" label="提交 / 修改时间" width="170" />
          <el-table-column v-if="canReview" label="操作" width="120" fixed="right">
            <template #default="{ row }">
              <template v-if="row.status === 'pending'">
                <el-button link type="success" @click="approvePayer(row as PayerAccount)">通过</el-button>
                <el-button link type="danger" @click="rejectPayer(row as PayerAccount)">驳回</el-button>
              </template>
            </template>
          </el-table-column>
        </el-table>
        <el-pagination
          v-model:current-page="payers.page.value"
          v-model:page-size="payers.perPage.value"
          class="pagination"
          layout="total, sizes, prev, pager, next"
          :total="payers.total.value"
          @current-change="payers.load"
          @size-change="payers.search"
        />
      </el-tab-pane>
    </el-tabs>
  </el-card>

  <el-drawer v-model="paymentVisible" title="在线支付（支付宝扫码转账）" size="760px">
    <div v-if="payment" v-loading="paymentBusy">
      <el-descriptions :column="2" border size="small">
        <el-descriptions-item label="支付单号">{{ payment.order_no }}</el-descriptions-item>
        <el-descriptions-item label="商户">{{ payment.merchant_name }}</el-descriptions-item>
        <el-descriptions-item label="应付金额">
          <b>{{ payment.pay_amount }}</b> 元（输入 {{ payment.amount }}）
        </el-descriptions-item>
        <el-descriptions-item label="状态">
          <StatusTag :map="onlinePaymentStatusLabels" :value="payment.status" />
        </el-descriptions-item>
        <el-descriptions-item label="收款账号" :span="2">
          {{ payment.alipay_account_name ?? '-' }}：{{ payment.receive_account }} · {{ payment.receive_name }}
        </el-descriptions-item>
        <el-descriptions-item label="发起时间">{{ payment.created_at }}</el-descriptions-item>
        <el-descriptions-item label="二维码到期">{{ payment.expires_at }}</el-descriptions-item>
        <el-descriptions-item label="自动核对到" :span="2">{{ payment.checking_until }}</el-descriptions-item>
        <template v-if="payment.status === 'paid'">
          <el-descriptions-item label="入账">
            {{ payment.confirm_type === 'auto' ? '自动核对' : `后台确认（${payment.confirmer_name ?? '-'}）` }} · {{ payment.paid_at }}
          </el-descriptions-item>
          <el-descriptions-item label="支付宝入账时间">{{ payment.trans_at ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="账务流水号">{{ payment.account_log_id ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="支付宝订单号">{{ payment.alipay_order_no ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="付款方">{{ payment.payer_account ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="账务备注">{{ payment.trans_memo ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="确认备注" :span="2">{{ payment.remark ?? '-' }}</el-descriptions-item>
        </template>
      </el-descriptions>

      <template v-if="canReview && payment.status !== 'paid'">
        <h4 class="section">处理</h4>
        <el-alert type="info" :closable="false" show-icon class="tip">
          自动核对只认核对期内金额完全相等的到账。过期、取消后才到账的，点「查找到账记录」从支付宝账务明细里找金额相等、
          还没入过账的收入，确认是这个商户付的再入账；实在查不到但已在支付宝里核实到账，可以写明备注直接确认。
        </el-alert>
        <div class="actions">
          <el-button type="primary" @click="findCandidates">查找到账记录</el-button>
          <el-button @click="confirmPayment(null)">不选记录，直接确认入账</el-button>
        </div>
        <el-table v-if="candidates" :data="candidates" border size="small" empty-text="没有找到金额相等、还没入过账的到账记录">
          <el-table-column prop="trans_at" label="入账时间" width="160" />
          <el-table-column prop="amount" label="金额" width="100" align="right" />
          <el-table-column label="付款方" min-width="140">
            <template #default="{ row }">{{ row.payer_account ?? '-' }}</template>
          </el-table-column>
          <el-table-column label="备注" min-width="160">
            <template #default="{ row }">
              {{ row.memo ?? '-' }}
              <el-tag v-if="row.memo_matches" type="success" size="small">是这个单号</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="account_log_id" label="流水号" min-width="150" />
          <el-table-column label="操作" width="80" fixed="right">
            <template #default="{ row }">
              <el-button link type="success" @click="confirmPayment(row as TransferCandidate)">入账</el-button>
            </template>
          </el-table-column>
        </el-table>
      </template>
    </div>
  </el-drawer>

  <el-drawer v-model="detailVisible" title="加款申请" size="720px">
    <div v-loading="detailLoading || acting">
      <template v-if="detail">
        <div v-if="canReview && detail.status === 'pending'" class="actions">
          <el-button type="success" @click="approveRequest">审核通过并入账</el-button>
          <el-button type="danger" @click="rejectRequest">驳回</el-button>
        </div>
        <el-descriptions :column="2" border size="small">
          <el-descriptions-item label="商户">{{ detail.merchant_name }}</el-descriptions-item>
          <el-descriptions-item label="金额">
            <b>{{ detail.amount }}</b> 元
          </el-descriptions-item>
          <el-descriptions-item label="付款账号" :span="2">{{ accountText(detail.payer) }}</el-descriptions-item>
          <el-descriptions-item label="收款账户" :span="2">{{ accountText(detail.receiving) }}</el-descriptions-item>
          <el-descriptions-item label="打款时间">{{ detail.paid_at }}</el-descriptions-item>
          <el-descriptions-item label="提交时间">{{ detail.created_at }}</el-descriptions-item>
          <el-descriptions-item label="打款备注" :span="2">{{ detail.remark ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="状态">
            <StatusTag :map="fundingStatusLabels" :value="detail.status" />
          </el-descriptions-item>
          <el-descriptions-item label="审核">
            {{ detail.reviewed_at ? `${detail.reviewer_name ?? '-'} · ${detail.reviewed_at}` : '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="审核备注" :span="2">{{ detail.review_remark ?? '-' }}</el-descriptions-item>
        </el-descriptions>

        <h4 class="section">打款凭证（{{ detail.voucher_count }} 张）</h4>
        <el-alert v-if="detail.voucher_error" type="error" :closable="false" :title="`图片加载失败：${detail.voucher_error}`" />
        <div class="vouchers">
          <el-image
            v-for="(url, i) in detail.voucher_urls"
            :key="url"
            :src="url"
            :preview-src-list="detail.voucher_urls"
            :initial-index="i"
            fit="cover"
            class="voucher"
            preview-teleported
          />
        </div>
        <div class="muted">图片链接 10 分钟内有效，过期后重新打开详情即可。</div>
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

.actions {
  margin-bottom: 16px;
}

.section {
  margin: 20px 0 12px;
}

.tip {
  margin-bottom: 12px;
}

.vouchers {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 8px;
}

.voucher {
  width: 140px;
  height: 140px;
  border: 1px solid #ebeef5;
  border-radius: 4px;
}
</style>
