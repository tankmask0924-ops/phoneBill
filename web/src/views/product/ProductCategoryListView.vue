<script setup lang="ts">
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { computed, onMounted, reactive, ref } from 'vue'
import { type ProductCategory, productCategoryApi } from '@/api/product'
import { usePermissionStore } from '@/stores/permission'

const permission = usePermissionStore()
const canManage = computed(() => permission.can('product.manage'))

const rows = ref<ProductCategory[]>([])
const loading = ref(false)

const dialogVisible = ref(false)
const editing = ref<ProductCategory | null>(null)
const saving = ref(false)
const formRef = ref<FormInstance>()
const form = reactive({ name: '', sort: 0, mobile_limit_days: 0, mobile_limit_orders: 0, mobile_limit_processing: 0, remark: '' })

/** 列表里的号码限制，如「1 天 1 单 / 同时 1 单」 */
function limitText(c: ProductCategory): string {
  const parts: string[] = []
  if (c.mobile_limit_days > 0 && c.mobile_limit_orders > 0) {
    parts.push(`${c.mobile_limit_days} 天 ${c.mobile_limit_orders} 单`)
  }
  if (c.mobile_limit_processing > 0) {
    parts.push(`同时 ${c.mobile_limit_processing} 单`)
  }
  return parts.join(' / ') || '不限'
}

/** 表单里实时说明当前的号码限制 */
const limitSummary = computed(() => {
  const parts: string[] = []
  if (form.mobile_limit_days > 0 && form.mobile_limit_orders > 0) {
    const period = form.mobile_limit_days === 1 ? '每天' : `每 ${form.mobile_limit_days} 天`
    parts.push(`同一号码${period}最多充 ${form.mobile_limit_orders} 单`)
  }
  if (form.mobile_limit_processing > 0) {
    parts.push(`同时最多 ${form.mobile_limit_processing} 单在充值中`)
  }
  return parts.length ? parts.join('，') : '不限制'
})
/** 周期和单数只填了一个 */
const limitIncomplete = computed(() => (form.mobile_limit_days > 0) !== (form.mobile_limit_orders > 0))

const rules: FormRules = {
  name: [{ required: true, message: '请输入分类名称', trigger: 'blur' }],
}

