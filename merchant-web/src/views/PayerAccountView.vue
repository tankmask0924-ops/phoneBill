<script setup lang="ts">
import StatusTag from '@/components/StatusTag.vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { computed, onMounted, reactive, ref } from 'vue'
import { fundingApi, type PayerAccount, type PaymentAccountType } from '@/api/portal'
import { fundingStatusLabels, paymentAccountTypeLabels, toOptions } from '@/utils/labels'

const rows = ref<PayerAccount[]>([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    rows.value = await fundingApi.payerAccounts()
  } finally {
    loading.value = false
  }
}

const dialogVisible = ref(false)
const editing = ref<PayerAccount | null>(null)
const saving = ref(false)
const formRef = ref<FormInstance>()
const form = reactive({ type: 'bank' as PaymentAccountType, account_name: '', account_no: '', bank_name: '' })

const rules = computed<FormRules>(() => ({
  account_name: [{ required: true, message: form.type === 'bank' ? '请输入户名' : '请输入支付宝实名', trigger: 'blur' }],
  account_no: [{ required: true, message: '请输入账号', trigger: 'blur' }],
  bank_name: form.type === 'bank' ? [{ required: true, message: '请输入开户行', trigger: 'blur' }] : [],
}))

function openCreate() {
  editing.value = null
  Object.assign(form, { type: 'bank', account_name: '', account_no: '', bank_name: '' })
  dialogVisible.value = true
}

function openEdit(row: PayerAccount) {
  editing.value = row
  Object.assign(form, { type: row.type, account_name: row.account_name, account_no: row.account_no, bank_name: row.bank_name ?? '' })
  dialogVisible.value = true
}

async function save() {
  if (!(await formRef.value?.validate().catch(() => false))) {
    return
  }
  saving.value = true
  try {
    if (editing.value) {
      await fundingApi.updatePayer(editing.value.id, { ...form })
    } else {
      await fundingApi.createPayer({ ...form })
    }
    ElMessage.success('已提交，等待平台审核')
    dialogVisible.value = false
    await load()
  } finally {
    saving.value = false
  }
}

async function remove(row: PayerAccount) {
  try {
    await ElMessageBox.confirm(`确定删除付款账号「${row.account_name} ${row.account_no}」吗？已提交的加款申请不受影响。`, '删除', { type: 'warning' })
  } catch {
    return
  }
  await fundingApi.removePayer(row.id)
  ElMessage.success('已删除')
  await load()
}

onMounted(load)
</script>

<template>
  <el-card shadow="never">
    <div class="toolbar">
      <span class="tip">你们用来给平台打款的账户，只支持支付宝和对公账户。新增、修改后要平台审核，审核通过才能在加款申请里选。</span>
      <el-button type="primary" :icon="Plus" @click="openCreate">添加付款账号</el-button>
    </div>

    <el-table v-loading="loading" :data="rows" border empty-text="还没有付款账号，先添加一个给平台审核">
      <el-table-column prop="type_label" label="类型" width="90" />
      <el-table-column prop="account_name" label="户名 / 实名" min-width="140" />
      <el-table-column prop="account_no" label="账号" min-width="180" />
      <el-table-column label="开户行" min-width="150">
        <template #default="{ row }">{{ row.bank_name ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="审核状态" min-width="160">
        <template #default="{ row }">
          <StatusTag :map="fundingStatusLabels" :value="row.status" />
          <div v-if="row.status === 'rejected' && row.reject_reason" class="reason">驳回原因：{{ row.reject_reason }}</div>
        </template>
      </el-table-column>
      <el-table-column prop="updated_at" label="提交 / 修改时间" width="170" />
      <el-table-column label="操作" width="120" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row as PayerAccount)">修改</el-button>
          <el-button link type="danger" @click="remove(row as PayerAccount)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <el-dialog v-model="dialogVisible" :title="editing ? '修改付款账号' : '添加付款账号'" width="520px" @closed="formRef?.clearValidate()">
    <el-alert v-if="editing && editing.status === 'approved'" type="warning" :closable="false" show-icon class="edit-tip">
      修改后要重新审核，审核通过前不能用这个账号提交加款申请。
    </el-alert>
    <el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
      <el-form-item label="类型">
        <el-radio-group v-model="form.type">
          <el-radio-button v-for="o in toOptions(paymentAccountTypeLabels)" :key="o.value" :value="o.value">{{ o.label }}</el-radio-button>
        </el-radio-group>
      </el-form-item>
      <el-form-item :label="form.type === 'bank' ? '户名' : '支付宝实名'" prop="account_name">
        <el-input v-model="form.account_name" maxlength="64" :placeholder="form.type === 'bank' ? '公司全称' : ''" />
      </el-form-item>
      <el-form-item :label="form.type === 'bank' ? '对公账号' : '支付宝账号'" prop="account_no">
        <el-input v-model="form.account_no" maxlength="64" :placeholder="form.type === 'bank' ? '银行账号，只填数字' : '手机号或邮箱'" />
      </el-form-item>
      <el-form-item v-if="form.type === 'bank'" label="开户行" prop="bank_name">
        <el-input v-model="form.bank_name" maxlength="64" placeholder="写到支行" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="save">提交审核</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  margin-bottom: 16px;
}

.tip {
  color: #909399;
  font-size: 12px;
}

.reason {
  margin-top: 4px;
  color: #f56c6c;
  font-size: 12px;
}

.edit-tip {
  margin-bottom: 16px;
}
</style>
