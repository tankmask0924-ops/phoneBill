<script setup lang="ts">
import { usePagedList } from '@/utils/paged'
import { onMounted } from 'vue'
import { type SecurityLog, securityLogApi } from '@/api/system'
import { labelOf, securityEventLabels, securityPortalLabels, securityReasonLabels, toOptions } from '@/utils/labels'

const list = usePagedList<SecurityLog, { portal: string; event: string; username: string; ip: string; dates: [string, string] | null }>(
  (params) => {
    // 日期范围拆成 created_from / created_to
    const { dates, ...rest } = params as Record<string, unknown> & { dates?: [string, string] }
    return securityLogApi.list(dates ? { ...rest, created_from: dates[0], created_to: dates[1] } : rest)
  },
  { portal: '', event: '', username: '', ip: '', dates: null },
  20,
)
const { rows, total, page, perPage, loading, filters } = list

/** 登录失败写原因（输错密码带第几次、是否因此封禁），其他事件把 detail 摊平显示 */
const describe = (row: SecurityLog): string => {
  const detail = row.detail ?? {}
  if (row.event === 'login_failed') {
    const parts = [labelOf(securityReasonLabels, String(detail.reason ?? ''))]
    if (typeof detail.failures === 'number') parts.push(`连续第 ${detail.failures} 次`)
    if (detail.locked === true) parts.push('已自动封禁')
    return parts.join('，')
  }
  const entries = Object.entries(detail)
  return entries.length === 0 ? '' : entries.map(([k, v]) => `${k}: ${typeof v === 'object' ? JSON.stringify(v) : String(v)}`).join('；')
}

/** 按 IP 筛：点表格里的 IP 直接看这个 IP 的全部记录 */
const filterIp = (ip: string | null) => {
  if (!ip) return
  filters.ip = ip
  list.search()
}

onMounted(list.load)
</script>

<template>
  <el-card shadow="never">
    <el-form inline @submit.prevent="list.search">
      <el-form-item>
        <el-select v-model="filters.portal" placeholder="全部端" clearable style="width: 120px">
          <el-option v-for="o in toOptions(securityPortalLabels)" :key="o.value" v-bind="o" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-select v-model="filters.event" placeholder="全部事件" clearable style="width: 170px">
          <el-option v-for="o in toOptions(securityEventLabels)" :key="o.value" v-bind="o" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-input v-model="filters.username" placeholder="用户名" clearable style="width: 140px" />
      </el-form-item>
      <el-form-item>
        <el-input v-model="filters.ip" placeholder="IP" clearable style="width: 150px" />
      </el-form-item>
      <el-form-item>
        <el-date-picker
          v-model="filters.dates"
          type="daterange"
          value-format="YYYY-MM-DD"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          style="width: 240px"
        />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" native-type="submit">查询</el-button>
      </el-form-item>
    </el-form>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="created_at" label="时间" width="170" />
      <el-table-column label="端" width="90">
        <template #default="{ row }">{{ labelOf(securityPortalLabels, row.portal) }}</template>
      </el-table-column>
      <el-table-column label="账号" min-width="150">
        <template #default="{ row }">
          {{ row.username }}
          <div v-if="row.merchant_id" class="muted">{{ row.merchant_name ?? '商户' }} #{{ row.merchant_id }}</div>
        </template>
      </el-table-column>
      <el-table-column label="事件" width="150">
        <template #default="{ row }">
          <el-tag :type="securityEventLabels[row.event]?.type ?? 'info'" disable-transitions>
            {{ labelOf(securityEventLabels, row.event) }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="说明" min-width="200">
        <template #default="{ row }">{{ describe(row as SecurityLog) }}</template>
      </el-table-column>
      <el-table-column label="IP" width="150">
        <template #default="{ row }">
          <el-button v-if="row.ip" link type="primary" @click="filterIp(row.ip)">{{ row.ip }}</el-button>
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column label="浏览器" min-width="200" show-overflow-tooltip>
        <template #default="{ row }">{{ row.user_agent ?? '-' }}</template>
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
.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}

.muted {
  color: #909399;
  font-size: 12px;
}
</style>
