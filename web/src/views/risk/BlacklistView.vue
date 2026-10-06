<script setup lang="ts">
import { usePagedList } from '@/utils/paged'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { computed, onMounted, reactive, ref } from 'vue'
import { type BlacklistItem, riskApi } from '@/api/risk'
import { usePermissionStore } from '@/stores/permission'

const permission = usePermissionStore()
const canManage = computed(() => permission.can('risk.manage'))

const list = usePagedList<BlacklistItem, { mobile: string }>(riskApi.blacklist, { mobile: '' }, 20)
const { rows, total, page, perPage, loading, filters } = list

const dialogVisible = ref(false)
const saving = ref(false)
const form = reactive({ mobiles: '', reason: '' })

function openCreate() {
  Object.assign(form, { mobiles: '', reason: '' })
  dialogVisible.value = true
}

async function save() {
  if (form.mobiles.trim() === '') {
    ElMessage.error('请输入手机号')
    return
  }
  saving.value = true
  try {
    const result = await riskApi.addBlacklist({ ...form })
    ElMessage.success(result.skipped > 0 ? `已加入 ${result.added} 个，${result.skipped} 个原本就在黑名单里` : `已加入 ${result.added} 个`)
    dialogVisible.value = false
    await list.search()
  } finally {
    saving.value = false
  }
}

async function remove(row: BlacklistItem) {
  try {
    await ElMessageBox.confirm(`移出黑名单后「${row.mobile}」可以正常下单，确定移出吗？`, '移出黑名单', { type: 'warning' })
  } catch {
    return
  }
  await riskApi.removeBlacklist(row.id)
  ElMessage.success('已移出')
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
          <el-input v-model="filters.mobile" placeholder="手机号（前几位即可）" clearable style="width: 200px" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" native-type="submit">查询</el-button>
        </el-form-item>
      </el-form>
      <el-button v-if="canManage" type="primary" :icon="Plus" @click="openCreate">加入黑名单</el-button>
    </div>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="mobile" label="手机号" width="150" />
      <el-table-column label="原因" min-width="200">
        <template #default="{ row }">{{ row.reason ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="添加人" width="120">
        <template #default="{ row }">{{ row.admin_name ?? '-' }}</template>
      </el-table-column>
      <el-table-column prop="created_at" label="添加时间" width="170" />
      <el-table-column v-if="canManage" label="操作" width="90">
        <template #default="{ row }">
          <el-button link type="danger" @click="remove(row as BlacklistItem)">移出</el-button>
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

  <el-dialog v-model="dialogVisible" title="加入黑名单" width="460px">
    <el-form label-width="70px">
      <el-form-item label="手机号" required>
        <el-input v-model="form.mobiles" type="textarea" :rows="5" placeholder="每行一个，一次最多 500 个" />
      </el-form-item>
      <el-form-item label="原因">
        <el-input v-model="form.reason" maxlength="255" placeholder="如：用户投诉" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="save">加入</el-button>
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
</style>
