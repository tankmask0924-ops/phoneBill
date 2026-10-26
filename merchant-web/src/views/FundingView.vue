<script setup lang="ts">
import AlipayTransferDialog from '@/components/AlipayTransferDialog.vue'
import StatusTag from '@/components/StatusTag.vue'
import { recentDays } from '@/utils/date'
import { usePagedList } from '@/utils/paged'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules, type UploadRequestOptions, type UploadUserFile } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  type FundingRequest,
  fundingApi,
  type OnlinePayment,
  type OnlinePaymentOptions,
  onlinePaymentApi,
  type PayerAccount,
  type PaymentAccountInfo,
  type ReceivingAccount,
} from '@/api/portal'
import { fundingStatusLabels, onlinePaymentStatusLabels, toOptions } from '@/utils/labels'

const router = useRouter()

const list = usePagedList<FundingRequest, { status: string; dates: [string, string] | null }>(
  (params) => {
    const { dates, ...rest } = params as Record<string, unknown> & { dates?: [string, string] }
    return fundingApi.requests(dates ? { ...rest, created_from: dates[0], created_to: dates[1] } : rest)
  },
  { status: '', dates: recentDays() },
  20,
)
const { rows, total, page, perPage, loading, filters } = list

const receivingAccounts = ref<ReceivingAccount[]>([])
const payerAccounts = ref<PayerAccount[]>([])
const approvedPayers = computed(() => payerAccounts.value.filter((a) => a.status === 'approved'))

function accountText(a: PaymentAccountInfo): string {
  return [a.type_label, a.account_name, a.account_no, a.bank_name].filter(Boolean).join(' · ')
}

// ---- 申请加款 ----
const MAX_VOUCHERS = 5
const dialogVisible = ref(false)
const saving = ref(false)
const formRef = ref<FormInstance>()
const form = reactive({
  amount: '',
  payer_account_id: undefined as number | undefined,
  receiving_account_id: undefined as number | undefined,
  paid_at: '',
  remark: '',
})
/** 已上传的凭证，response 里是后端返回的 key */
const fileList = ref<UploadUserFile[]>([])
const uploading = ref(0)

const rules: FormRules = {
  receiving_account_id: [{ required: true, message: '请选择收款账户', trigger: 'change' }],
  payer_account_id: [{ required: true, message: '请选择付款账号', trigger: 'change' }],
  amount: [
    { required: true, message: '请输入打款金额', trigger: 'blur' },
    { pattern: /^\d{1,9}(\.\d{1,2})?$/, message: '金额格式不对，最多两位小数', trigger: 'blur' },
  ],
  paid_at: [{ required: true, message: '请选择打款时间', trigger: 'change' }],
}

const selectedReceiving = computed(() => receivingAccounts.value.find((a) => a.id === form.receiving_account_id))
const selectedPayer = computed(() => approvedPayers.value.find((a) => a.id === form.payer_account_id))

async function openCreate() {
  ;[receivingAccounts.value, payerAccounts.value] = await Promise.all([fundingApi.receivingAccounts(), fundingApi.payerAccounts()])
  Object.assign(form, {
    amount: '',
    payer_account_id: approvedPayers.value.length === 1 ? approvedPayers.value[0].id : undefined,
    receiving_account_id: receivingAccounts.value.length === 1 ? receivingAccounts.value[0].id : undefined,
    paid_at: '',
    remark: '',
  })
  fileList.value = []
  dialogVisible.value = true
}

async function uploadVoucher(options: UploadRequestOptions) {
  uploading.value++
  try {
    const result = await fundingApi.uploadVoucher(options.file)
    return result.key
  } finally {
    uploading.value--
  }
}

function onExceed() {
  ElMessage.warning(`最多上传 ${MAX_VOUCHERS} 张`)
}

function beforeUpload(file: File): boolean {
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
    ElMessage.warning('只支持 JPG、PNG、WebP 图片')
    return false
  }
  if (file.size > 5 * 1024 * 1024) {
    ElMessage.warning('图片不能超过 5MB')
    return false
  }
  return true
}

