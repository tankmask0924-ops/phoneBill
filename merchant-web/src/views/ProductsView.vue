<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { type CatalogProduct, portalApi } from '@/api/portal'
import { formatDuration } from '@/utils/date'
import { labelOf, operatorLabels } from '@/utils/labels'

const loading = ref(false)
const products = ref<CatalogProduct[]>([])
const categories = ref<{ id: number; name: string }[]>([])
const range = ref<{ from: string; to: string } | null>(null)

/** 分类 tab：全部 + 已开通商品里出现过的分类（按平台的排序）；有没分类的老商品时再加「未分类」 */
const NO_CATEGORY = 'none'
const activeTab = ref('all')
const tabs = computed(() => {
  const result = [{ name: 'all', label: '全部', count: products.value.length }]
  for (const c of categories.value) {
    result.push({ name: String(c.id), label: c.name, count: products.value.filter((p) => p.category_id === c.id).length })
  }
  const uncategorized = products.value.filter((p) => p.category_id === null).length
  if (uncategorized > 0 && categories.value.length > 0) {
    result.push({ name: NO_CATEGORY, label: '未分类', count: uncategorized })
  }
  return result
})

const visibleProducts = computed(() => {
  if (activeTab.value === 'all') {
    return products.value
  }
  if (activeTab.value === NO_CATEGORY) {
    return products.value.filter((p) => p.category_id === null)
  }
  return products.value.filter((p) => String(p.category_id) === activeTab.value)
})

/** 每个商品按运营商拆成多行，商品编码、名称、面值合并单元格 */
const rows = computed(() =>
  visibleProducts.value.flatMap((p) =>
    p.operators.map((op, i) => ({ ...op, product: p, first: i === 0, span: p.operators.length })),
  ),
)

type Row = (typeof rows.value)[number]

function spanMethod({ row, columnIndex }: { row: Row; columnIndex: number }) {
  if (columnIndex > 2) {
    return [1, 1]
  }
  return row.first ? [row.span, 1] : [0, 0]
}

onMounted(async () => {
  loading.value = true
  try {
    const result = await portalApi.products()
    products.value = result.products
    categories.value = result.categories
    range.value = { from: result.from, to: result.to }
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <el-card shadow="never">
    <div class="tip">
      下单时 <code>product_code</code> 传商品编码。成功率、平均耗时是平台整体
      <template v-if="range">{{ range.from }} ~ {{ range.to }}</template>（近 7 天，不含今天）的数据，每天凌晨更新；
      成功率 = 成功 /（成功 + 失败），耗时是下单受理到充值成功。
    </div>
    <el-tabs v-if="tabs.length > 1" v-model="activeTab">
      <el-tab-pane v-for="t in tabs" :key="t.name" :name="t.name" :label="`${t.label} (${t.count})`" />
    </el-tabs>
    <el-table v-loading="loading" :data="rows" :span-method="spanMethod" border empty-text="还没有开通商品，请联系平台">
      <el-table-column label="商品编码" width="150">
        <template #default="{ row }"><code>{{ row.product.product_code }}</code></template>
      </el-table-column>
      <el-table-column label="名称" min-width="140">
        <template #default="{ row }">{{ row.product.name }}</template>
      </el-table-column>
      <el-table-column label="面值" width="90" align="right">
        <template #default="{ row }">{{ row.product.face_value }} 元</template>
      </el-table-column>
      <el-table-column label="运营商" width="90">
        <template #default="{ row }">{{ labelOf(operatorLabels, row.operator) }}</template>
      </el-table-column>
      <el-table-column label="你的价格（元）" width="120" align="right">
        <template #default="{ row }">{{ row.price }}</template>
      </el-table-column>
      <el-table-column label="近 7 天成功率" width="130" align="right">
        <template #default="{ row }">
          <span v-if="row.success_rate === null" class="muted">暂无数据</span>
          <span v-else :class="Number(row.success_rate) >= 90 ? 'good' : Number(row.success_rate) >= 70 ? 'fair' : 'poor'">
            {{ row.success_rate }}%
          </span>
        </template>
      </el-table-column>
      <el-table-column label="平均耗时" width="120" align="right">
        <template #default="{ row }">
          <span v-if="row.avg_duration === null" class="muted">暂无数据</span>
          <span v-else>{{ formatDuration(row.avg_duration) }}</span>
        </template>
      </el-table-column>
      <el-table-column label="订单数" width="90" align="right">
        <template #default="{ row }">{{ row.order_count }}</template>
      </el-table-column>
    </el-table>
  </el-card>
</template>

<style scoped>
.tip {
  margin-bottom: 12px;
  color: #909399;
  font-size: 12px;
  line-height: 20px;
}

.muted {
  color: #c0c4cc;
}

.good {
  color: #67c23a;
}

.fair {
  color: #e6a23c;
}

.poor {
  color: #f56c6c;
}
</style>
