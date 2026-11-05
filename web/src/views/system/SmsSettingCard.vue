<script setup lang="ts">
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { onMounted, reactive, ref } from 'vue'
import { settingApi } from '@/api/funding'

const loading = ref(false)
const saving = ref(false)
const testing = ref(false)
const secretSet = ref(false)
const testMobile = ref('')
const formRef = ref<FormInstance>()
const form = reactive({
  enabled: false,
  access_key_id: '',
  access_key_secret: '',
  sign_name: '',
  code_template: '',
  alert_template: '',
})

const template = { pattern: /^SMS_\d+$/, message: '格式如 SMS_123456789', trigger: 'blur' }
const rules: FormRules = {
  access_key_id: [{ required: true, message: '请输入 AccessKey ID', trigger: 'blur' }],
  sign_name: [{ required: true, message: '请输入短信签名', trigger: 'blur' }],
  code_template: [{ required: true, message: '请输入验证码模板', trigger: 'blur' }, template],
  alert_template: [{ required: true, message: '请输入余额提醒模板', trigger: 'blur' }, template],
}

async function load() {
  loading.value = true
  try {
    const s = await settingApi.sms()
    Object.assign(form, {
      enabled: s.enabled,
      access_key_id: s.access_key_id,
      access_key_secret: '',
      sign_name: s.sign_name,
      code_template: s.code_template,
      alert_template: s.alert_template,
    })
    secretSet.value = s.access_key_secret_set
  } finally {
    loading.value = false
  }
}

async function save() {
  if (!(await formRef.value?.validate().catch(() => false))) {
    return
  }
  if (!secretSet.value && !form.access_key_secret) {
    ElMessage.warning('请输入 AccessKey Secret')
    return
  }
  saving.value = true
  try {
    const s = await settingApi.saveSms({ ...form })
    secretSet.value = s.access_key_secret_set
    form.access_key_secret = ''
    ElMessage.success(s.enabled ? '已保存，建议发一条测试短信确认一下' : '已保存（未启用）')
  } finally {
    saving.value = false
  }
}

async function test() {
  if (!/^1[3-9]\d{9}$/.test(testMobile.value.trim())) {
    ElMessage.warning('请输入接收测试短信的手机号')
    return
  }
  testing.value = true
  try {
    const result = await settingApi.testSms(testMobile.value.trim())
    if (result.success) {
      ElMessage.success(result.message)
    } else {
      ElMessage.error({ message: `测试失败：${result.message}`, duration: 8000 })
    }
  } finally {
    testing.value = false
  }
}

onMounted(load)
</script>

<template>
  <el-card v-loading="loading" shadow="never" header="短信配置（阿里云短信）">
    <el-alert type="info" :closable="false" show-icon class="tip-bar">
      用于商户后台登录验证码、绑定手机和余额提醒。启用后，绑定了手机的商户账号登录时要再输入短信验证码；
      关闭后登录只校验密码、不能绑定手机、不发余额提醒。建议用 RAM 子账号的 AccessKey，只授予短信发送权限。
    </el-alert>
    <el-form ref="formRef" :model="form" :rules="rules" label-width="140px" style="max-width: 620px">
      <el-form-item label="启用">
        <el-switch v-model="form.enabled" />
      </el-form-item>
      <el-form-item label="AccessKey ID" prop="access_key_id">
        <el-input v-model="form.access_key_id" maxlength="128" />
      </el-form-item>
      <el-form-item label="AccessKey Secret">
        <el-input
          v-model="form.access_key_secret"
          type="password"
          show-password
          autocomplete="new-password"
          :placeholder="secretSet ? '已设置，不改请留空' : '必填'"
        />
      </el-form-item>
      <el-form-item label="短信签名" prop="sign_name">
        <el-input v-model="form.sign_name" maxlength="32" placeholder="审核通过的签名名称，如 某某科技" />
      </el-form-item>
      <el-form-item label="验证码模板" prop="code_template">
        <el-input v-model="form.code_template" maxlength="32" placeholder="如 SMS_123456789" />
        <div class="tip">登录验证、绑定手机共用，模板变量：<code>${code}</code>（6 位数字，5 分钟有效）</div>
      </el-form-item>
      <el-form-item label="余额提醒模板" prop="alert_template">
        <el-input v-model="form.alert_template" maxlength="32" placeholder="如 SMS_123456789" />
        <div class="tip">
          模板变量：<code>${name}</code> 商户名称、<code>${balance}</code> 当前余额、<code>${threshold}</code> 提醒阈值（用不到的可以不写进模板）。
          余额跌破阈值时发一次，充值回到阈值以上后再跌破会再发，每个商户每天最多 3 条。
        </div>
      </el-form-item>
      <el-form-item>
        <el-button type="primary" :loading="saving" @click="save">保存</el-button>
      </el-form-item>
      <el-form-item label="测试发送">
        <div class="test-row">
          <el-input v-model="testMobile" maxlength="11" placeholder="接收测试短信的手机号" />
          <el-button :loading="testing" @click="test">发送验证码短信</el-button>
        </div>
        <div class="tip">用已保存的配置发一条验证码短信（随机码，不能用于登录）。</div>
      </el-form-item>
    </el-form>
  </el-card>
</template>

<style scoped>
.tip-bar {
  margin-bottom: 20px;
}

.tip {
  color: #909399;
  font-size: 12px;
  line-height: 20px;
}

.test-row {
  display: flex;
  gap: 8px;
  width: 100%;
}
</style>
