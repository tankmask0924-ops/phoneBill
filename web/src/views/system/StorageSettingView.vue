<script setup lang="ts">
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { onMounted, reactive, ref } from 'vue'
import { settingApi } from '@/api/funding'

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
  notifyBase.value = (await settingApi.notify()).base_url
})
</script>

<template>
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

.tip {
  color: #909399;
  font-size: 12px;
  line-height: 20px;
}
</style>
