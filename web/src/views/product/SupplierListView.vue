<script setup lang="ts">
import StatusTag from '@/components/StatusTag.vue'
import { usePagedList } from '@/utils/paged'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { Plus, Refresh } from '@element-plus/icons-vue'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { type Supplier, supplierApi, type SupplierDriver } from '@/api/product'
import { supplierStatusLabels, toOptions } from '@/utils/labels'
import { usePermissionStore } from '@/stores/permission'

const permission = usePermissionStore()
const canManage = computed(() => permission.can('supplier.manage'))

const list = usePagedList<Supplier, { keyword: string; status: string }>(supplierApi.list, {
  keyword: '',
  status: '',
})
const { rows, total, page, perPage, loading, filters } = list

const drivers = ref<SupplierDriver[]>([])

// ---- 余额：列表加载后逐个实时查，同时最多 3 个 ----
type BalanceState = { loading: boolean; supported?: boolean; balance?: string | null; error?: string | null }
const balances = reactive<Record<number, BalanceState>>({})
const balanceLoading = ref(false)

async function loadBalances() {
  const ids = rows.value.map((r) => r.id)
  ids.forEach((id) => (balances[id] = { loading: true }))
  balanceLoading.value = true
  let next = 0
  const worker = async () => {
    while (next < ids.length) {
      const id = ids[next++]
      try {
        balances[id] = { loading: false, ...(await supplierApi.balance(id)) }
      } catch {
        balances[id] = { loading: false, error: '查询失败' }
      }
    }
  }
  await Promise.all([worker(), worker(), worker()])
  balanceLoading.value = false
}

watch(rows, loadBalances)

const dialogVisible = ref(false)
const editing = ref<Supplier | null>(null)
const saving = ref(false)
const formRef = ref<FormInstance>()
const form = reactive({
  name: '',
  code: '',
  driver: '',
  config: {} as Record<string, string>,
  remark: '',
})
const currentDriver = computed(() => drivers.value.find((d) => d.code === form.driver))
// 编辑时密钥不回显；没换驱动且已经填过的密钥可以留空
const secretKept = (key: string) =>
  !!editing.value && editing.value.driver === form.driver && editing.value.configured_secrets.includes(key)

const rules = computed<FormRules>(() => ({
  name: [{ required: true, message: '请输入供应商名称', trigger: 'blur' }],
  code: editing.value
    ? []
    : [
        { required: true, message: '请输入编码', trigger: 'blur' },
        { pattern: /^[a-z0-9_]{2,32}$/, message: '2~32 位小写字母、数字或下划线', trigger: 'blur' },
      ],
  driver: [{ required: true, message: '请选择对接驱动', trigger: 'change' }],
}))

function onDriverChange() {
  const config: Record<string, string> = {}
  for (const field of currentDriver.value?.config_schema ?? []) {
    config[field.key] = editing.value?.driver === form.driver ? (editing.value.config[field.key] ?? '') : ''
    // 必填的下拉默认选第一项
    if (field.type === 'select' && field.required && !config[field.key]) {
      config[field.key] = field.options?.[0]?.value ?? ''
    }
  }
  form.config = config
}

function openCreate() {
  editing.value = null
  Object.assign(form, { name: '', code: '', driver: drivers.value[0]?.code ?? '', remark: '' })
  onDriverChange()
  dialogVisible.value = true
}

function openEdit(row: Supplier) {
  editing.value = row
  Object.assign(form, {
    name: row.name,
    code: row.code,
    driver: row.driver,
    remark: row.remark ?? '',
  })
  onDriverChange()
  dialogVisible.value = true
}

