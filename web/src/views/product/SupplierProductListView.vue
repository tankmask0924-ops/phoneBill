<script setup lang="ts">
import StatusTag from '@/components/StatusTag.vue'
import { usePagedList } from '@/utils/paged'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { Plus, Refresh } from '@element-plus/icons-vue'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import {
  type OperatorCode,
  type SupplierOption,
  type SupplierProduct,
  type UpstreamProduct,
  supplierProductApi,
} from '@/api/product'
import { labelOf, operatorLabels, shelfStatusLabels, toOptions } from '@/utils/labels'
import { usePermissionStore } from '@/stores/permission'

const permission = usePermissionStore()
const canManage = computed(() => permission.can('supplier_product.manage'))

const list = usePagedList<
  SupplierProduct,
  { keyword: string; supplier_id: number | ''; operator: string; face_value: string; status: string }
>(supplierProductApi.list, { keyword: '', supplier_id: '', operator: '', face_value: '', status: '' })
const { rows, total, page, perPage, loading, filters } = list

const suppliers = ref<SupplierOption[]>([])

const dialogVisible = ref(false)
const editing = ref<SupplierProduct | null>(null)
const saving = ref(false)
const formRef = ref<FormInstance>()
const form = reactive({
  supplier_id: undefined as number | undefined,
  name: '',
  face_value: undefined as number | undefined,
  cost_price: '',
  external_code: '',
  operators: [] as OperatorCode[],
  remark: '',
})
// 已经被平台商品绑定的，面值不能改
const faceValueLocked = computed(() => (editing.value?.bound_product_count ?? 0) > 0)

// 供应商提供了商品查询接口时，从上游商品列表里选，自动填写表单
const supportsUpstream = computed(
  () => suppliers.value.find((s) => s.id === form.supplier_id)?.supports_upstream_products ?? false,
)
const upstream = ref<UpstreamProduct[]>([])
const upstreamLoading = ref(false)
const upstreamPick = ref('')

async function loadUpstream() {
  upstream.value = []
  upstreamPick.value = ''
  const supplierId = form.supplier_id
  if (!supplierId || !supportsUpstream.value) {
    return
  }
  upstreamLoading.value = true
  try {
    const products = await supplierProductApi.upstreamProducts(supplierId)
    // 加载期间换了供应商，丢掉旧结果
    if (form.supplier_id === supplierId) {
      upstream.value = products
      // 编辑时标出当前对应的上游商品（只是显示，不触发自动填写）
      if (products.some((p) => p.code === form.external_code)) {
        upstreamPick.value = form.external_code
      }
    }
  } catch {
    // 拦截器已经提示了错误，这里保持列表为空，可以手动填编码
  } finally {
    upstreamLoading.value = false
  }
}

watch(
  () => [dialogVisible.value, form.supplier_id] as const,
  ([visible]) => {
    if (visible) {
      loadUpstream()
    }
  },
)

function onPickUpstream(code: string) {
  const picked = upstream.value.find((p) => p.code === code)
  if (!picked) {
    return
  }
  form.external_code = picked.code
  if (picked.name) {
    form.name = picked.name.slice(0, 64)
  }
  const price = picked.price === null ? NaN : Number(picked.price)
  if (Number.isFinite(price) && price > 0) {
    form.cost_price = price.toFixed(2)
  }
  if (picked.face_value !== null && !faceValueLocked.value) {
    form.face_value = picked.face_value
  }
  if (picked.operators.length > 0) {
    form.operators = [...picked.operators]
  }
}

const rules: FormRules = {
  supplier_id: [{ required: true, message: '请选择供应商', trigger: 'change' }],
  name: [{ required: true, message: '请输入商品名称', trigger: 'blur' }],
  face_value: [{ required: true, message: '请输入面值', trigger: 'blur' }],
  cost_price: [
    { required: true, message: '请输入成本价', trigger: 'blur' },
    { pattern: /^\d{1,9}(\.\d{1,2})?$/, message: '金额最多两位小数', trigger: 'blur' },
  ],
  external_code: [{ required: true, message: '请输入供应商商品编码', trigger: 'blur' }],
  operators: [{ type: 'array', required: true, min: 1, message: '请至少选择一个运营商', trigger: 'change' }],
}

