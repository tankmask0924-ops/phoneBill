<script setup lang="ts">
import StatusTag from '@/components/StatusTag.vue'
import { formatDuration } from '@/utils/date'
import { usePagedList } from '@/utils/paged'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { Plus, WarningFilled } from '@element-plus/icons-vue'
import { computed, onMounted, reactive, ref } from 'vue'
import { type OperatorCode, type Product, productApi, type SupplierProduct } from '@/api/product'
import { labelOf, operatorLabels, shelfStatusLabels, toOptions } from '@/utils/labels'
import { usePermissionStore } from '@/stores/permission'

const permission = usePermissionStore()
const canManage = computed(() => permission.can('product.manage'))

const list = usePagedList<Product, { keyword: string; face_value: string; status: string }>(productApi.list, {
  keyword: '',
  face_value: '',
  status: '',
})
const { rows, total, page, perPage, loading, filters } = list

const operatorOrder = Object.keys(operatorLabels) as OperatorCode[]

interface RouteRow {
  supplier_product_id: number
  priority: number
  supplier_product: SupplierProduct
}

const dialogVisible = ref(false)
const editing = ref<Product | null>(null)
const saving = ref(false)
const formRef = ref<FormInstance>()
const form = reactive({
  code: '',
  name: '',
  face_value: undefined as number | undefined,
  remark: '',
  routes: [] as RouteRow[],
  prices: {} as Partial<Record<OperatorCode, string>>,
})

/** 同面值的供应商商品，绑定时从这里选 */
const options = ref<SupplierProduct[]>([])
const optionsLoading = ref(false)
const picking = ref<number | undefined>()
const unboundOptions = computed(() =>
  options.value.filter((o) => !form.routes.some((r) => r.supplier_product_id === o.id)),
)

/** 覆盖的运营商：绑定的供应商商品支持的运营商合起来 */
const covered = computed(() => {
  const set = new Set(form.routes.flatMap((r) => r.supplier_product.operators))
  return operatorOrder.filter((op) => set.has(op))
})

const rules = computed<FormRules>(() => ({
  code: editing.value
    ? []
    : [
        { required: true, message: '请输入商品编码', trigger: 'blur' },
        { pattern: /^[A-Za-z0-9_-]{1,32}$/, message: '1~32 位字母、数字、_ 或 -', trigger: 'blur' },
      ],
  name: [{ required: true, message: '请输入商品名称', trigger: 'blur' }],
  face_value: [{ required: true, message: '请输入面值', trigger: 'blur' }],
}))

async function loadOptions() {
  options.value = []
  picking.value = undefined
  if (!form.face_value) {
    return
  }
  optionsLoading.value = true
  try {
    options.value = await productApi.supplierProductOptions(form.face_value)
  } finally {
    optionsLoading.value = false
  }
}

function onFaceValueChange() {
  // 面值变了，已选的绑定都不再匹配
  form.routes = []
  form.prices = {}
  loadOptions()
}

function addRoute() {
  const option = options.value.find((o) => o.id === picking.value)
  if (!option) {
    return
  }
  const maxPriority = Math.max(0, ...form.routes.map((r) => r.priority))
  form.routes.push({ supplier_product_id: option.id, priority: maxPriority + 1, supplier_product: option })
  picking.value = undefined
}

function removeRoute(index: number) {
  form.routes.splice(index, 1)
}

function optionLabel(o: SupplierProduct): string {
  const ops = o.operators.map((op) => labelOf(operatorLabels, op)).join('/')
  const off = o.supplier_status !== 'active' ? '（供应商已停用）' : o.status !== 'active' ? '（已下架）' : ''
  return `${o.supplier_name} · ${o.name} · ${ops} · 成本 ${o.cost_price}${off}`
}

function openCreate() {
  editing.value = null
  Object.assign(form, { code: '', name: '', face_value: undefined, remark: '', routes: [], prices: {} })
  options.value = []
  dialogVisible.value = true
}

function openEdit(row: Product) {
  editing.value = row
  Object.assign(form, {
    code: row.code,
    name: row.name,
    face_value: row.face_value,
    remark: row.remark ?? '',
    routes: row.routes
      .filter((r) => r.supplier_product !== null)
      .map((r) => ({ supplier_product_id: r.supplier_product_id, priority: r.priority, supplier_product: r.supplier_product })),
    prices: { ...row.prices },
  })
  dialogVisible.value = true
  loadOptions()
}

