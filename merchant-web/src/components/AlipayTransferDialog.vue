<script setup lang="ts">
import { ElMessage } from 'element-plus'
import { ref, watch } from 'vue'
import { type OnlinePayment, type OnlinePaymentOptions, onlinePaymentApi } from '@/api/portal'

/**
 * 支付宝转账加款：扫平台的固定收款码转账 → 填支付宝订单号和金额 → 点「已转账」立即查询到账。
 * 传 payment 时直接打开这笔未到账的单（可以重新查询）。
 */
const props = defineProps<{ options: OnlinePaymentOptions; payment?: OnlinePayment | null }>()
const emit = defineEmits<{ changed: [] }>()
const visible = defineModel<boolean>({ required: true })

const form = ref({ alipay_order_no: '', amount: '' })
const submitting = ref(false)
const current = ref<OnlinePayment | null>(null)

watch(visible, (open) => {
  if (open) {
    current.value = props.payment ?? null
    form.value = {
      alipay_order_no: props.payment?.alipay_order_no ?? '',
      amount: props.payment?.amount ?? '',
    }
  }
})

function notify(p: OnlinePayment) {
  if (p.status === 'paid') {
    ElMessage.success(`已到账，加款 ${p.pay_amount} 元`)
  }
}

async function submit() {
  const alipayOrderNo = form.value.alipay_order_no.trim()
  if (!/^[0-9A-Za-z]{10,64}$/.test(alipayOrderNo)) {
    ElMessage.warning('请填写支付宝订单号：在支付宝「账单」里点开这笔转账，复制「订单号」')
    return
  }
  if (!/^\d{1,9}(\.\d{1,2})?$/.test(form.value.amount) || Number(form.value.amount) <= 0) {
    ElMessage.warning('请输入正确的转账金额，最多两位小数')
    return
  }
  submitting.value = true
  try {
    current.value = await onlinePaymentApi.submit({ alipay_order_no: alipayOrderNo, amount: form.value.amount })
    emit('changed')
    notify(current.value)
  } finally {
    submitting.value = false
  }
}

async function recheck() {
  submitting.value = true
  try {
    current.value = await onlinePaymentApi.check(current.value!.id)
    emit('changed')
    notify(current.value)
  } finally {
    submitting.value = false
  }
}

async function copy(text: string) {
  try {
    await navigator.clipboard.writeText(text)
    ElMessage.success('已复制')
  } catch {
    ElMessage.warning('复制失败，请手动选中复制')
  }
}

/** 没到账时回到表单，改订单号或金额重新提交（同一个订单号会更新原来那笔单） */
function edit() {
  current.value = null
}
</script>

<template>
  <el-dialog v-model="visible" title="支付宝转账加款" width="680px" :close-on-click-modal="false">
    <template v-if="!current">
      <div class="pay">
        <img v-if="options.qr_code_url" :src="options.qr_code_url" class="qr" alt="支付宝收款码" />
        <el-alert v-else type="warning" :closable="false" show-icon class="block">收款码暂时加载不出来，请按下面的收款账号手动转账。</el-alert>
        <div class="step">① 打开支付宝扫一扫，向平台转账（单笔 {{ options.min_amount }} ~ {{ options.max_amount }} 元）</div>
        <div v-if="options.receive_account" class="receiver">
          <div class="receiver-row">
            <span class="receiver-label">收款账号</span>
            <span class="receiver-value">{{ options.receive_account }}</span>
            <el-button link type="primary" @click="copy(options.receive_account)">复制</el-button>
          </div>
          <div class="receiver-row">
            <span class="receiver-label">收款人</span>
            <span class="receiver-value">{{ options.receive_name }}</span>
          </div>
          <div class="tip">转账前请核对收款人是否一致</div>
        </div>
      </div>
      <el-form label-width="110px" size="large" class="form" @submit.prevent="submit">
        <el-form-item label="支付宝订单号">
          <el-input v-model.trim="form.alipay_order_no" maxlength="64" placeholder="转账完成后在支付宝账单里复制" />
          <div class="tip">② 支付宝「我的 - 账单」点开这笔转账，复制「订单号」粘贴到这里</div>
        </el-form-item>
        <el-form-item label="转账金额">
          <el-input v-model.trim="form.amount" placeholder="实际转了多少就填多少" style="width: 220px">
            <template #append>元</template>
          </el-input>
        </el-form-item>
      </el-form>
    </template>

    <el-result
      v-else-if="current.status === 'paid'"
      icon="success"
      :title="`已到账 ${current.pay_amount} 元`"
      :sub-title="`已加到账户余额，支付宝订单号 ${current.alipay_order_no ?? '-'}`"
    />

    <template v-else>
      <el-result icon="warning" :title="current.status === 'pending' ? '暂未到账' : '已取消'">
        <template #sub-title>
          <div class="tip">支付宝订单号 {{ current.alipay_order_no ?? '-' }}，金额 {{ current.amount }} 元</div>
          <div v-if="current.check_message" class="warn">{{ current.check_message }}</div>
          <div v-if="current.checked_at" class="tip">查询时间 {{ current.checked_at }}</div>
        </template>
      </el-result>
    </template>

    <template #footer>
      <template v-if="!current">
        <el-button @click="visible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submit">已转账</el-button>
      </template>
      <template v-else-if="current.status === 'pending'">
        <el-button @click="edit">修改后重新提交</el-button>
        <el-button type="primary" :loading="submitting" @click="recheck">重新查询</el-button>
      </template>
      <el-button v-else type="primary" @click="visible = false">关闭</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.pay {
  text-align: center;
}

.qr {
  width: 280px;
  height: 280px;
  margin: 0 auto 12px;
  display: block;
  object-fit: contain;
}

.block {
  margin-bottom: 8px;
  text-align: left;
}

.form {
  margin-top: 20px;
}

.step {
  color: #606266;
  font-size: 14px;
  line-height: 22px;
}

.receiver {
  display: inline-block;
  min-width: 360px;
  margin-top: 12px;
  padding: 12px 20px;
  background: #f5f7fa;
  border-radius: 6px;
  text-align: left;
}

.receiver-row {
  display: flex;
  align-items: center;
  gap: 12px;
  line-height: 32px;
}

.receiver-label {
  width: 64px;
  color: #909399;
  font-size: 14px;
}

.receiver-value {
  color: #303133;
  font-size: 20px;
  font-weight: 600;
  word-break: break-all;
}

.tip {
  color: #909399;
  font-size: 12px;
  line-height: 20px;
}

.warn {
  color: #e6a23c;
  font-size: 13px;
  line-height: 20px;
  margin: 4px 0;
}
</style>