function openCreate() {
  editing.value = null
  Object.assign(form, {
    supplier_id: filters.supplier_id || undefined,
    name: '',
    face_value: undefined,
    cost_price: '',
    external_code: '',
    operators: [],
    remark: '',
  })
  dialogVisible.value = true
}

function openEdit(row: SupplierProduct) {
  editing.value = row
  Object.assign(form, {
    supplier_id: row.supplier_id,
    name: row.name,
    face_value: row.face_value,
    cost_price: row.cost_price,
    external_code: row.external_code,
    operators: [...row.operators],
    remark: row.remark ?? '',
  })
  dialogVisible.value = true
}

async function save() {
  if (!(await formRef.value?.validate().catch(() => false))) {
    return
  }
  const data = {
    name: form.name,
    face_value: form.face_value as number,
    cost_price: form.cost_price,
    external_code: form.external_code,
    operators: form.operators,
    remark: form.remark,
  }
  saving.value = true
  try {
    if (editing.value) {
      await supplierProductApi.update(editing.value.id, data)
    } else {
      await supplierProductApi.create({ ...data, supplier_id: form.supplier_id })
    }
    ElMessage.success('已保存')
    dialogVisible.value = false
    await list.load()
  } finally {
    saving.value = false
  }
}

async function toggleStatus(row: SupplierProduct) {
  const next = row.status === 'active' ? 'disabled' : 'active'
  try {
    await ElMessageBox.confirm(
      next === 'disabled' ? `下架后「${row.name}」不再参与选路，确定下架吗？` : `确定上架「${row.name}」吗？`,
      next === 'disabled' ? '下架' : '上架',
      { type: 'warning' },
    )
  } catch {
    return
  }
  await supplierProductApi.changeStatus(row.id, next)
  ElMessage.success(next === 'disabled' ? '已下架' : '已上架')
  await list.load()
}

onMounted(async () => {
  await permission.load()
  list.load()
  suppliers.value = await supplierProductApi.supplierOptions()
})
</script>

