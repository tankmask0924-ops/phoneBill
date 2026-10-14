<script setup lang="ts">
import StatusTag from '@/components/StatusTag.vue'
import { Refresh } from '@element-plus/icons-vue'
import { usePagedList } from '@/utils/paged'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { type ExportRecord, exportApi } from '@/api/portal'
import { balanceTypeLabels, labelOf, operatorLabels, orderStatusLabels } from '@/utils/labels'
import type { LabelMap } from '@/utils/labels'

const exportStatusLabels: LabelMap = {
  pending: { label: '生成中', type: 'primary' },
  success: { label: '已完成', type: 'success' },
  failed: { label: '失败', type: 'danger' },
}

const list = usePagedList<ExportRecord, object>(exportApi.list, {}, 20)
const { rows, total, page, perPage, loading } = list

/** 筛选条件显示成一行文字 */
function describeFilters(record: ExportRecord): string {
  const f = record.filters
  const parts: string[] = []
  if (f.created_from || f.created_to) {
    parts.push(`${f.created_from ?? ''} ~ ${f.created_to ?? ''}`)
  }
  if (f.keyword) parts.push(`订单号 ${f.keyword}`)
  if (f.order_no) parts.push(`订单号 ${f.order_no}`)
  if (f.mobile) parts.push(`手机号 ${String(f.mobile).split(/[\s,，;；、]+/).filter(Boolean).length} 个`)
  if (f.status) parts.push(labelOf(orderStatusLabels, String(f.status)))
  if (f.operator) parts.push(labelOf(operatorLabels, String(f.operator)))
  if (f.province) parts.push(String(f.province))
  if (f.face_value) parts.push(`${f.face_value} 元`)
  if (f.category_id) parts.push('指定分类')
  if (f.type) parts.push(labelOf(balanceTypeLabels, String(f.type)))
  return parts.length > 0 ? parts.join('，') : '全部'
}

const downloading = ref<number | null>(null)

async function download(record: ExportRecord) {
  downloading.value = record.id
  try {
    await exportApi.download(record)
  } finally {
    downloading.value = null
  }
}

/** 有生成中的任务时每 3 秒刷新一次 */
let timer: ReturnType<typeof setTimeout> | undefined
watch(rows, (value) => {
  clearTimeout(timer)
  if (value.some((r) => r.status === 'pending')) {
    timer = setTimeout(() => list.load(), 3000)
  }
})

onMounted(list.load)
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <el-card shadow="never">
    <div class="toolbar">
      <span class="tip">在「订单记录」「资金流水」里按筛选条件导出，文件生成后保留 7 天，过期自动删除。</span>
      <el-button :icon="Refresh" @click="list.load">刷新</el-button>
    </div>

    <el-table v-loading="loading" :data="rows" border empty-text="还没有导出记录">
      <el-table-column prop="type_label" label="类型" width="100" />
      <el-table-column label="筛选条件" min-width="220">
        <template #default="{ row }">{{ describeFilters(row as ExportRecord) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="100">
        <template #default="{ row }">
          <StatusTag :map="exportStatusLabels" :value="row.status" />
        </template>
      </el-table-column>
      <el-table-column label="行数" width="90" align="right">
        <template #default="{ row }">{{ row.row_count ?? '-' }}</template>
      </el-table-column>
      <el-table-column prop="created_at" label="导出时间" width="170" />
      <el-table-column prop="expires_at" label="过期时间" width="170" />
      <el-table-column label="操作" min-width="160" fixed="right">
        <template #default="{ row }">
          <el-button
            v-if="row.status === 'success'"
            link
            type="primary"
            :loading="downloading === row.id"
            @click="download(row as ExportRecord)"
          >
            下载
          </el-button>
          <span v-else-if="row.status === 'failed'" class="error">{{ row.error ?? '生成失败' }}</span>
          <span v-else class="muted">生成中…</span>
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
</template>

<style scoped>
.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}

.tip {
  color: #909399;
  font-size: 12px;
}

.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}

.muted {
  color: #909399;
}

.error {
  color: #f56c6c;
  font-size: 12px;
}
</style>
