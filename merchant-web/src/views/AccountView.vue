<script setup lang="ts">
import { ElMessage } from 'element-plus'
import { onMounted, ref } from 'vue'
import { type Account, portalApi } from '@/api/portal'
import { labelOf, operatorLabels } from '@/utils/labels'

const account = ref<Account | null>(null)

async function copy(text: string) {
  try {
    await navigator.clipboard.writeText(text)
    ElMessage.success('已复制')
  } catch {
    ElMessage.warning('复制失败，请手动选中复制')
  }
}

onMounted(async () => {
  account.value = await portalApi.account()
})
</script>

<template>
  <div v-loading="!account">
    <el-card v-if="account" shadow="never" header="接入信息">
      <el-descriptions :column="1" border>
        <el-descriptions-item label="商户">{{ account.name }}</el-descriptions-item>
        <el-descriptions-item label="AppKey">
          <code>{{ account.app_key }}</code>
          <el-button link type="primary" class="copy" @click="copy(account.app_key)">复制</el-button>
        </el-descriptions-item>
        <el-descriptions-item label="AppSecret">
          出于安全考虑不在这里显示，开通时平台已单独发给你；遗失或泄露请联系平台重置
        </el-descriptions-item>
        <el-descriptions-item label="结果通知地址">{{ account.notify_url ?? '未设置（可在每次下单时传 notify_url）' }}</el-descriptions-item>
        <el-descriptions-item label="IP 白名单">
          {{ account.ip_whitelist.length > 0 ? account.ip_whitelist.join('、') : '未限制' }}
        </el-descriptions-item>
      </el-descriptions>
      <div class="tip">修改通知地址、IP 白名单请联系平台。接口说明请向平台索取《商户接入文档》。</div>
    </el-card>

    <el-card v-if="account" shadow="never" header="可下单的商品" class="products">
      <el-table :data="account.products" border empty-text="还没有开通商品，请联系平台">
        <el-table-column prop="product_code" label="商品编码" width="160" />
        <el-table-column prop="name" label="名称" min-width="140" />
        <el-table-column label="面值" width="100" align="right">
          <template #default="{ row }">{{ row.face_value }} 元</template>
        </el-table-column>
        <el-table-column label="你的价格（元）" min-width="260">
          <template #default="{ row }">
            <span v-for="(price, op) in row.prices" :key="op" class="price">{{ labelOf(operatorLabels, op as string) }} {{ price }}</span>
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<style scoped>
.copy {
  margin-left: 8px;
}

.tip {
  margin-top: 12px;
  color: #909399;
  font-size: 12px;
}

.products {
  margin-top: 16px;
}

.price {
  margin-right: 16px;
  white-space: nowrap;
}
</style>