async function save() {
  if (!(await formRef.value?.validate().catch(() => false))) {
    return
  }
  // 只提交覆盖的运营商的默认售价
  const prices: Partial<Record<OperatorCode, string>> = {}
  for (const op of covered.value) {
    const price = form.prices[op]?.trim()
    if (price) {
      if (!/^\d{1,9}(\.\d{1,2})?$/.test(price)) {
        ElMessage.error(`${labelOf(operatorLabels, op)}默认售价格式不对，最多两位小数`)
        return
      }
      prices[op] = price
    }
  }
  const data = {
    name: form.name,
    remark: form.remark,
    routes: form.routes.map((r) => ({ supplier_product_id: r.supplier_product_id, priority: r.priority })),
    prices,
  }
  saving.value = true
  try {
    const saved = editing.value
      ? await productApi.update(editing.value.id, data)
      : await productApi.create({ ...data, code: form.code, face_value: form.face_value })
    if (saved.warnings.length > 0) {
      ElMessage.warning({ message: `已保存，但有 ${saved.warnings.length} 条提醒，请在列表里查看`, duration: 4000 })
    } else {
      ElMessage.success('已保存')
    }
    dialogVisible.value = false
    await list.load()
  } finally {
    saving.value = false
  }
}

async function toggleStatus(row: Product) {
  const next = row.status === 'active' ? 'disabled' : 'active'
  try {
    await ElMessageBox.confirm(
      next === 'disabled' ? `下架后商户不能再买「${row.name}」，确定下架吗？` : `确定上架「${row.name}」吗？`,
      next === 'disabled' ? '下架' : '上架',
      { type: 'warning' },
    )
  } catch {
    return
  }
  await productApi.changeStatus(row.id, next)
  ElMessage.success(next === 'disabled' ? '已下架' : '已上架')
  await list.load()
}

onMounted(async () => {
  await permission.load()
  list.load()
})
</script>

