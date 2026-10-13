<script setup lang="ts">
import StatusTag from '@/components/StatusTag.vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { type BatchPreview, type BatchResult, type CatalogProduct, batchOrderApi, portalApi } from '@/api/portal'
import { labelOf, operatorLabels, orderStatusLabels } from '@/utils/labels'

const MAX_MOBILES = 200

const router = useRouter()

/** edit 填写 → preview 预览确认 → result 下单结果 */
const step = ref<'edit' | 'preview' | 'result'>('edit')
const products = ref<CatalogProduct[]>([])
const productCode = ref('')
const mobilesText = ref('')
const loading = ref(false)
const preview = ref<BatchPreview | null>(null)
const result = ref<BatchResult | null>(null)
/** 每次预览生成一个，提交超时后再点提交用同一个，后端不会重复下单 */
const batchNo = ref('')

const selectedProduct = computed(() => products.value.find((p) => p.product_code === productCode.value))

/** 换行、空格、逗号、分号都算分隔 */
const mobiles = computed(() => mobilesText.value.split(/[\s,，;；]+/).filter((m) => m !== ''))

const validMobiles = computed(() => preview.value?.items.filter((i) => i.ok).map((i) => i.mobile) ?? [])

const balanceShort = computed(() => {
  const p = preview.value
  return p !== null && p.balance !== null && Number(p.balance) < Number(p.total_amount)
})

function productText(p: CatalogProduct): string {
  const prices = p.operators.map((o) => `${labelOf(operatorLabels, o.operator)} ${o.price}`).join(' / ')
  return `${p.name}（${p.product_code}，面值 ${p.face_value} 元）${prices ? '：' + prices : ''}`
}

function newBatchNo(): string {
  const now = new Date()
  const pad = (n: number) => String(n).padStart(2, '0')
  const time = `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`
  const random = Array.from(crypto.getRandomValues(new Uint8Array(4)), (b) => b.toString(16).padStart(2, '0')).join('')
  return `B${time}${random}`
}

async function doPreview() {
  if (!productCode.value) {
    ElMessage.warning('请选择商品')
    return
  }
  if (mobiles.value.length === 0) {
    ElMessage.warning('请填写手机号')
    return
  }
  if (mobiles.value.length > MAX_MOBILES) {
    ElMessage.warning(`一批最多 ${MAX_MOBILES} 个号码，当前 ${mobiles.value.length} 个，请分批提交`)
    return
  }
  loading.value = true
  try {
    preview.value = await batchOrderApi.preview(productCode.value, mobiles.value)
    batchNo.value = newBatchNo()
    step.value = 'preview'
  } finally {
    loading.value = false
  }
}

async function submit() {
  const p = preview.value!
  const tip = balanceShort.value ? `当前余额 ${p.balance} 元，不够全部下单，余额不足的号码会下单失败。` : ''
  try {
    await ElMessageBox.confirm(
      `将为 ${p.valid_count} 个号码下单，预计扣款 ${p.total_amount} 元（每个号码单独扣款，失败自动退款）。${tip}确定下单吗？`,
      '确认下单',
      { type: 'warning' },
    )
  } catch {
    return
  }
  loading.value = true
  try {
    result.value = await batchOrderApi.submit(productCode.value, validMobiles.value, batchNo.value)
    step.value = 'result'
  } finally {
    loading.value = false
  }
}

async function copyFailed() {
  const failed = result.value?.items.filter((i) => !i.ok).map((i) => i.mobile) ?? []
  try {
    await navigator.clipboard.writeText(failed.join('\n'))
    ElMessage.success(`已复制 ${failed.length} 个号码`)
  } catch {
    ElMessage.error('复制失败，请手动选择复制')
  }
}

function restart() {
  mobilesText.value = ''
  preview.value = null
  result.value = null
  step.value = 'edit'
}

onMounted(async () => {
  products.value = (await portalApi.products()).products
  if (products.value.length === 1) {
    productCode.value = products.value[0].product_code
  }
})
</script>

