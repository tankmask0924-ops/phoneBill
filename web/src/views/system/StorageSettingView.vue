<script setup lang="ts">
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { onMounted, reactive, ref } from 'vue'
import { settingApi } from '@/api/funding'
import { useSiteStore } from '@/stores/site'

// ---- 基本设置 ----
const site = useSiteStore()
const siteName = ref('')
const siteSaving = ref(false)
const defaultName = import.meta.env.VITE_APP_TITLE

async function saveSite() {
  siteSaving.value = true
  try {
    siteName.value = (await settingApi.saveSite(siteName.value.trim())).name
    site.set(siteName.value)
    ElMessage.success(siteName.value ? '已保存，左上角和浏览器标题已更新' : '已保存，使用默认名称')
  } finally {
    siteSaving.value = false
  }
}

// ---- 回调域名 ----
const notifyBase = ref('')
const notifySaving = ref(false)

async function saveNotify() {
  notifySaving.value = true
  try {
    notifyBase.value = (await settingApi.saveNotify(notifyBase.value.trim())).base_url
    ElMessage.success('已保存，所有供应商的回调地址按新域名生成')
  } finally {
    notifySaving.value = false
  }
}

// ---- 下单设置 ----
const duplicateWindow = ref(10)
const orderSaving = ref(false)

async function saveOrder() {
  orderSaving.value = true
  try {
    duplicateWindow.value = (await settingApi.saveOrder(duplicateWindow.value)).duplicate_window_minutes
    ElMessage.success(duplicateWindow.value === 0 ? '已保存，不再拦截重复充值' : '已保存，新下的订单立即生效')
  } finally {
    orderSaving.value = false
  }
}

// ---- 支付宝转账加款 ----
const alipayLoading = ref(false)
const alipaySaving = ref(false)
const alipayTesting = ref(false)
const privateKeySet = ref(false)
const alipayFormRef = ref<FormInstance>()
const alipay = reactive({
  enabled: false,
  app_id: '',
  private_key: '',
  alipay_public_key: '',
  account: '',
  account_name: '',
  expire_minutes: 15,
  min_amount: '1.00',
  max_amount: '50000.00',
})

const alipayRules: FormRules = {
  app_id: [{ required: true, pattern: /^\d{16}$/, message: 'AppID 是 16 位数字', trigger: 'blur' }],
  alipay_public_key: [{ required: true, message: '请粘贴支付宝公钥', trigger: 'blur' }],
  account: [{ required: true, message: '请输入收款支付宝账号', trigger: 'blur' }],
  account_name: [{ required: true, message: '请输入收款人姓名', trigger: 'blur' }],
  min_amount: [{ required: true, message: '请输入单笔最低金额', trigger: 'blur' }],
  max_amount: [{ required: true, message: '请输入单笔最高金额', trigger: 'blur' }],
}

async function loadAlipay() {
  alipayLoading.value = true
  try {
    const { private_key_set, ...rest } = await settingApi.alipayTransfer()
    Object.assign(alipay, rest, { private_key: '' })
    privateKeySet.value = private_key_set
  } finally {
    alipayLoading.value = false
  }
}

async function saveAlipay() {
  if (!(await alipayFormRef.value?.validate().catch(() => false))) {
    return
  }
  if (!privateKeySet.value && !alipay.private_key) {
    ElMessage.warning('请粘贴应用私钥')
    return
  }
  alipaySaving.value = true
  try {
    const { private_key_set, ...rest } = await settingApi.saveAlipayTransfer({ ...alipay })
    Object.assign(alipay, rest, { private_key: '' })
    privateKeySet.value = private_key_set
    ElMessage.success('已保存，建议点「测试连接」确认一下')
  } finally {
    alipaySaving.value = false
  }
}

async function testAlipay() {
  alipayTesting.value = true
  try {
    const result = await settingApi.testAlipayTransfer()
    if (result.success) {
      ElMessage.success(result.message)
    } else {
      ElMessage.error({ message: `测试失败：${result.message}`, duration: 8000 })
    }
  } finally {
    alipayTesting.value = false
  }
}

// ---- 图片存储 ----
const loading = ref(false)
const saving = ref(false)
const testing = ref(false)
const secretKeySet = ref(false)
const formRef = ref<FormInstance>()
const form = reactive({ secret_id: '', secret_key: '', region: '', bucket: '', path_prefix: '' })

