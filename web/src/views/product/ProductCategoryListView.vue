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
const form = reactive({ name: '', sort: 0, remark: '' })

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
  Object.assign(form, { name: '', sort: 0, remark: '' })
  dialogVisible.value = true
}

function openEdit(row: ProductCategory) {
  editing.value = row
  Object.assign(form, { name: row.name, sort: row.sort, remark: row.remark ?? '' })
  dialogVisible.value = true
}

async function save() {
  if (!(await formRef.value?.validate().catch(() => false))) {
    return
  }
  saving.value = true
  try {
    const data = { name: form.name, sort: form.sort, remark: form.remark }
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
    width="480px"
    @closed="formRef?.clearValidate()"
  >
    <el-form ref="formRef" :model="form" :rules="rules" label-width="80px">
      <el-form-item label="名称" prop="name">
        <el-input v-model="form.name" maxlength="32" placeholder="如：话费快充" />
      </el-form-item>
      <el-form-item label="排序">
        <el-input-number v-model="form.sort" :min="0" :max="9999" controls-position="right" />
        <span class="tip">数字越小越靠前</span>
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
</style>
