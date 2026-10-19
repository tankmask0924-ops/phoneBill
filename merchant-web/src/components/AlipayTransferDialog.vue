<script setup lang="ts">
import { ElMessage, ElMessageBox } from 'element-plus'
import QRCode from 'qrcode'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { type OnlinePayment, type OnlinePaymentOptions, onlinePaymentApi } from '@/api/portal'

/**
 * 支付宝扫码转账加款：输入金额 → 展示二维码、应付金额、支付单号（备注）→ 轮询到账。
 * 传 payment 时直接打开这笔待支付单。
 */
const props = defineProps<{ options: OnlinePaymentOptions; payment?: OnlinePayment | null }>()
const emit = defineEmits<{ changed: [] }>()
const visible = defineModel<boolean>({ required: true })

const amount = ref('')
const creating = ref(false)
const current = ref<OnlinePayment | null>(null)
const qrImage = ref('')
const checking = ref(false)
const now = ref(Date.now())
let pollTimer: ReturnType<typeof setInterval> | undefined
let clockTimer: ReturnType<typeof setInterval> | undefined

const secondsLeft = computed(() => {
  if (!current.value) {
    return 0
  }
  return Math.max(0, Math.floor((new Date(current.value.expires_at.replace(' ', 'T')).getTime() - now.value) / 1000))
})
const countdown = computed(() => {
  const s = secondsLeft.value
  return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`
})

watch(visible, (open) => {
  if (open) {
    amount.value = ''
    show(props.payment ?? null)
  } else {
    stopTimers()
  }
})

async function show(p: OnlinePayment | null) {
  current.value = p
  qrImage.value = p?.qr_content ? await QRCode.toDataURL(p.qr_content, { width: 240, margin: 1 }) : ''
  stopTimers()
  if (p?.status === 'pending') {
    clockTimer = setInterval(() => (now.value = Date.now()), 1000)
    pollTimer = setInterval(refresh, 5000)
  }
}

function stopTimers() {
  clearInterval(pollTimer)
  clearInterval(clockTimer)
}

async function refresh() {
  if (!current.value) {
    return
  }
  const p = await onlinePaymentApi.detail(current.value.id)
  if (p.status !== current.value.status) {
    await show(p)
    emit('changed')
    if (p.status === 'paid') {
      ElMessage.success(`已到账 ${p.pay_amount} 元`)
    }
  }
}

async function create() {
  if (!/^\d{1,9}(\.\d{1,2})?$/.test(amount.value) || Number(amount.value) <= 0) {
    ElMessage.warning('请输入正确的金额，最多两位小数')
    return
  }
  creating.value = true
  try {
    await show(await onlinePaymentApi.create(amount.value))
    emit('changed')
  } finally {
    creating.value = false
  }
}

async function paid() {
  checking.value = true
  try {
    const p = await onlinePaymentApi.check(current.value!.id)
    if (p.status === 'paid') {
      await show(p)
      emit('changed')
      ElMessage.success(`已到账 ${p.pay_amount} 元`)
    } else {
      ElMessage.info('暂时还没查到到账，支付宝入账可能有延迟，系统会每分钟自动核对，到账后自动加款')
    }
  } finally {
    checking.value = false
  }
}

async function cancel() {
  try {
    await ElMessageBox.confirm('确定取消这笔支付？如果已经转账了请不要取消，等待自动到账。', '取消支付', { type: 'warning' })
  } catch {
    return
  }
  await show(await onlinePaymentApi.cancel(current.value!.id))
  emit('changed')
}

async function copy(text: string) {
  try {
    await navigator.clipboard.writeText(text)
    ElMessage.success('已复制')
  } catch {
    ElMessage.warning('复制失败，请手动选中复制')
  }
}

onBeforeUnmount(stopTimers)
</script>

<template>
  <el-dialog v-model="visible" title="支付宝转账加款" width="520px" :close-on-click-modal="false">
    <template v-if="!current">
      <el-form label-width="80px" @submit.prevent="create">
        <el-form-item label="加款金额">
          <el-input v-model.trim="amount" placeholder="输入要加款的金额" style="width: 220px">
            <template #append>元</template>
          </el-input>
        </el-form-item>
        <div class="tip">
          单笔 {{ options.min_amount }} ~ {{ options.max_amount }} 元。为了自动认出你的转账，实际要付的金额会带上随机的几分钱，
          到账后按实际付的金额加款。
        </div>
      </el-form>
    </template>

    <template v-else-if="current.status === 'pending'">
      <div class="pay">
        <div class="pay-label">请用支付宝转账</div>
        <div class="pay-amount">¥ {{ current.pay_amount }}</div>
        <div class="tip">金额必须一分不差，不要改动</div>
        <template v-if="!current.expired_qr">
          <img v-if="qrImage" :src="qrImage" class="qr" alt="支付宝转账二维码" />
          <div class="tip">打开支付宝扫一扫，剩余 <b>{{ countdown }}</b></div>
        </template>
        <el-alert v-else type="warning" :closable="false" show-icon class="expired">
          二维码已到期，请不要再转账。如果已经转了，系统还会核对一段时间，到账后自动加款。
        </el-alert>
      </div>
      <el-descriptions :column="1" border size="small" class="info">
        <el-descriptions-item label="转账备注">
          <b>{{ current.order_no }}</b>
          <el-button link type="primary" @click="copy(current.order_no)">复制</el-button>
          <div class="warn">备注必须填这个支付单号（扫码会自动填好，不要删改）</div>
        </el-descriptions-item>
        <el-descriptions-item label="收款账号">
          {{ current.receive_account }}（{{ current.receive_name }}）
          <el-button link type="primary" @click="copy(current.receive_account)">复制</el-button>
        </el-descriptions-item>
        <el-descriptions-item label="应付金额">
          {{ current.pay_amount }} 元
          <el-button link type="primary" @click="copy(current.pay_amount)">复制</el-button>
        </el-descriptions-item>
      </el-descriptions>
      <div class="tip">扫码打不开时，可以在支付宝里手动转账到上面的账号，金额和备注照填。</div>
    </template>

    <el-result
      v-else
      :icon="current.status === 'paid' ? 'success' : 'info'"
      :title="current.status === 'paid' ? `已到账 ${current.pay_amount} 元` : current.status === 'cancelled' ? '已取消' : '已过期'"
      :sub-title="current.status === 'paid' ? `已加到账户余额，支付单号 ${current.order_no}` : '如果已经转账但没有到账，请联系平台并提供支付单号 ' + current.order_no"
    />

    <template #footer>
      <template v-if="!current">
        <el-button @click="visible = false">取消</el-button>
        <el-button type="primary" :loading="creating" @click="create">生成付款码</el-button>
      </template>
      <template v-else-if="current.status === 'pending'">
        <el-button @click="cancel">取消支付</el-button>
        <el-button type="primary" :loading="checking" @click="paid">我已付款</el-button>
      </template>
      <el-button v-else type="primary" @click="visible = false">关闭</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.pay {
  text-align: center;
}

.pay-label {
  color: #606266;
}

.pay-amount {
  margin: 4px 0;
  color: #f56c6c;
  font-size: 32px;
  font-weight: 600;
}

.qr {
  width: 220px;
  height: 220px;
  margin: 12px auto 4px;
  display: block;
}

.expired {
  margin: 12px 0;
  text-align: left;
}

.info {
  margin: 16px 0 8px;
}

.tip {
  color: #909399;
  font-size: 12px;
  line-height: 20px;
}

.warn {
  color: #e6a23c;
  font-size: 12px;
}
</style>
