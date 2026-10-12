<script setup lang="ts">
import StatusTag from '@/components/StatusTag.vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { computed, onMounted, reactive, ref } from 'vue'
import { fundingApi, type PaymentAccountType, type ReceivingAccount, receivingAccountApi } from '@/api/funding'
import { paymentAccountTypeLabels, supplierStatusLabels, toOptions } from '@/utils/labels'
import { usePermissionStore } from '@/stores/permission'

const permission = usePermissionStore()
const canConfig = computed(() => permission.can('funding.config'))

const rows = ref<ReceivingAccount[]>([])
const loading = ref(false)
const merchants = ref<{ id: number; name: string; status: string }[]>([])

async function load() {
  loading.value = true
  try {
    rows.value = await receivingAccountApi.list()
  } finally {
    loading.value = false
  }
}

// ---- 新建 / 编辑 ----
const dialogVisible = ref(false)
const editing = ref<ReceivingAccount | null>(null)
const saving = ref(false)
const formRef = ref<FormInstance>()
const form = reactive({ type: 'bank' as PaymentAccountType, account_name: '', account_no: '', bank_name: '', remark: '' })

const rules = computed<FormRules>(() => ({
  account_name: [{ required: true, message: form.type === 'bank' ? '请输入户名' : '请输入支付宝实名', trigger: 'blur' }],
  account_no: [{ required: true, message: '请输入账号', trigger: 'blur' }],
  bank_name: form.type === 'bank' ? [{ required: true, message: '请输入开户行', trigger: 'blur' }] : [],
}))

function openCreate() {
  editing.value = null
  Object.assign(form, { type: 'bank', account_name: '', account_no: '', bank_name: '', remark: '' })
  dialogVisible.value = true
}

function openEdit(row: ReceivingAccount) {
  editing.value = row
  Object.assign(form, { type: row.type, account_name: row.account_name, account_no: row.account_no, bank_name: row.bank_name ?? '', remark: row.remark ?? '' })
  dialogVisible.value = true
}

async function save() {
  if (!(await formRef.value?.validate().catch(() => false))) {
    return
  }
  saving.value = true
  try {
    const data = { ...form }
    if (editing.value) {
      await receivingAccountApi.update(editing.value.id, data)
    } else {
      await receivingAccountApi.create(data)
    }
    ElMessage.success('已保存')
    dialogVisible.value = false
    await load()
  } finally {
    saving.value = false
  }
}

async function toggleStatus(row: ReceivingAccount) {
  const next = row.status === 'active' ? 'disabled' : 'active'
  try {
    await ElMessageBox.confirm(
      next === 'disabled' ? '停用后商户提交加款申请时不能再选这个账户（已提交的申请不受影响），确定停用吗？' : '确定启用这个收款账户吗？',
      next === 'disabled' ? '停用' : '启用',
      { type: 'warning' },
    )
  } catch {
    return
  }
  await receivingAccountApi.changeStatus(row.id, next)
  ElMessage.success(next === 'disabled' ? '已停用' : '已启用')
  await load()
}

// ---- 分配商户 ----
const assignVisible = ref(false)
const assigning = ref<ReceivingAccount | null>(null)
const assignIds = ref<number[]>([])
const assignSaving = ref(false)

function openAssign(row: ReceivingAccount) {
  assigning.value = row
  assignIds.value = [...row.merchant_ids]
  assignVisible.value = true
}

async function saveAssign() {
  assignSaving.value = true
  try {
    await receivingAccountApi.assignMerchants(assigning.value!.id, assignIds.value)
    ElMessage.success('已保存')
    assignVisible.value = false
    await load()
  } finally {
    assignSaving.value = false
  }
}

onMounted(async () => {
  await permission.load()
  load()
  merchants.value = await fundingApi.merchantOptions()
})
</script>

<template>
  <el-card shadow="never">
    <div class="toolbar">
      <span class="tip">平台收商户打款的账户，只支持支付宝和对公账户。分配给商户后，商户提交加款申请时才能选。</span>
      <el-button v-if="canConfig" type="primary" :icon="Plus" @click="openCreate">新建收款账户</el-button>
    </div>

    <el-table v-loading="loading" :data="rows" border empty-text="还没有收款账户">
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="type_label" label="类型" width="90" />
      <el-table-column prop="account_name" label="户名 / 实名" min-width="140" />
      <el-table-column prop="account_no" label="账号" min-width="180" />
      <el-table-column label="开户行" min-width="150">
        <template #default="{ row }">{{ row.bank_name ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="分配的商户" min-width="200">
        <template #default="{ row }">
          <span v-if="row.merchant_names.length === 0" class="muted">未分配</span>
          <el-tag v-for="name in row.merchant_names" v-else :key="name" size="small" class="merchant">{{ name }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="80">
        <template #default="{ row }"><StatusTag :map="supplierStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column label="备注" min-width="120">
        <template #default="{ row }">{{ row.remark ?? '' }}</template>
      </el-table-column>
      <el-table-column v-if="canConfig" label="操作" width="180" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row as ReceivingAccount)">编辑</el-button>
          <el-button link type="primary" @click="openAssign(row as ReceivingAccount)">分配商户</el-button>
          <el-button link :type="row.status === 'active' ? 'danger' : 'success'" @click="toggleStatus(row as ReceivingAccount)">
            {{ row.status === 'active' ? '停用' : '启用' }}
          </el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <el-dialog v-model="dialogVisible" :title="editing ? '编辑收款账户' : '新建收款账户'" width="520px" @closed="formRef?.clearValidate()">
    <el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
      <el-form-item label="类型">
        <el-radio-group v-model="form.type">
          <el-radio-button v-for="o in toOptions(paymentAccountTypeLabels)" :key="o.value" :value="o.value">{{ o.label }}</el-radio-button>
        </el-radio-group>
      </el-form-item>
      <el-form-item :label="form.type === 'bank' ? '户名' : '支付宝实名'" prop="account_name">
        <el-input v-model="form.account_name" maxlength="64" />
      </el-form-item>
      <el-form-item :label="form.type === 'bank' ? '对公账号' : '支付宝账号'" prop="account_no">
        <el-input v-model="form.account_no" maxlength="64" :placeholder="form.type === 'bank' ? '银行账号，只填数字' : '手机号或邮箱'" />
      </el-form-item>
      <el-form-item v-if="form.type === 'bank'" label="开户行" prop="bank_name">
        <el-input v-model="form.bank_name" maxlength="64" placeholder="写到支行，如：招商银行深圳科技园支行" />
      </el-form-item>
      <el-form-item label="备注">
        <el-input v-model="form.remark" maxlength="255" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="save">保存</el-button>
    </template>
  </el-dialog>

  <el-dialog v-model="assignVisible" title="分配给商户" width="520px">
    <div v-if="assigning" class="assign-account">{{ assigning.type_label }} · {{ assigning.account_name }} · {{ assigning.account_no }}</div>
    <el-select v-model="assignIds" multiple filterable placeholder="选择商户，可多选" style="width: 100%">
      <el-option v-for="m in merchants" :key="m.id" :label="m.status === 'active' ? m.name : `${m.name}（已停用）`" :value="m.id" />
    </el-select>
    <template #footer>
      <el-button @click="assignVisible = false">取消</el-button>
      <el-button type="primary" :loading="assignSaving" @click="saveAssign">保存</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}

.tip,
.muted {
  color: #909399;
  font-size: 12px;
}

.merchant {
  margin: 2px 4px 2px 0;
}

.assign-account {
  margin-bottom: 12px;
  color: #606266;
}
</style>