const rules: FormRules = {
  secret_id: [{ required: true, message: '请输入 SecretId', trigger: 'blur' }],
  region: [{ required: true, message: '请输入地域', trigger: 'blur' }],
  bucket: [{ required: true, message: '请输入存储桶名称', trigger: 'blur' }],
}

async function load() {
  loading.value = true
  try {
    const s = await settingApi.storage()
    Object.assign(form, { secret_id: s.secret_id, secret_key: '', region: s.region, bucket: s.bucket, path_prefix: s.path_prefix })
    secretKeySet.value = s.secret_key_set
  } finally {
    loading.value = false
  }
}

async function save() {
  if (!(await formRef.value?.validate().catch(() => false))) {
    return
  }
  if (!secretKeySet.value && !form.secret_key) {
    ElMessage.warning('请输入 SecretKey')
    return
  }
  saving.value = true
  try {
    const s = await settingApi.saveStorage({ ...form })
    secretKeySet.value = s.secret_key_set
    form.secret_key = ''
    ElMessage.success('已保存，建议点「测试连接」确认一下')
  } finally {
    saving.value = false
  }
}

async function test() {
  testing.value = true
  try {
    const result = await settingApi.testStorage()
    if (result.success) {
      ElMessage.success(result.message)
    } else {
      ElMessage.error({ message: `测试失败：${result.message}`, duration: 8000 })
    }
  } finally {
    testing.value = false
  }
}

onMounted(async () => {
  load()
  loadAlipay()
  settingApi.site().then((s) => (siteName.value = s.name))
  notifyBase.value = (await settingApi.notify()).base_url
  duplicateWindow.value = (await settingApi.order()).duplicate_window_minutes
})
</script>