<template>
  <el-card shadow="never">
    <div class="toolbar">
      <el-form inline @submit.prevent="list.search">
        <el-form-item>
          <el-input v-model="filters.keyword" placeholder="名称 / 供应商编码" clearable style="width: 170px" />
        </el-form-item>
        <el-form-item>
          <el-select v-model="filters.supplier_id" placeholder="全部供应商" clearable filterable style="width: 150px">
            <el-option v-for="s in suppliers" :key="s.id" :label="s.name" :value="s.id" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-select v-model="filters.operator" placeholder="运营商" clearable style="width: 100px">
            <el-option v-for="o in toOptions(operatorLabels)" :key="o.value" v-bind="o" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-input v-model="filters.face_value" placeholder="面值" clearable style="width: 90px" />
        </el-form-item>
        <el-form-item>
          <el-select v-model="filters.status" placeholder="全部状态" clearable style="width: 100px">
            <el-option v-for="o in toOptions(shelfStatusLabels)" :key="o.value" v-bind="o" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" native-type="submit">查询</el-button>
        </el-form-item>
      </el-form>
      <el-button v-if="canManage" type="primary" :icon="Plus" @click="openCreate">新建供应商商品</el-button>
    </div>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column label="供应商" min-width="130">
        <template #default="{ row }">
          {{ row.supplier_name ?? '-' }}
          <el-tag v-if="row.supplier_status === 'disabled'" size="small" type="info">已停用</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="name" label="商品名称" min-width="140" />
      <el-table-column label="运营商" min-width="120">
        <template #default="{ row }">
          <el-tag v-for="op in row.operators" :key="op" size="small" class="op">{{ labelOf(operatorLabels, op) }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="面值" width="80" align="right">
        <template #default="{ row }">{{ row.face_value }} 元</template>
      </el-table-column>
      <el-table-column prop="cost_price" label="成本价" width="90" align="right" />
      <el-table-column prop="external_code" label="供应商商品编码" min-width="130" />
      <el-table-column prop="bound_product_count" label="被绑定" width="80" align="center" />
      <el-table-column label="状态" width="80">
        <template #default="{ row }"><StatusTag :map="shelfStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column v-if="canManage" label="操作" width="120" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row as SupplierProduct)">编辑</el-button>
          <el-button link :type="row.status === 'active' ? 'danger' : 'success'" @click="toggleStatus(row as SupplierProduct)">
            {{ row.status === 'active' ? '下架' : '上架' }}
          </el-button>
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

  <el-dialog v-model="dialogVisible" :title="editing ? '编辑供应商商品' : '新建供应商商品'" width="520px" @closed="formRef?.clearValidate()">
    <el-form ref="formRef" :model="form" :rules="rules" label-width="110px">
      <el-form-item label="供应商" prop="supplier_id">
        <el-select v-model="form.supplier_id" :disabled="!!editing" filterable style="width: 100%">
          <el-option v-for="s in suppliers" :key="s.id" :label="s.name" :value="s.id" />
        </el-select>
      </el-form-item>
      <el-form-item v-if="supportsUpstream" label="上游商品">
        <div class="upstream">
          <el-select
            v-model="upstreamPick"
            filterable
            :loading="upstreamLoading"
            placeholder="从上游商品列表里选"
            no-data-text="上游没有返回商品"
            class="upstream-select"
            @change="onPickUpstream"
          >
            <el-option
              v-for="p in upstream"
              :key="p.code"
              :value="p.code"
              :label="`${p.name}（${p.code}）`"
              :disabled="!p.on_sale"
            >
              <span>{{ p.name }}</span>
              <span class="upstream-meta">
                {{ p.code }}<template v-if="p.price"> · ¥{{ p.price }}</template><template v-if="p.note"> · {{ p.note }}</template>
              </span>
              <el-tag v-if="!p.on_sale" size="small" type="info" class="upstream-tag">下架</el-tag>
              <el-tag v-else-if="p.added" size="small" type="warning" class="upstream-tag">已添加</el-tag>
            </el-option>
          </el-select>
          <el-button :icon="Refresh" :loading="upstreamLoading" title="重新查询" @click="loadUpstream" />
        </div>
        <div class="tip upstream-tip">选择后自动填写名称、成本价，上游提供时还有面值和运营商，保存前请核对</div>
      </el-form-item>
      <el-form-item label="商品名称" prop="name">
        <el-input v-model="form.name" maxlength="64" />
      </el-form-item>
      <el-form-item label="支持的运营商" prop="operators">
        <el-checkbox-group v-model="form.operators">
          <el-checkbox v-for="o in toOptions(operatorLabels)" :key="o.value" :value="o.value">{{ o.label }}</el-checkbox>
        </el-checkbox-group>
      </el-form-item>
      <el-form-item label="面值（元）" prop="face_value">
        <el-input-number v-model="form.face_value" :min="1" :max="100000" :step="10" :disabled="faceValueLocked" />
        <div v-if="faceValueLocked" class="tip">已被平台商品绑定，面值不能修改</div>
      </el-form-item>
      <el-form-item label="成本价（元）" prop="cost_price">
        <el-input v-model="form.cost_price" style="width: 150px" />
      </el-form-item>
      <el-form-item label="供应商商品编码" prop="external_code">
        <el-input v-model="form.external_code" maxlength="64" placeholder="调用供应商接口时传的商品编码" />
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
.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}

.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}

.op {
  margin-right: 4px;
}

.tip {
  color: #909399;
  font-size: 12px;
  margin-left: 12px;
}

.upstream {
  display: flex;
  gap: 8px;
  width: 100%;
}

.upstream-select {
  flex: 1;
  min-width: 0;
}

.upstream-tip {
  margin-left: 0;
  line-height: 1.6;
}

.upstream-meta {
  color: #909399;
  font-size: 12px;
  margin-left: 8px;
}

.upstream-tag {
  margin-left: 8px;
}
</style>
