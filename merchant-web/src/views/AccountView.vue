<script setup lang="ts">
import { ElMessage } from 'element-plus'
import { onMounted, reactive, ref } from 'vue'
import { type Account, portalApi } from '@/api/portal'
import { labelOf, operatorLabels } from '@/utils/labels'

const account = ref<Account | null>(null)

// ---- 修改 IP 白名单 ----
const ipVisible = ref(false)
const ipSaving = ref(false)
const ipForm = reactive({ ip_whitelist: '', password: '' })

function openIp() {
  ipForm.ip_whitelist = account.value?.ip_whitelist.join('\n') ?? ''
  ipForm.password = ''
  ipVisible.value = true
}

async function saveIp() {
  if (!ipForm.password) {
    ElMessage.warning('请输入登录密码')
    return
  }
  ipSaving.value = true
  try {
    const result = await portalApi.updateIpWhitelist(ipForm.password, ipForm.ip_whitelist)
    if (account.value) {
      account.value.ip_whitelist = result.ip_whitelist
    }
    ipVisible.value = false
    ElMessage.success('已保存，立即生效')
  } finally {
    ipSaving.value = false
  }
}

// ---- 重置签名密钥 ----
const secretVisible = ref(false)
const secretSaving = ref(false)
const secretPassword = ref('')
/** 重置成功后的新密钥，只显示这一次 */
const newSecret = ref('')

function openSecret() {
  secretPassword.value = ''
  newSecret.value = ''
  secretVisible.value = true
}

async function resetSecret() {
  if (!secretPassword.value) {
    ElMessage.warning('请输入登录密码')
    return
  }
  secretSaving.value = true
  try {
    newSecret.value = (await portalApi.resetSecret(secretPassword.value)).app_secret
    secretPassword.value = ''
  } finally {
    secretSaving.value = false
  }
}

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
          出于安全考虑不在这里显示；遗失或泄露时可以重置
          <el-button link type="danger" class="copy" @click="openSecret">重置密钥</el-button>
        </el-descriptions-item>
        <el-descriptions-item label="结果通知地址">{{ account.notify_url ?? '未设置（可在每次下单时传 notify_url）' }}</el-descriptions-item>
        <el-descriptions-item label="IP 白名单">
          {{ account.ip_whitelist.length > 0 ? account.ip_whitelist.join('、') : '未限制' }}
          <el-button link type="primary" class="copy" @click="openIp">修改</el-button>
        </el-descriptions-item>
      </el-descriptions>
      <div class="tip">修改通知地址请联系平台。接口说明请向平台索取《商户接入文档》。</div>
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

    <el-dialog v-model="ipVisible" title="修改 IP 白名单" width="480px">
      <el-form label-width="80px" @submit.prevent="saveIp">
        <el-form-item label="IP 白名单">
          <el-input
            v-model="ipForm.ip_whitelist"
            type="textarea"
            :rows="5"
            placeholder="每行一个 IP 或网段，如 1.2.3.4 或 1.2.3.0/24；留空表示不限制"
          />
          <div class="tip">只限制调用下单、查单等开放接口的服务器 IP，不影响登录商户后台。保存后立即生效，填错会导致下单被拒。</div>
        </el-form-item>
        <el-form-item label="登录密码">
          <el-input v-model="ipForm.password" type="password" show-password autocomplete="current-password" placeholder="确认是你本人操作" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="ipVisible = false">取消</el-button>
        <el-button type="primary" :loading="ipSaving" @click="saveIp">保存</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="secretVisible" title="重置签名密钥" width="560px" :close-on-click-modal="!newSecret">
      <template v-if="!newSecret">
        <el-alert type="warning" :closable="false" show-icon>
          重置后旧密钥<b>立即失效</b>，你们系统里还在用旧密钥签名的请求都会被拒绝，请准备好马上更新配置。
        </el-alert>
        <el-form label-width="80px" class="secret-form" @submit.prevent="resetSecret">
          <el-form-item label="登录密码">
            <el-input v-model="secretPassword" type="password" show-password autocomplete="current-password" placeholder="确认是你本人操作" />
          </el-form-item>
        </el-form>
      </template>
      <template v-else>
        <el-alert type="success" :closable="false" show-icon>新密钥只显示这一次，请立即复制保存，关闭后无法再查看。</el-alert>
        <div class="secret">
          <code>{{ newSecret }}</code>
          <el-button link type="primary" class="copy" @click="copy(newSecret)">复制</el-button>
        </div>
      </template>
      <template #footer>
        <template v-if="!newSecret">
          <el-button @click="secretVisible = false">取消</el-button>
          <el-button type="danger" :loading="secretSaving" @click="resetSecret">确认重置</el-button>
        </template>
        <el-button v-else type="primary" @click="secretVisible = false">我已保存</el-button>
      </template>
    </el-dialog>
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

.secret-form {
  margin-top: 16px;
}

.secret {
  margin-top: 16px;
  word-break: break-all;
}
</style>