async function save() {
  if (!(await formRef.value?.validate().catch(() => false))) {
    return
  }
  if (uploading.value > 0) {
    ElMessage.warning('凭证还在上传，请稍等')
    return
  }
  const vouchers = fileList.value.filter((f) => f.status === 'success' && typeof f.response === 'string').map((f) => f.response as string)
  if (vouchers.length === 0) {
    ElMessage.warning('请上传打款凭证')
    return
  }
  saving.value = true
  try {
    await fundingApi.createRequest({
      amount: form.amount,
      payer_account_id: form.payer_account_id!,
      receiving_account_id: form.receiving_account_id!,
      paid_at: form.paid_at,
      remark: form.remark,
      vouchers,
    })
    ElMessage.success('已提交，平台核对到账后会给你加款')
    dialogVisible.value = false
    await list.search()
  } finally {
    saving.value = false
  }
}

// ---- 详情 ----
const detailVisible = ref(false)
const detail = ref<FundingRequest | null>(null)

async function openDetail(row: FundingRequest) {
  detail.value = null
  detailVisible.value = true
  detail.value = await fundingApi.request(row.id)
}

// ---- 支付宝转账加款 ----
const tab = ref<'offline' | 'online'>('offline')
const onlineOptions = ref<OnlinePaymentOptions>({ enabled: false, min_amount: null, max_amount: null, qr_code_url: null, receive_account: null, receive_name: null })
const online = usePagedList<OnlinePayment, { status: string; dates: [string, string] | null }>(
  (params) => {
    const { dates, ...rest } = params as Record<string, unknown> & { dates?: [string, string] }
    return onlinePaymentApi.list(dates ? { ...rest, created_from: dates[0], created_to: dates[1] } : rest)
  },
  { status: '', dates: recentDays() },
  20,
)
const transferVisible = ref(false)
const transferPayment = ref<OnlinePayment | null>(null)

async function openTransfer(row: OnlinePayment | null) {
  if (!row) {
    // 收款码链接是临时的，每次打开重新取
    onlineOptions.value = await onlinePaymentApi.options()
  }
  transferPayment.value = row
  transferVisible.value = true
}

async function cancelTransfer(row: OnlinePayment) {
  try {
    await ElMessageBox.confirm(`取消支付宝订单号 ${row.alipay_order_no} 的这笔提交？如果确实转了账，之后可以重新提交同一个订单号。`, '取消', { type: 'warning' })
  } catch {
    return
  }
  await onlinePaymentApi.cancel(row.id)
  ElMessage.success('已取消')
  online.load()
}

onMounted(async () => {
  list.load()
  onlineOptions.value = await onlinePaymentApi.options()
  if (onlineOptions.value.enabled) {
    online.load()
  }
})
</script>