async function save() {
  if (!(await formRef.value?.validate().catch(() => false))) {
    return
  }
  for (const field of currentDriver.value?.config_schema ?? []) {
    if (field.required && !form.config[field.key] && !secretKept(field.key)) {
      ElMessage.error(`请填写${field.label}`)
      return
    }
  }
  const data = {
    name: form.name,
    driver: form.driver,
    config: { ...form.config },
    remark: form.remark,
  }
  saving.value = true
  try {
    if (editing.value) {
      await supplierApi.update(editing.value.id, data)
    } else {
      await supplierApi.create({ ...data, code: form.code })
    }
    ElMessage.success('已保存')
    dialogVisible.value = false
    await list.load()
  } finally {
    saving.value = false
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

async function toggleStatus(row: Supplier) {
  const next = row.status === 'active' ? 'disabled' : 'active'
  try {
    await ElMessageBox.confirm(
      next === 'disabled' ? `停用后「${row.name}」的商品都不再参与选路，确定停用吗？` : `确定启用「${row.name}」吗？`,
      next === 'disabled' ? '停用供应商' : '启用供应商',
      { type: 'warning' },
    )
  } catch {
    return
  }
  await supplierApi.changeStatus(row.id, next)
  ElMessage.success(next === 'disabled' ? '已停用' : '已启用')
  await list.load()
}

async function remove(row: Supplier) {
  try {
    await ElMessageBox.confirm(`删除后不再显示，历史订单仍保留名字。确定删除供应商「${row.name}」吗？`, '删除', { type: 'warning' })
  } catch {
    return
  }
  await supplierApi.remove(row.id)
  ElMessage.success('已删除')
  await list.load()
}

onMounted(async () => {
  await permission.load()
  list.load()
  const meta = await supplierApi.meta()
  drivers.value = meta.drivers
})
</script>

<template>
  <el-card shadow="never">
    <div class="toolbar">
      <el-form inline @submit.prevent="list.search">
        <el-form-item>
          <el-input v-model="filters.keyword" placeholder="名称 / 编码" clearable style="width: 180px" />
        </el-form-item>
        <el-form-item>
          <el-select v-model="filters.status" placeholder="全部状态" clearable style="width: 110px">
            <el-option v-for="o in toOptions(supplierStatusLabels)" :key="o.value" v-bind="o" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" native-type="submit">查询</el-button>
        </el-form-item>
      </el-form>
      <div>
        <el-button :icon="Refresh" :loading="balanceLoading" @click="loadBalances">刷新余额</el-button>
        <el-button v-if="canManage" type="primary" :icon="Plus" @click="openCreate">新建供应商</el-button>
      </div>
    </div>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="name" label="名称" min-width="140" />
      <el-table-column prop="code" label="编码" min-width="120" />
      <el-table-column prop="driver_name" label="对接驱动" min-width="150" />
      <el-table-column label="回调地址" min-width="260">
        <template #default="{ row }">
          <template v-if="row.notify_url">
            <span class="mono">{{ row.notify_url }}</span>
            <el-button link type="primary" class="copy" @click="copy(row.notify_url)">复制</el-button>
          </template>
          <span v-else class="warn">未生成</span>
          <el-tooltip v-if="!row.notify_url_generated" content="还没在「系统管理 - 系统配置」填回调域名，暂用以前手填的地址" placement="top">
            <el-tag size="small" type="warning" class="copy">待配置</el-tag>
          </el-tooltip>
        </template>
      </el-table-column>
      <el-table-column label="余额（元）" width="130" align="right">
        <template #default="{ row }">
          <span v-if="!balances[row.id] || balances[row.id].loading" class="muted">查询中…</span>
          <el-tooltip v-else-if="balances[row.id].error" :content="balances[row.id].error ?? ''" placement="top">
            <span class="error">查询失败</span>
          </el-tooltip>
          <span v-else-if="!balances[row.id].supported" class="muted">不支持</span>
          <b v-else>{{ balances[row.id].balance }}</b>
        </template>
      </el-table-column>
      <el-table-column prop="product_count" label="商品数" width="80" align="center" />
      <el-table-column label="状态" width="80">
        <template #default="{ row }"><StatusTag :map="supplierStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column label="备注" min-width="120">
        <template #default="{ row }">{{ row.remark ?? '-' }}</template>
      </el-table-column>
      <el-table-column v-if="canManage" label="操作" width="160" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row as Supplier)">编辑</el-button>
          <el-button link :type="row.status === 'active' ? 'danger' : 'success'" @click="toggleStatus(row as Supplier)">
            {{ row.status === 'active' ? '停用' : '启用' }}
          </el-button>
          <el-tooltip :disabled="row.product_count === 0" content="名下还有供应商商品，先删除它们" placement="top">
            <span class="action">
              <el-button link type="danger" :disabled="row.product_count > 0" @click="remove(row as Supplier)">删除</el-button>
            </span>
          </el-tooltip>
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
  </el-card>

  <el-dialog v-model="dialogVisible" :title="editing ? '编辑供应商' : '新建供应商'" width="640px" @closed="formRef?.clearValidate()">
    <el-form ref="formRef" :model="form" :rules="rules" label-width="96px">
      <el-form-item label="名称" prop="name">
        <el-input v-model="form.name" maxlength="64" />
      </el-form-item>
      <el-form-item label="编码" prop="code">
        <el-input v-model="form.code" :disabled="!!editing" placeholder="用在供应商回调地址里，建好后不能改" />
        <div class="tip">
          回调地址自动生成：回调域名/notify/supplier/{{ form.code || '编码' }}（回调域名在「系统管理 - 系统配置」设置），保存后在列表里复制给供应商
        </div>
      </el-form-item>
      <el-form-item label="对接驱动" prop="driver">
        <el-select v-model="form.driver" style="width: 100%" @change="onDriverChange">
          <el-option v-for="d in drivers" :key="d.code" :label="d.name" :value="d.code" />
        </el-select>
      </el-form-item>
      <el-form-item
        v-for="field in currentDriver?.config_schema ?? []"
        :key="field.key"
        :label="field.label"
        :required="field.required && !secretKept(field.key)"
      >
        <el-select v-if="field.type === 'select'" v-model="form.config[field.key]" style="width: 100%">
          <el-option v-for="o in field.options ?? []" :key="o.value" :label="o.label" :value="o.value" />
        </el-select>
        <el-input
          v-else-if="field.type === 'secret'"
          v-model="form.config[field.key]"
          type="password"
          show-password
          autocomplete="new-password"
          :placeholder="secretKept(field.key) ? '已设置，留空表示不修改' : (field.placeholder ?? '')"
        />
        <el-input v-else v-model="form.config[field.key]" :placeholder="field.placeholder ?? ''" />
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
</template>

<style scoped>
.action {
  margin-left: 12px;
}

.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}

.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}

.mono {
  font-family: monospace;
  font-size: 12px;
}

.copy {
  margin-left: 6px;
}

.warn {
  color: #e6a23c;
}

.muted {
  color: #909399;
}

.error {
  color: #f56c6c;
  cursor: help;
}

.tip {
  color: #909399;
  font-size: 12px;
  line-height: 20px;
}
</style>