async function load() {
  loading.value = true
  try {
    rows.value = await productCategoryApi.list()
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editing.value = null
  Object.assign(form, { name: '', sort: 0, mobile_limit_days: 0, mobile_limit_orders: 0, mobile_limit_processing: 0, remark: '' })
  dialogVisible.value = true
}

function openEdit(row: ProductCategory) {
  editing.value = row
  Object.assign(form, {
    name: row.name,
    sort: row.sort,
    mobile_limit_days: row.mobile_limit_days,
    mobile_limit_orders: row.mobile_limit_orders,
    mobile_limit_processing: row.mobile_limit_processing,
    remark: row.remark ?? '',
  })
  dialogVisible.value = true
}

async function save() {
  if (!(await formRef.value?.validate().catch(() => false))) {
    return
  }
  saving.value = true
  try {
    if ((form.mobile_limit_days > 0) !== (form.mobile_limit_orders > 0)) {
      ElMessage.warning('号码限制的周期天数和单数要一起设置，都填 0 表示不限')
      return
    }
    const data = { ...form }
    if (editing.value) {
      await productCategoryApi.update(editing.value.id, data)
    } else {
      await productCategoryApi.create(data)
    }
    ElMessage.success('已保存')
    dialogVisible.value = false
    await load()
  } finally {
    saving.value = false
  }
}

async function remove(row: ProductCategory) {
  try {
    await ElMessageBox.confirm(`确定删除分类「${row.name}」吗？`, '删除', { type: 'warning' })
  } catch {
    return
  }
  await productCategoryApi.remove(row.id)
  ElMessage.success('已删除')
  await load()
}

onMounted(async () => {
  await permission.load()
  load()
})
</script>

<template>
  <el-card shadow="never">
    <div class="toolbar">
      <span class="tip">只用于平台商品，新建、编辑平台商品时必须选择分类</span>
      <el-button v-if="canManage" type="primary" :icon="Plus" @click="openCreate">新建分类</el-button>
    </div>

    <el-table v-loading="loading" :data="rows" border empty-text="还没有分类">
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="name" label="名称" min-width="140" />
      <el-table-column prop="sort" label="排序" width="80" align="right" />
      <el-table-column prop="product_count" label="平台商品数" width="100" align="right" />
      <el-table-column label="号码限制" min-width="150">
        <template #default="{ row }">
          <span :class="{ muted: limitText(row as ProductCategory) === '不限' }">{{ limitText(row as ProductCategory) }}</span>
        </template>
      </el-table-column>
      <el-table-column label="备注" min-width="160">
        <template #default="{ row }">{{ row.remark ?? '' }}</template>
      </el-table-column>
      <el-table-column prop="updated_at" label="更新时间" width="170" />
      <el-table-column v-if="canManage" label="操作" width="120" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row as ProductCategory)">编辑</el-button>
          <el-tooltip :disabled="row.product_count === 0" content="还有平台商品在用，不能删除" placement="top">
            <span class="action">
              <el-button link type="danger" :disabled="row.product_count > 0" @click="remove(row as ProductCategory)">
                删除
              </el-button>
            </span>
          </el-tooltip>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <el-dialog
    v-model="dialogVisible"
    :title="editing ? '编辑分类' : '新建分类'"
    width="min(600px, 94vw)"
    @closed="formRef?.clearValidate()"
  >
    <el-form ref="formRef" :model="form" :rules="rules" label-width="120px" class="category-form">
      <div class="section-title">基本信息</div>
      <el-form-item label="名称" prop="name">
        <el-input v-model="form.name" maxlength="32" show-word-limit placeholder="如：话费快充" />
      </el-form-item>
      <el-form-item label="排序">
        <el-input-number v-model="form.sort" :min="0" :max="9999" controls-position="right" class="num" />
        <span class="hint">数字越小越靠前</span>
      </el-form-item>
      <el-form-item label="备注">
        <el-input v-model="form.remark" type="textarea" :rows="2" maxlength="255" show-word-limit placeholder="选填" />
      </el-form-item>

      <div class="section-title">
        号码限制
        <span class="section-sub">只管这个分类下的商品，所有商户合计，0 表示不限</span>
      </div>
      <el-form-item label="限制周期">
        <el-input-number v-model="form.mobile_limit_days" :min="0" :max="366" controls-position="right" class="num" />
        <span class="unit">天</span>
        <span class="hint">按自然日算，1 = 当天 0 点起</span>
      </el-form-item>
      <el-form-item label="周期内最多充值">
        <el-input-number v-model="form.mobile_limit_orders" :min="0" :max="9999" controls-position="right" class="num" />
        <span class="unit">单</span>
        <span class="hint">同一号码，失败的单也算</span>
      </el-form-item>
      <el-form-item label="同时充值中最多">
        <el-input-number v-model="form.mobile_limit_processing" :min="0" :max="9999" controls-position="right" class="num" />
        <span class="unit">单</span>
        <span class="hint">还没出结果的单</span>
      </el-form-item>
      <el-form-item>
        <el-alert v-if="limitIncomplete" type="warning" :closable="false" show-icon title="限制周期和周期内单数要一起设置，都填 0 表示不限" />
        <el-alert v-else type="info" :closable="false" show-icon :title="`当前：${limitSummary}`" />
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
  align-items: center;
  margin-bottom: 16px;
}

.tip {
  color: #909399;
  font-size: 12px;
  margin-left: 8px;
}

.toolbar .tip {
  margin-left: 0;
}

.action {
  margin-left: 12px;
}

.muted {
  color: #909399;
}

.section-title {
  margin: 4px 0 16px;
  padding-left: 8px;
  border-left: 3px solid var(--el-color-primary);
  color: #303133;
  font-weight: 600;
  line-height: 18px;
}

.section-title:not(:first-child) {
  margin-top: 24px;
}

.section-sub {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
  font-weight: normal;
}

.category-form .num {
  width: 140px;
}

.unit {
  margin-left: 8px;
  color: #606266;
}

.hint {
  margin-left: 12px;
  color: #909399;
  font-size: 12px;
}
</style>