<template>
  <el-card v-loading="loading" shadow="never">
    <template v-if="step === 'edit'">
      <div class="tip">
        一批选一个商品，最多 {{ MAX_MOBILES }} 个号码。先预览，确认每个号码的运营商、价格后再下单；每个号码单独下单、单独扣款，
        失败自动退款，结果照常推送到你的回调地址，也可以在「订单记录」里查。
      </div>
      <el-form label-width="80px" class="form">
        <el-form-item label="商品">
          <el-select v-model="productCode" placeholder="选择商品" filterable class="full" no-data-text="还没有开通商品，请联系平台">
            <el-option v-for="p in products" :key="p.product_code" :label="productText(p)" :value="p.product_code" />
          </el-select>
        </el-form-item>
        <el-form-item label="手机号">
          <el-input
            v-model="mobilesText"
            type="textarea"
            :rows="12"
            placeholder="每行一个手机号，也可以用空格、逗号分隔"
          />
          <div class="count" :class="{ over: mobiles.length > MAX_MOBILES }">已识别 {{ mobiles.length }} 个号码（最多 {{ MAX_MOBILES }} 个）</div>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="doPreview">预览</el-button>
        </el-form-item>
      </el-form>
    </template>

    <template v-else-if="step === 'preview' && preview">
      <el-alert :type="balanceShort ? 'warning' : 'info'" :closable="false" show-icon class="summary">
        <template #title>
          {{ selectedProduct ? productText(selectedProduct) : productCode }}：共 {{ preview.items.length }} 个号码，
          可下单 <b>{{ preview.valid_count }}</b> 个，预计扣款 <b>{{ preview.total_amount }}</b> 元，当前余额 {{ preview.balance }} 元
          <template v-if="balanceShort">，余额不够全部下单</template>
        </template>
      </el-alert>
      <el-table :data="preview.items" border max-height="520">
        <el-table-column type="index" label="#" width="60" />
        <el-table-column label="手机号" prop="mobile" width="140" />
        <el-table-column label="运营商" width="90">
          <template #default="{ row }">{{ labelOf(operatorLabels, row.operator) }}</template>
        </el-table-column>
        <el-table-column label="地区" width="90">
          <template #default="{ row }">{{ row.province ?? '-' }}</template>
        </el-table-column>
        <el-table-column label="价格（元）" width="110" align="right">
          <template #default="{ row }">{{ row.price ?? '-' }}</template>
        </el-table-column>
        <el-table-column label="检查结果" min-width="220">
          <template #default="{ row }">
            <el-tag v-if="row.ok" type="success" disable-transitions>可下单</el-tag>
            <span v-else class="error">{{ row.error }}</span>
          </template>
        </el-table-column>
      </el-table>
      <div class="actions">
        <el-button @click="step = 'edit'">返回修改</el-button>
        <el-button type="primary" :disabled="preview.valid_count === 0" @click="submit">
          确认下单（{{ preview.valid_count }} 个）
        </el-button>
        <span v-if="preview.valid_count < preview.items.length" class="muted">不能下单的号码不会提交</span>
      </div>
    </template>

    <template v-else-if="step === 'result' && result">
      <el-alert :type="result.failed_count ? 'warning' : 'success'" :closable="false" show-icon class="summary">
        <template #title>
          下单成功 <b>{{ result.success_count }}</b> 个，失败 <b>{{ result.failed_count }}</b> 个，当前余额 {{ result.balance }} 元。
          下单成功只是平台已受理，充值结果以订单记录和回调为准。
        </template>
      </el-alert>
      <el-table :data="result.items" border max-height="520">
        <el-table-column type="index" label="#" width="60" />
        <el-table-column label="手机号" prop="mobile" width="140" />
        <el-table-column label="商户订单号" prop="merchant_order_no" width="210" />
        <el-table-column label="平台订单号" width="190">
          <template #default="{ row }">{{ row.order_no ?? '-' }}</template>
        </el-table-column>
        <el-table-column label="扣款（元）" width="90" align="right">
          <template #default="{ row }">{{ row.amount ?? '-' }}</template>
        </el-table-column>
        <el-table-column label="结果" min-width="180">
          <template #default="{ row }">
            <StatusTag v-if="row.ok" :map="orderStatusLabels" :value="row.status" />
            <span v-else class="error">{{ row.error }}</span>
          </template>
        </el-table-column>
      </el-table>
      <div class="actions">
        <el-button type="primary" @click="restart">再下一批</el-button>
        <el-button v-if="result.failed_count" @click="copyFailed">复制失败的号码</el-button>
        <el-button @click="router.push({ name: 'orders' })">去订单记录</el-button>
      </div>
    </template>
  </el-card>
</template>

<style scoped>
.tip {
  margin-bottom: 16px;
  color: #909399;
  font-size: 12px;
  line-height: 20px;
}

.form {
  max-width: 760px;
}

.full {
  width: 100%;
}

.count {
  width: 100%;
  color: #909399;
  font-size: 12px;
}

.count.over {
  color: #f56c6c;
}

.summary {
  margin-bottom: 12px;
}

.actions {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-top: 16px;
}

.error {
  color: #f56c6c;
}

.muted {
  color: #909399;
  font-size: 12px;
}
</style>