<template>
  <el-card shadow="never" header="基本设置" class="block">
    <el-form label-width="110px" style="max-width: 560px" @submit.prevent="saveSite">
      <el-form-item label="系统名称">
        <el-input v-model="siteName" maxlength="30" show-word-limit :placeholder="`留空使用默认名称：${defaultName}`" />
        <div class="tip">显示在管理后台左上角、登录页和浏览器标题</div>
      </el-form-item>
      <el-form-item>
        <el-button type="primary" :loading="siteSaving" native-type="submit">保存</el-button>
      </el-form-item>
    </el-form>
  </el-card>

  <el-card shadow="never" header="回调域名" class="block">
    <el-form label-width="110px" style="max-width: 560px" @submit.prevent="saveNotify">
      <el-form-item label="回调域名">
        <el-input v-model="notifyBase" placeholder="如 https://pay.example.com，不带路径" />
        <div class="tip">
          供应商回调地址自动生成为「回调域名/notify/supplier/供应商编码」，新建供应商时不用再填。
          要填供应商能访问到的公网域名；改了之后，新提交的订单立即用新地址。
        </div>
      </el-form-item>
      <el-form-item>
        <el-button type="primary" :loading="notifySaving" @click="saveNotify">保存</el-button>
      </el-form-item>
    </el-form>
  </el-card>

  <el-card shadow="never" header="下单设置" class="block">
    <el-form label-width="110px" style="max-width: 560px" @submit.prevent="saveOrder">
      <el-form-item label="重复充值拦截">
        <el-input-number v-model="duplicateWindow" :min="0" :max="1440" :step="1" step-strictly controls-position="right" />
        <span class="unit">分钟</span>
        <div class="tip">
          同一号码、同一面值在这段时间内还有充值中的订单时，新的下单直接拒绝（「该号码 N 分钟内有正在处理的同面值订单」），
          批量下单预览里也会标出来。填 0 表示不拦截。
        </div>
      </el-form-item>
      <el-form-item>
        <el-button type="primary" :loading="orderSaving" @click="saveOrder">保存</el-button>
      </el-form-item>
    </el-form>
  </el-card>

  <el-card v-loading="alipayLoading" shadow="never" header="支付宝转账加款" class="block">
    <el-alert type="info" :closable="false" show-icon class="tip-bar">
      商户在加款页面扫码，用支付宝向下面的收款账号转账（金额带随机分、备注填支付单号），系统每分钟查一次收款账号的<b>账务明细</b>，
      金额对上就自动入账。需要在支付宝开放平台为<b>收款账号所在的企业账户</b>创建应用、开通账务明细查询权限，接口加签方式选<b>公钥</b>。
    </el-alert>
    <el-form ref="alipayFormRef" :model="alipay" :rules="alipayRules" label-width="110px" style="max-width: 640px">
      <el-form-item label="启用">
        <el-switch v-model="alipay.enabled" />
        <span class="unit">关闭后商户看不到这个入口，已发起的单照常核对</span>
      </el-form-item>
      <el-form-item label="AppID" prop="app_id">
        <el-input v-model="alipay.app_id" maxlength="16" placeholder="开放平台应用的 AppID" />
      </el-form-item>
      <el-form-item label="应用私钥">
        <el-input
          v-model="alipay.private_key"
          type="textarea"
          :rows="3"
          autocomplete="off"
          :placeholder="privateKeySet ? '已设置，不改请留空' : '必填，支付宝密钥工具生成的应用私钥'"
        />
      </el-form-item>
      <el-form-item label="支付宝公钥" prop="alipay_public_key">
        <el-input v-model="alipay.alipay_public_key" type="textarea" :rows="3" placeholder="开放平台「接口加签方式」里的支付宝公钥，不是应用公钥" />
      </el-form-item>
      <el-form-item label="收款账号" prop="account">
        <el-input v-model="alipay.account" maxlength="64" placeholder="手机号或邮箱，必须是创建应用的那个支付宝账户" />
      </el-form-item>
      <el-form-item label="收款人姓名" prop="account_name">
        <el-input v-model="alipay.account_name" maxlength="64" placeholder="商户页面只显示最后一个字" />
      </el-form-item>
      <el-form-item label="二维码有效期">
        <el-input-number v-model="alipay.expire_minutes" :min="5" :max="60" :step="5" controls-position="right" />
        <span class="unit">分钟，到期后还会再自动核对 10 分钟</span>
      </el-form-item>
      <el-form-item label="单笔金额" required>
        <el-input v-model="alipay.min_amount" style="width: 140px" placeholder="最低" />
        <span class="unit">~</span>
        <el-input v-model="alipay.max_amount" style="width: 140px; margin-left: 8px" placeholder="最高" />
        <span class="unit">元</span>
      </el-form-item>
      <el-form-item>
        <el-button type="primary" :loading="alipaySaving" @click="saveAlipay">保存</el-button>
        <el-button :loading="alipayTesting" @click="testAlipay">测试连接</el-button>
      </el-form-item>
    </el-form>
  </el-card>

  <el-card v-loading="loading" shadow="never" header="图片存储（腾讯云 COS）">
    <el-alert type="info" :closable="false" show-icon class="tip-bar">
      商户上传的打款凭证存在这里。存储桶请设为<b>私有读写</b>，图片只通过 10 分钟有效的临时链接查看；
      建议用子账号的 SecretId / SecretKey，只授予这个存储桶的读写权限。
    </el-alert>
    <el-form ref="formRef" :model="form" :rules="rules" label-width="110px" style="max-width: 560px">
      <el-form-item label="SecretId" prop="secret_id">
        <el-input v-model="form.secret_id" maxlength="128" />
      </el-form-item>
      <el-form-item label="SecretKey">
        <el-input
          v-model="form.secret_key"
          type="password"
          show-password
          autocomplete="new-password"
          :placeholder="secretKeySet ? '已设置，不改请留空' : '必填'"
        />
      </el-form-item>
      <el-form-item label="地域" prop="region">
        <el-input v-model="form.region" maxlength="32" placeholder="如 ap-guangzhou" />
      </el-form-item>
      <el-form-item label="存储桶名称" prop="bucket">
        <el-input v-model="form.bucket" maxlength="64" placeholder="带 APPID，如 phonebill-1250000000" />
      </el-form-item>
      <el-form-item label="路径前缀">
        <el-input v-model="form.path_prefix" maxlength="64" placeholder="选填，如 phonebill；多个环境共用一个桶时用来区分" />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" :loading="saving" @click="save">保存</el-button>
        <el-button :loading="testing" @click="test">测试连接</el-button>
      </el-form-item>
    </el-form>
  </el-card>
</template>

<style scoped>
.tip-bar {
  margin-bottom: 20px;
}

.block {
  margin-bottom: 16px;
}

.unit {
  margin-left: 8px;
  color: #606266;
}

.tip {
  color: #909399;
  font-size: 12px;
  line-height: 20px;
}
</style>
