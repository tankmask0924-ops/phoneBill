<script setup lang="ts">
import StatusTag from '@/components/StatusTag.vue'
import { usePagedList } from '@/utils/paged'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { computed, onMounted, reactive, ref } from 'vue'
import type { SupplierOption } from '@/api/product'
import { type Maintenance, riskApi } from '@/api/risk'
import { labelOf, maintenanceStateLabels, operatorLabels, toOptions } from '@/utils/labels'
import { usePermissionStore } from '@/stores/permission'

const permission = usePermissionStore()
const canManage = computed(() => permission.can('risk.manage'))

const list = usePagedList<Maintenance, { supplier_id: number | ''; state: string }>(riskApi.maintenances, {
  supplier_id: '',
  state: 'active',
})
const { rows, total, page, perPage, loading, filters } = list

const suppliers = ref<SupplierOption[]>([])
const provinces = ref<string[]>([])

const dialogVisible = ref(false)
const saving = ref(false)
const form = reactive({
  supplier_id: undefined as number | undefined,
  operator: '',
  province: '',
  range: null as [string, string] | null,
  reason: '',
})

function openCreate() {
  const now = new Date()
  const later = new Date(now.getTime() + 2 * 3600 * 1000)
  Object.assign(form, { supplier_id: undefined, operator: '', province: '', range: [fmt(now), fmt(later)], reason: '' })
  dialogVisible.value = true
}

function fmt(d: Date): string {
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:00`
}

async function save() {
  if (!form.supplier_id) {
    ElMessage.error('请选择供应商')
    return
  }
  if (!form.range) {
    ElMessage.error('请选择维护时间')
    return
  }
  saving.value = true
  try {
    await riskApi.addMaintenance({
      supplier_id: form.supplier_id,
      operator: form.operator,
      province: form.province,
      start_at: form.range[0],
      end_at: form.range[1],
      reason: form.reason,
    })
    ElMessage.success('已添加，维护时间内这个通道不参与选路')
    dialogVisible.value = false
    await list.load()
  } finally {
    saving.value = false
  }
}

async function finish(row: Maintenance) {
  try {
    await ElMessageBox.confirm('提前结束后这个通道马上恢复选路，确定结束吗？', '结束维护', { type: 'warning' })
  } catch {
    return
  }
  await riskApi.finishMaintenance(row.id)
  ElMessage.success('已结束')
  await list.load()
}

onMounted(async () => {
  await permission.load()
  list.load()
  const options = await riskApi.maintenanceOptions()
  suppliers.value = options.suppliers
  provinces.value = options.provinces
})
</script>

<template>
  <el-card shadow="never">
    <div class="toolbar">
      <el-form inline @submit.prevent="list.search">
        <el-form-item>
          <el-select v-model="filters.supplier_id" placeholder="全部供应商" clearable filterable style="width: 160px">
            <el-option v-for="s in suppliers" :key="s.id" :label="s.name" :value="s.id" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-select v-model="filters.state" placeholder="全部" clearable style="width: 110px">
            <el-option v-for="o in toOptions(maintenanceStateLabels)" :key="o.value" v-bind="o" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" native-type="submit">查询</el-button>
        </el-form-item>
      </el-form>
      <el-button v-if="canManage" type="primary" :icon="Plus" @click="openCreate">添加维护</el-button>
    </div>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="supplier_name" label="供应商" min-width="140" />
      <el-table-column label="运营商" width="100">
        <template #default="{ row }">{{ row.operator ? labelOf(operatorLabels, row.operator) : '全部' }}</template>
      </el-table-column>
      <el-table-column label="省份" width="100">
        <template #default="{ row }">{{ row.province ?? '全部' }}</template>
      </el-table-column>
      <el-table-column prop="start_at" label="开始" width="170" />
      <el-table-column prop="end_at" label="结束（自动恢复）" width="170" />
      <el-table-column label="状态" width="90">
        <template #default="{ row }"><StatusTag :map="maintenanceStateLabels" :value="row.state" /></template>
      </el-table-column>
      <el-table-column label="原因" min-width="160">
        <template #default="{ row }">{{ row.reason ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="添加人" width="110">
        <template #default="{ row }">{{ row.admin_name ?? '-' }}</template>
      </el-table-column>
      <el-table-column v-if="canManage" label="操作" width="100">
        <template #default="{ row }">
          <el-button v-if="row.state !== 'ended'" link type="primary" @click="finish(row as Maintenance)">提前结束</el-button>
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

  <el-dialog v-model="dialogVisible" title="添加通道维护" width="560px">
    <el-form label-width="90px">
      <el-form-item label="供应商" required>
        <el-select v-model="form.supplier_id" filterable style="width: 100%">
          <el-option v-for="s in suppliers" :key="s.id" :label="s.name" :value="s.id" />
        </el-select>
      </el-form-item>
      <el-form-item label="运营商">
        <el-select v-model="form.operator" placeholder="全部运营商" clearable style="width: 160px">
          <el-option v-for="o in toOptions(operatorLabels)" :key="o.value" v-bind="o" />
        </el-select>
      </el-form-item>
      <el-form-item label="省份">
        <el-select v-model="form.province" placeholder="全部省份" clearable filterable style="width: 160px">
          <el-option v-for="p in provinces" :key="p" :label="p" :value="p" />
        </el-select>
      </el-form-item>
      <el-form-item label="维护时间" required>
        <el-date-picker
          v-model="form.range"
          type="datetimerange"
          value-format="YYYY-MM-DD HH:mm:ss"
          start-placeholder="开始"
          end-placeholder="结束"
        />
        <div class="tip">到结束时间自动恢复；已经提交出去的订单不受影响</div>
      </el-form-item>
      <el-form-item label="原因">
        <el-input v-model="form.reason" maxlength="255" placeholder="如：运营商系统割接" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="save">添加</el-button>
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

.tip {
  color: #909399;
  font-size: 12px;
  width: 100%;
}
</style>
