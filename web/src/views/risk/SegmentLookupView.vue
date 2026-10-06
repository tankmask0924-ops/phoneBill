<script setup lang="ts">
import { ElMessage } from 'element-plus'
import { ref } from 'vue'
import { type SegmentLookup, segmentApi } from '@/api/product'

const mobile = ref('')
const loading = ref(false)
const result = ref<SegmentLookup | null>(null)

async function lookup() {
  const value = mobile.value.trim()
  if (!/^1\d{10}$/.test(value)) {
    ElMessage.error('请输入 11 位手机号')
    return
  }
  loading.value = true
  try {
    result.value = await segmentApi.lookup(value)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <el-card shadow="never">
    <el-form inline @submit.prevent="lookup">
      <el-form-item>
        <el-input v-model="mobile" placeholder="手机号" maxlength="11" clearable style="width: 200px" />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" native-type="submit" :loading="loading">查询</el-button>
      </el-form-item>
      <span class="muted">查看号码的识别结果，以及每个上架商品会按什么顺序尝试哪些供应商商品</span>
    </el-form>

    <template v-if="result">
      <el-descriptions :column="5" border class="segment">
        <el-descriptions-item label="号段">{{ result.segment?.segment ?? '-' }}</el-descriptions-item>
        <el-descriptions-item label="运营商">{{ result.segment?.operator_name ?? '-' }}</el-descriptions-item>
        <el-descriptions-item label="省份">{{ result.segment?.province ?? '-' }}</el-descriptions-item>
        <el-descriptions-item label="城市">{{ result.segment?.city ?? '-' }}</el-descriptions-item>
        <el-descriptions-item label="虚拟运营商">{{ result.segment ? (result.segment.is_virtual ? '是' : '否') : '-' }}</el-descriptions-item>
      </el-descriptions>

      <el-alert v-if="result.rejected_reason" :title="result.rejected_reason" type="error" :closable="false" show-icon />
      <template v-else>
        <el-empty v-if="result.products.length === 0" description="还没有上架的平台商品" />
        <div v-for="product in result.products" :key="product.id" class="product">
          <div class="product-title">
            {{ product.name }}
            <span class="muted">{{ product.code }} · {{ product.face_value }} 元</span>
            <el-tag v-if="product.candidates.length === 0" type="danger" size="small">没有可用的供应商商品，下单会被拒绝</el-tag>
          </div>
          <el-table v-if="product.candidates.length > 0" :data="product.candidates" border size="small">
            <el-table-column type="index" label="尝试顺序" width="90" align="center" />
            <el-table-column prop="supplier_name" label="供应商" min-width="140" />
            <el-table-column prop="supplier_product_name" label="供应商商品" min-width="160" />
            <el-table-column prop="external_code" label="供应商商品编码" min-width="130" />
            <el-table-column prop="priority" label="优先级" width="80" align="center" />
            <el-table-column prop="cost_price" label="成本价" width="90" align="right" />
          </el-table>
        </div>
      </template>
    </template>
  </el-card>
</template>

<style scoped>
.muted {
  color: #909399;
  font-size: 12px;
}

.segment {
  margin-bottom: 16px;
}

.product {
  margin-bottom: 16px;
}

.product-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 600;
  margin-bottom: 8px;
}

.product-title .muted {
  font-weight: normal;
}
</style>