<template>
  <el-card shadow="never">
    <div class="toolbar">
      <el-form inline @submit.prevent="list.search">
        <el-form-item>
          <el-input v-model="filters.keyword" placeholder="名称 / 商品编码" clearable style="width: 180px" />
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
      <el-button v-if="canManage" type="primary" :icon="Plus" @click="openCreate">新建平台商品</el-button>
    </div>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="code" label="商品编码" min-width="110" />
      <el-table-column label="名称" min-width="140">
        <template #default="{ row }">
          {{ row.name }}
          <el-tooltip v-if="row.warnings.length > 0" placement="top">
            <template #content>
              <div v-for="w in row.warnings" :key="w">{{ w }}</div>
            </template>
            <el-icon class="warn"><WarningFilled /></el-icon>
          </el-tooltip>
        </template>
      </el-table-column>
      <el-table-column label="面值" width="80" align="right">
        <template #default="{ row }">{{ row.face_value }} 元</template>
      </el-table-column>
      <el-table-column label="默认售价" min-width="200">
        <template #default="{ row }">
          <span v-if="row.operators.length === 0" class="muted">未绑定供应商商品</span>
          <span v-for="op in row.operators" :key="op" class="price">
            {{ labelOf(operatorLabels, op) }} {{ row.prices[op] ?? '未设' }}
          </span>
        </template>
      </el-table-column>
      <el-table-column label="绑定" width="70" align="center">
        <template #default="{ row }">{{ row.routes.length }}</template>
      </el-table-column>
      <el-table-column label="昨日成功率" width="100" align="right">
        <template #default="{ row }">
          {{ row.yesterday_stats?.success_rate != null ? `${row.yesterday_stats.success_rate}%` : '-' }}
        </template>
      </el-table-column>
      <el-table-column label="昨日平均耗时" width="110" align="right">
        <template #default="{ row }">{{ formatDuration(row.yesterday_stats?.avg_duration) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="80">
        <template #default="{ row }"><StatusTag :map="shelfStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column label="操作" width="120" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row as Product)">{{ canManage ? '编辑' : '查看' }}</el-button>
          <el-button
            v-if="canManage"
            link
            :type="row.status === 'active' ? 'danger' : 'success'"
            @click="toggleStatus(row as Product)"
          >
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

  <el-dialog
    v-model="dialogVisible"
    :title="editing ? (canManage ? '编辑平台商品' : '查看平台商品') : '新建平台商品'"
    width="860px"
    @closed="formRef?.clearValidate()"
  >
    <el-form ref="formRef" :model="form" :rules="rules" label-width="96px" :disabled="!canManage">
      <el-row :gutter="16">
        <el-col :span="12">
          <el-form-item label="商品编码" prop="code">
            <el-input v-model="form.code" :disabled="!!editing" placeholder="商户下单时传，建好后不能改" />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="名称" prop="name">
            <el-input v-model="form.name" maxlength="64" placeholder="如：新话费100" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-form-item label="面值（元）" prop="face_value">
        <el-input-number
          v-model="form.face_value"
          :min="1"
          :max="100000"
          :step="10"
          :disabled="!!editing"
          @change="onFaceValueChange"
        />
        <span class="tip">只能绑定同面值的供应商商品，建好后不能改</span>
      </el-form-item>

      <el-form-item label="供应商商品">
        <div class="routes">
          <el-select
            v-if="canManage"
            v-model="picking"
            :loading="optionsLoading"
            :disabled="!form.face_value"
            :placeholder="form.face_value ? '选择要绑定的供应商商品，选中即加入下表' : '先填写面值'"
            filterable
            class="pick"
            @change="addRoute"
          >
            <el-option v-for="o in unboundOptions" :key="o.id" :label="optionLabel(o)" :value="o.id" />
          </el-select>
          <el-table :data="form.routes" border size="small" empty-text="还没有绑定，商户下单会被拒绝">
            <el-table-column label="供应商" min-width="110">
              <template #default="{ row }">
                {{ row.supplier_product.supplier_name }}
                <el-tag v-if="row.supplier_product.supplier_status !== 'active'" size="small" type="info">已停用</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="供应商商品" min-width="120">
              <template #default="{ row }">
                {{ row.supplier_product.name }}
                <el-tag v-if="row.supplier_product.status !== 'active'" size="small" type="info">已下架</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="运营商" min-width="100">
              <template #default="{ row }">
                {{ row.supplier_product.operators.map((op: string) => labelOf(operatorLabels, op)).join('、') }}
              </template>
            </el-table-column>
            <el-table-column label="成本价" width="80" align="right">
              <template #default="{ row }">{{ row.supplier_product.cost_price }}</template>
            </el-table-column>
            <el-table-column label="优先级" width="130">
              <template #default="{ row }">
                <el-input-number v-model="row.priority" :min="0" :max="9999" size="small" controls-position="right" />
              </template>
            </el-table-column>
            <el-table-column v-if="canManage" label="" width="60" align="center">
              <template #default="{ $index }">
                <el-button link type="danger" @click="removeRoute($index)">移除</el-button>
              </template>
            </el-table-column>
          </el-table>
          <div class="tip">优先级数字越小越先用；相同时先用成本低的。只有支持该号码运营商、覆盖该号码省份的才会参与选路。</div>
        </div>
      </el-form-item>

      <el-form-item label="默认售价">
        <div v-if="covered.length === 0" class="tip">绑定供应商商品后，按覆盖的运营商分别设置</div>
        <div v-else class="prices">
          <div v-for="op in covered" :key="op" class="price-input">
            <span class="price-label">{{ labelOf(operatorLabels, op) }}</span>
            <el-input v-model="form.prices[op]" placeholder="不设可留空" style="width: 120px" />
          </div>
          <div class="tip">只用来给商户开通商品时带出初始价格，开通时可以改</div>
        </div>
      </el-form-item>
      <el-form-item label="备注">
        <el-input v-model="form.remark" maxlength="255" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">{{ canManage ? '取消' : '关闭' }}</el-button>
      <el-button v-if="canManage" type="primary" :loading="saving" @click="save">保存</el-button>
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

.warn {
  color: #e6a23c;
  vertical-align: middle;
  margin-left: 4px;
}

.price {
  margin-right: 12px;
  white-space: nowrap;
}

.muted,
.tip {
  color: #909399;
  font-size: 12px;
}

.tip {
  line-height: 20px;
  margin-left: 8px;
}

.routes {
  width: 100%;
}

.routes .tip {
  margin-left: 0;
}

.pick {
  width: 100%;
  margin-bottom: 8px;
}

.prices {
  width: 100%;
}

.price-input {
  display: inline-flex;
  align-items: center;
  margin: 0 16px 8px 0;
}

.price-label {
  margin-right: 8px;
}

.prices .tip {
  margin-left: 0;
}
</style>
