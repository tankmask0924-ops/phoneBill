<script setup lang="ts">
import StatusTag from '@/components/StatusTag.vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules, type UploadRawFile, type UploadRequestOptions } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { onMounted, reactive, ref } from 'vue'
import { type AlipayAccount, alipayAccountApi } from '@/api/funding'
import { supplierStatusLabels } from '@/utils/labels'

// 系统配置 - 支付宝转账加款账户（可以多个），商户用哪个在商户列表的「收款配置」里指定
const accounts = ref<AlipayAccount[]>([])
const loading = ref(false)
const testingId = ref<number | null>(null)

async function load() {
  loading.value = true
  try {
    accounts.value = await alipayAccountApi.list()
  } finally {
    loading.value = false
  }
}

const dialogVisible = ref(false)
const editing = ref<AlipayAccount | null>(null)
const saving = ref(false)
const formRef = ref<FormInstance>()
const form = reactive({
  name: '',
  app_id: '',
  private_key: '',
  alipay_public_key: '',
  account: '',
  account_name: '',
  qr_code: '',
  min_amount: '1.00',
  max_amount: '50000.00',
  remark: '',
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入账户名称', trigger: 'blur' }],
  app_id: [{ required: true, pattern: /^\d{16}$/, message: 'AppID 是 16 位数字', trigger: 'blur' }],
  alipay_public_key: [{ required: true, message: '请粘贴支付宝公钥', trigger: 'blur' }],
  account: [{ required: true, message: '请输入收款支付宝账号', trigger: 'blur' }],
  account_name: [{ required: true, message: '请输入收款人姓名', trigger: 'blur' }],
  min_amount: [{ required: true, message: '请输入单笔最低金额', trigger: 'blur' }],
  max_amount: [{ required: true, message: '请输入单笔最高金额', trigger: 'blur' }],
}

function openForm(row: AlipayAccount | null) {
  editing.value = row
  Object.assign(
    form,
    row
      ? {
          name: row.name,
          app_id: row.app_id,
          private_key: '',
          alipay_public_key: row.alipay_public_key,
          account: row.account,
          account_name: row.account_name,
          qr_code: '',
          min_amount: row.min_amount,
          max_amount: row.max_amount,
          remark: row.remark ?? '',
        }
      : {
          name: '',
          app_id: '',
          private_key: '',
          alipay_public_key: '',
          account: '',
          account_name: '',
          qr_code: '',
          min_amount: '1.00',
          max_amount: '50000.00',
          remark: '',
        },
  )
  qrPreview.value = row?.qr_code_url ?? ''
  dialogVisible.value = true
}

// 收款码：选好图片立即上传，保存时只传 key；编辑时不重新上传就不改
const qrPreview = ref('')
const uploadingQr = ref(false)

function beforeQrUpload(file: UploadRawFile) {
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

async function uploadQr(options: UploadRequestOptions) {
  uploadingQr.value = true
  try {
    const result = await alipayAccountApi.uploadQrCode(options.file)
    form.qr_code = result.key
    qrPreview.value = result.url
  } finally {
    uploadingQr.value = false
  }
}

async function save() {
  if (!(await formRef.value?.validate().catch(() => false))) {
    return
  }
  if (!editing.value && !form.private_key.trim()) {
    ElMessage.warning('请粘贴应用私钥')
    return
  }
  if (!form.qr_code && !editing.value?.qr_code) {
    ElMessage.warning('请上传收款码')
    return
  }
  saving.value = true
  try {
    if (editing.value) {
      await alipayAccountApi.update(editing.value.id, { ...form })
    } else {
      await alipayAccountApi.create({ ...form })
    }
    ElMessage.success(editing.value ? '已保存' : '已新建，建议点「测试连接」确认一下，再到商户列表的「收款配置」里指定给商户')
    dialogVisible.value = false
    await load()
  } finally {
    saving.value = false
  }
}

async function toggle(row: AlipayAccount) {
  const disable = row.status === 'active'
  if (disable) {
    try {
      await ElMessageBox.confirm(`停用「${row.name}」后，指定用它的商户不能再提交支付宝转账，已提交的还能重新查询。`, '停用账户', {
        type: 'warning',
      })
    } catch {
      return
    }
  }
  await alipayAccountApi.changeStatus(row.id, disable ? 'disabled' : 'active')
  ElMessage.success(disable ? '已停用' : '已启用')
  await load()
}

async function test(row: AlipayAccount) {
  testingId.value = row.id
  try {
    const result = await alipayAccountApi.test(row.id)
    if (result.success) {
      ElMessage.success(result.message)
    } else {
      ElMessage.error({ message: `测试失败：${result.message}`, duration: 8000 })
    }
  } finally {
    testingId.value = null
  }
}

onMounted(load)
</script>

<template>
  <el-card v-loading="loading" shadow="never">
    <template #header>
      <div class="header">
        <span>支付宝转账加款账户</span>
        <el-button type="primary" size="small" :icon="Plus" @click="openForm(null)">新建账户</el-button>
      </div>
    </template>
    <el-alert type="info" :closable="false" show-icon class="tip-bar">
      商户在加款页面扫这里上传的<b>固定收款码</b>转账，转完填上支付宝<b>订单号</b>和金额，系统按订单号查收款账号的<b>账务明细</b>，
      金额对上就立即入账。每个账户要在支付宝开放平台为<b>收款账号所在的企业账户</b>创建应用、开通账务明细查询权限，接口加签方式选<b>公钥</b>；
      收款码必须是这个收款账号的。
      商户用哪个账户在「商户中心 - 商户」的「收款配置」里指定，没指定的商户看不到这个入口。
    </el-alert>
    <el-table :data="accounts" border empty-text="还没有支付宝转账加款账户">
      <el-table-column prop="name" label="账户名称" min-width="130" />
      <el-table-column label="收款账号" min-width="190">
        <template #default="{ row }">
          {{ row.account }}
          <div class="muted">{{ row.account_name }} · AppID {{ row.app_id }}</div>
        </template>
      </el-table-column>
      <el-table-column label="单笔金额" width="150">
        <template #default="{ row }">{{ row.min_amount }} ~ {{ row.max_amount }}</template>
      </el-table-column>
      <el-table-column label="收款码" width="80" align="center">
        <template #default="{ row }">
          <el-image v-if="row.qr_code_url" :src="row.qr_code_url" :preview-src-list="[row.qr_code_url]" preview-teleported fit="contain" class="qr-thumb" />
          <span v-else class="muted">{{ row.qr_code ? '加载失败' : '未上传' }}</span>
        </template>
      </el-table-column>
      <el-table-column label="指定的商户" min-width="160">
        <template #default="{ row }">
          <span v-if="row.merchant_names.length === 0" class="muted">还没有</span>
          <el-tooltip v-else :content="row.merchant_names.join('、')" placement="top">
            <span>{{ row.merchant_names.slice(0, 3).join('、') }}{{ row.merchant_names.length > 3 ? ` 等 ${row.merchant_names.length} 个` : '' }}</span>
          </el-tooltip>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="80">
        <template #default="{ row }"><StatusTag :map="supplierStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column label="操作" width="180" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openForm(row as AlipayAccount)">编辑</el-button>
          <el-button link type="primary" :loading="testingId === row.id" @click="test(row as AlipayAccount)">测试连接</el-button>
          <el-button link :type="row.status === 'active' ? 'danger' : 'success'" @click="toggle(row as AlipayAccount)">
            {{ row.status === 'active' ? '停用' : '启用' }}
          </el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <el-dialog v-model="dialogVisible" :title="editing ? '编辑支付宝账户' : '新建支付宝账户'" width="640px" :close-on-click-modal="false" @closed="formRef?.clearValidate()">
    <el-form ref="formRef" :model="form" :rules="rules" label-width="110px">
      <el-form-item label="账户名称" prop="name">
        <el-input v-model="form.name" maxlength="64" placeholder="后台区分用，如：公司 A 支付宝" />
      </el-form-item>
      <el-form-item label="AppID" prop="app_id">
        <el-input v-model="form.app_id" maxlength="16" placeholder="开放平台应用的 AppID" />
      </el-form-item>
      <el-form-item label="应用私钥" :required="!editing">
        <el-input
          v-model="form.private_key"
          type="textarea"
          :rows="3"
          autocomplete="off"
          :placeholder="editing ? '已设置，不改请留空' : '支付宝密钥工具生成的应用私钥'"
        />
      </el-form-item>
      <el-form-item label="支付宝公钥" prop="alipay_public_key">
        <el-input v-model="form.alipay_public_key" type="textarea" :rows="3" placeholder="开放平台「接口加签方式」里的支付宝公钥，不是应用公钥" />
      </el-form-item>
      <el-form-item label="收款账号" prop="account">
        <el-input v-model="form.account" maxlength="64" placeholder="手机号或邮箱，必须是创建应用的那个支付宝账户" />
      </el-form-item>
      <el-form-item label="收款人姓名" prop="account_name">
        <el-input v-model="form.account_name" maxlength="64" placeholder="商户页面只显示最后一个字" />
      </el-form-item>
      <el-form-item label="收款码" required>
        <el-upload
          v-loading="uploadingQr"
          class="qr-upload"
          :show-file-list="false"
          accept="image/jpeg,image/png,image/webp"
          :http-request="uploadQr"
          :before-upload="beforeQrUpload"
        >
          <img v-if="qrPreview" :src="qrPreview" class="qr-preview" alt="收款码" />
          <el-icon v-else class="qr-placeholder"><Plus /></el-icon>
        </el-upload>
        <div class="muted qr-tip">这个收款账号的支付宝收款码截图，商户扫它转账，点图片可以换一张</div>
      </el-form-item>
      <el-form-item label="单笔金额" required>
        <el-input v-model="form.min_amount" style="width: 140px" placeholder="最低" />
        <span class="unit">~</span>
        <el-input v-model="form.max_amount" style="width: 140px; margin-left: 8px" placeholder="最高" />
        <span class="unit">元</span>
      </el-form-item>
      <el-form-item label="备注">
        <el-input v-model="form.remark" maxlength="255" placeholder="选填" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="save">保存</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.tip-bar {
  margin-bottom: 16px;
}

.muted {
  color: #909399;
  font-size: 12px;
}

.unit {
  margin-left: 8px;
  color: #606266;
}

.qr-thumb {
  width: 48px;
  height: 48px;
}

.qr-upload :deep(.el-upload) {
  width: 148px;
  height: 148px;
  border: 1px dashed var(--el-border-color);
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}

.qr-preview {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.qr-placeholder {
  font-size: 28px;
  color: #8c939d;
}

.qr-tip {
  width: 100%;
  margin-top: 4px;
}
</style>