<template>
  <el-card shadow="never">
    <el-tabs v-model="tab">
      <el-tab-pane label="线下打款" name="offline">
        <div class="toolbar">
          <el-form inline @submit.prevent="list.search">
            <el-form-item>
              <el-select v-model="filters.status" placeholder="全部状态" clearable style="width: 110px">
                <el-option v-for="o in toOptions(fundingStatusLabels)" :key="o.value" v-bind="o" />
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
          </el-form>
          <el-button type="primary" :icon="Plus" @click="openCreate">申请加款</el-button>
        </div>

        <el-table v-loading="loading" :data="rows" border empty-text="还没有加款申请">
          <el-table-column prop="created_at" label="提交时间" width="170" />
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
          <el-table-column label="状态" min-width="150">
            <template #default="{ row }">
              <StatusTag :map="fundingStatusLabels" :value="row.status" />
              <div v-if="row.status === 'rejected' && row.review_remark" class="reason">{{ row.review_remark }}</div>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="80" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" @click="openDetail(row as FundingRequest)">详情</el-button>
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
      </el-tab-pane>

      <el-tab-pane v-if="onlineOptions.enabled" label="支付宝转账" name="online">
        <div class="toolbar">
          <el-form inline @submit.prevent="online.search">
            <el-form-item>
              <el-select v-model="online.filters.status" placeholder="全部状态" clearable style="width: 110px">
                <el-option v-for="o in toOptions(onlinePaymentStatusLabels)" :key="o.value" v-bind="o" />
              </el-select>
            </el-form-item>
            <el-form-item>
              <el-date-picker
                v-model="online.filters.dates"
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
          </el-form>
          <el-button type="primary" :icon="Plus" @click="openTransfer(null)">支付宝转账加款</el-button>
        </div>
        <el-alert type="info" :closable="false" show-icon class="block">
          扫平台收款码用支付宝转账，转完在支付宝账单里复制这笔转账的「订单号」，连同金额提交，系统查到到账立即加款，不用上传凭证。
          支付宝入账偶尔有几分钟延迟，没查到可以稍后「重新查询」。
        </el-alert>

        <el-table v-loading="online.loading.value" :data="online.rows.value" border empty-text="还没有支付宝转账记录">
          <el-table-column prop="created_at" label="提交时间" width="170" />
          <el-table-column label="支付宝订单号" min-width="230">
            <template #default="{ row }">{{ row.alipay_order_no ?? '-' }}</template>
          </el-table-column>
          <el-table-column label="金额（元）" width="110" align="right">
            <template #default="{ row }">{{ row.status === 'paid' ? row.pay_amount : row.amount }}</template>
          </el-table-column>
          <el-table-column label="状态" width="100">
            <template #default="{ row }"><StatusTag :map="onlinePaymentStatusLabels" :value="row.status" /></template>
          </el-table-column>
          <el-table-column label="到账时间 / 说明" min-width="220">
            <template #default="{ row }">
              <span v-if="row.paid_at">{{ row.paid_at }}</span>
              <span v-else-if="row.check_message" class="muted">{{ row.check_message }}</span>
              <span v-else>-</span>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="130" fixed="right">
            <template #default="{ row }">
              <template v-if="row.status === 'pending'">
                <el-button link type="primary" @click="openTransfer(row as OnlinePayment)">重新查询</el-button>
                <el-button link type="danger" @click="cancelTransfer(row as OnlinePayment)">取消</el-button>
              </template>
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
    </el-tabs>
  </el-card>

  <AlipayTransferDialog v-model="transferVisible" :options="onlineOptions" :payment="transferPayment" @changed="online.load" />

  <el-dialog v-model="dialogVisible" title="申请加款" width="640px" :close-on-click-modal="false" @closed="formRef?.clearValidate()">
    <el-alert v-if="receivingAccounts.length === 0" type="warning" :closable="false" show-icon class="block">
      平台还没有给你分配收款账户，请联系平台。
    </el-alert>
    <el-alert v-else-if="approvedPayers.length === 0" type="warning" :closable="false" show-icon class="block">
      还没有审核通过的付款账号。
      <el-button link type="primary" @click="router.push('/payer-accounts')">去添加付款账号</el-button>
    </el-alert>
    <el-form ref="formRef" :model="form" :rules="rules" label-width="96px">
      <el-form-item label="收款账户" prop="receiving_account_id">
        <el-select v-model="form.receiving_account_id" placeholder="打款到哪个账户" style="width: 100%">
          <el-option v-for="a in receivingAccounts" :key="a.id" :label="a.account_name" :value="a.id" />
        </el-select>
        <div v-if="selectedReceiving" class="receiving">
          <div>{{ selectedReceiving.type_label }}：{{ selectedReceiving.account_name }}</div>
          <div>账号：<b>{{ selectedReceiving.account_no }}</b></div>
          <div v-if="selectedReceiving.bank_name">开户行：{{ selectedReceiving.bank_name }}</div>
          <div v-if="selectedReceiving.remark" class="tip">{{ selectedReceiving.remark }}</div>
        </div>
      </el-form-item>
      <el-form-item label="付款账号" prop="payer_account_id">
        <el-select v-model="form.payer_account_id" placeholder="用哪个账号打的款" style="width: 100%">
          <el-option v-for="a in approvedPayers" :key="a.id" :label="a.account_name" :value="a.id" />
        </el-select>
        <div v-if="selectedPayer" class="receiving">
          <div>{{ selectedPayer.type_label }}：{{ selectedPayer.account_name }}</div>
          <div>账号：<b>{{ selectedPayer.account_no }}</b></div>
          <div v-if="selectedPayer.bank_name">开户行：{{ selectedPayer.bank_name }}</div>
        </div>
      </el-form-item>
      <el-form-item label="打款金额" prop="amount">
        <el-input v-model.trim="form.amount" placeholder="和实际打款金额一致" style="width: 200px">
          <template #append>元</template>
        </el-input>
        <span class="tip inline">平台按这个金额入账，和到账金额不一致会被驳回</span>
      </el-form-item>
      <el-form-item label="打款时间" prop="paid_at">
        <el-date-picker v-model="form.paid_at" type="datetime" value-format="YYYY-MM-DD HH:mm" format="YYYY-MM-DD HH:mm" placeholder="选择打款时间" />
      </el-form-item>
      <el-form-item label="打款备注">
        <el-input v-model="form.remark" maxlength="255" placeholder="选填，如银行流水号、转账附言" />
      </el-form-item>
      <el-form-item label="打款凭证" required>
        <el-upload
          v-model:file-list="fileList"
          list-type="picture-card"
          accept="image/jpeg,image/png,image/webp"
          :limit="MAX_VOUCHERS"
          :http-request="uploadVoucher"
          :before-upload="beforeUpload"
          :on-exceed="onExceed"
        >
          <el-icon><Plus /></el-icon>
        </el-upload>
        <div class="tip">转账回单截图，1~{{ MAX_VOUCHERS }} 张，每张不超过 5MB</div>
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" :disabled="receivingAccounts.length === 0 || approvedPayers.length === 0" @click="save">
        提交申请
      </el-button>
    </template>
  </el-dialog>

  <el-drawer v-model="detailVisible" title="加款申请" size="640px">
    <div v-loading="!detail">
      <template v-if="detail">
        <el-descriptions :column="1" border size="small">
          <el-descriptions-item label="金额"><b>{{ detail.amount }}</b> 元</el-descriptions-item>
          <el-descriptions-item label="状态"><StatusTag :map="fundingStatusLabels" :value="detail.status" /></el-descriptions-item>
          <el-descriptions-item v-if="detail.review_remark" label="审核备注">{{ detail.review_remark }}</el-descriptions-item>
          <el-descriptions-item label="付款账号">{{ accountText(detail.payer) }}</el-descriptions-item>
          <el-descriptions-item label="收款账户">{{ accountText(detail.receiving) }}</el-descriptions-item>
          <el-descriptions-item label="打款时间">{{ detail.paid_at }}</el-descriptions-item>
          <el-descriptions-item label="打款备注">{{ detail.remark ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="提交时间">{{ detail.created_at }}</el-descriptions-item>
          <el-descriptions-item label="审核时间">{{ detail.reviewed_at ?? '-' }}</el-descriptions-item>
        </el-descriptions>
        <div class="vouchers">
          <el-image
            v-for="(url, i) in detail.voucher_urls ?? []"
            :key="url"
            :src="url"
            :preview-src-list="detail.voucher_urls"
            :initial-index="i"
            fit="cover"
            class="voucher"
            preview-teleported
          />
        </div>
      </template>
    </div>
  </el-drawer>
</template>

<style scoped>
.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}

.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}

.reason {
  margin-top: 4px;
  color: #f56c6c;
  font-size: 12px;
}

.block {
  margin-bottom: 16px;
}

.muted {
  color: #909399;
  font-size: 12px;
}

.receiving {
  width: 100%;
  margin-top: 8px;
  padding: 8px 12px;
  background: #f5f7fa;
  border-radius: 4px;
  line-height: 22px;
}

.tip {
  color: #909399;
  font-size: 12px;
  line-height: 20px;
}

.tip.inline {
  margin-left: 12px;
}

.vouchers {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 16px;
}

.voucher {
  width: 140px;
  height: 140px;
  border: 1px solid #ebeef5;
  border-radius: 4px;
}
</style>
