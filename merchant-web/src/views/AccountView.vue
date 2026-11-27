<script setup lang="ts">
import { ElMessage, ElMessageBox } from 'element-plus'
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { bindMobile, fetchMe, type Me, sendBindCode, setSmsLogin } from '@/api/auth'
import { type Account, portalApi } from '@/api/portal'
import { useMeStore } from '@/stores/me'
import { useCountdown } from '@/utils/countdown'

const router = useRouter()
const account = ref<Account | null>(null)
const me = ref<Me | null>(null)
const meStore = useMeStore()

// ---- 绑定 / 修改手机 ----
const mobileVisible = ref(false)
const mobileSaving = ref(false)
const codeSending = ref(false)
const mobileForm = reactive({ mobile: '', code: '', password: '' })
const { seconds: codeSeconds, start: startCountdown, stop: stopCountdown } = useCountdown()

function openMobile() {
  Object.assign(mobileForm, { mobile: '', code: '', password: '' })
  stopCountdown()
  mobileVisible.value = true
}

async function sendCode() {
  if (!/^1[3-9]\d{9}$/.test(mobileForm.mobile.trim())) {
    ElMessage.warning('请输入正确的手机号')
    return
  }
  codeSending.value = true
  try {
    await sendBindCode(mobileForm.mobile.trim())
    startCountdown()
    ElMessage.success('验证码已发送')
  } finally {
    codeSending.value = false
  }
}

async function saveMobile() {
  if (!mobileForm.code.trim() || !mobileForm.password) {
    ElMessage.warning('请填写验证码和登录密码')
    return
  }
  mobileSaving.value = true
  try {
    const { mobile } = await bindMobile({ mobile: mobileForm.mobile.trim(), code: mobileForm.code.trim(), password: mobileForm.password })
    if (me.value) {
      me.value.mobile = mobile
    }
    meStore.setMobile(mobile)
    account.value = await portalApi.account()
    mobileVisible.value = false
    ElMessage.success(me.value?.sms_login_enabled ? '已修改，登录验证码会发到新手机' : '已绑定，可以开启登录短信验证')
  } finally {
    mobileSaving.value = false
  }
}

// ---- 登录短信验证 ----
const smsLoginSaving = ref(false)

async function toggleSmsLogin(enabled: boolean) {
  if (!me.value) {
    return
  }
  let password: string
  try {
    const result = await ElMessageBox.prompt(
      enabled ? `开启后，登录时除了密码还要输入发到 ${me.value.mobile} 的短信验证码。请输入登录密码确认。` : '关闭后登录只需要账号密码。请输入登录密码确认。',
      enabled ? '开启登录短信验证' : '关闭登录短信验证',
      { inputType: 'password', inputPlaceholder: '登录密码', inputValidator: (v) => !!v || '请输入登录密码' },
    )
    password = result.value
  } catch {
    return
  }
  smsLoginSaving.value = true
  try {
    me.value.sms_login_enabled = (await setSmsLogin(enabled, password)).sms_login_enabled
    ElMessage.success(enabled ? '已开启，下次登录需要输入短信验证码' : '已关闭')
  } finally {
    smsLoginSaving.value = false
  }
}

// ---- 余额提醒 ----
const alertVisible = ref(false)
const alertSaving = ref(false)
const alertForm = reactive({ enabled: false, threshold: '' })

function openAlert() {
  const current = account.value?.balance_alert_threshold ?? null
  Object.assign(alertForm, { enabled: current !== null, threshold: current ?? '' })
  alertVisible.value = true
}

async function saveAlert() {
  const threshold = alertForm.threshold.trim()
  if (alertForm.enabled && (!/^\d{1,8}(\.\d{1,2})?$/.test(threshold) || Number(threshold) <= 0)) {
    ElMessage.warning('提醒阈值要大于 0，最多两位小数')
    return
  }
  alertSaving.value = true
  try {
    const result = await portalApi.updateBalanceAlert(alertForm.enabled ? threshold : null)
    if (account.value) {
      account.value.balance_alert_threshold = result.balance_alert_threshold
    }
    alertVisible.value = false
    ElMessage.success('已保存')
  } finally {
    alertSaving.value = false
  }
}

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
const secretCode = ref('')
const secretCodeSending = ref(false)
const secretCountdown = useCountdown()
/** 重置成功后的新密钥，只显示这一次 */
const newSecret = ref('')

function openSecret() {
  secretPassword.value = ''
  secretCode.value = ''
  newSecret.value = ''
  secretCountdown.stop()
  secretVisible.value = true
}

async function sendSecretCode() {
  secretCodeSending.value = true
  try {
    const { mobile } = await portalApi.sendResetSecretCode()
    secretCountdown.start()
    ElMessage.success(`验证码已发送到 ${mobile}`)
  } finally {
    secretCodeSending.value = false
  }
}

async function resetSecret() {
  if (!secretPassword.value) {
    ElMessage.warning('请输入登录密码')
    return
  }
  if (me.value?.sms_available && !/^\d{6}$/.test(secretCode.value.trim())) {
    ElMessage.warning('请输入 6 位短信验证码')
    return
  }
  secretSaving.value = true
  try {
    newSecret.value = (await portalApi.resetSecret(secretPassword.value, secretCode.value.trim())).app_secret
    secretPassword.value = ''
    secretCode.value = ''
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
  ;[account.value, me.value] = await Promise.all([portalApi.account(), fetchMe()])
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

    <el-card v-if="account && me" shadow="never" header="登录与提醒" class="products">
      <el-descriptions :column="1" border>
        <el-descriptions-item label="登录账号">{{ me.username }}</el-descriptions-item>
        <el-descriptions-item label="绑定手机">
          {{ me.mobile ?? '未绑定' }}
          <el-button v-if="me.sms_available" link type="primary" class="copy" @click="openMobile">{{ me.mobile ? '修改' : '绑定' }}</el-button>
          <span v-else class="muted">（平台暂未开通短信服务）</span>
          <div class="cell-tip">余额提醒短信发到这里；开启登录短信验证后，登录验证码也发到这里。</div>
        </el-descriptions-item>
        <el-descriptions-item label="登录短信验证">
          <el-switch
            :model-value="me.sms_login_enabled"
            :loading="smsLoginSaving"
            :disabled="!me.sms_login_enabled && (!me.mobile || !me.sms_available)"
            @change="(v: string | number | boolean) => toggleSmsLogin(!!v)"
          />
          <span v-if="!me.sms_login_enabled && !me.mobile" class="muted">（请先绑定手机）</span>
          <span v-else-if="!me.sms_available" class="muted">（平台暂未开通短信服务，暂不需要验证码）</span>
          <div class="cell-tip">开启后，登录时除了账号密码还要输入短信验证码，更安全。只对当前账号生效。</div>
        </el-descriptions-item>
        <el-descriptions-item label="余额提醒">
          <template v-if="account.balance_alert_threshold !== null">
            余额低于 {{ account.balance_alert_threshold }} 元时短信提醒
          </template>
          <template v-else>未开启</template>
          <el-button link type="primary" class="copy" @click="openAlert">设置</el-button>
          <div class="cell-tip">
            跌破阈值时提醒一次，充值到阈值以上后再跌破会再提醒，每天最多 3 条；发给本商户所有已绑定手机的账号<template v-if="account.alert_mobiles.length > 0">（{{ account.alert_mobiles.join('、') }}）</template><template v-else>（目前还没有账号绑定手机，收不到提醒）</template>。
          </div>
        </el-descriptions-item>
      </el-descriptions>
    </el-card>

    <el-card v-if="account" shadow="never" class="products">
      开通的商品、价格和近 7 天成功率见
      <el-button link type="primary" @click="router.push('/products')">可下单商品</el-button>
    </el-card>

    <el-dialog v-model="mobileVisible" :title="me?.mobile ? '修改绑定手机' : '绑定手机'" width="480px">
      <el-form label-width="80px" @submit.prevent="saveMobile">
        <el-form-item v-if="me?.mobile" label="当前手机">{{ me.mobile }}</el-form-item>
        <el-form-item :label="me?.mobile ? '新手机号' : '手机号'">
          <el-input v-model="mobileForm.mobile" maxlength="11" placeholder="11 位手机号" />
        </el-form-item>
        <el-form-item label="验证码">
          <div class="code-row">
            <el-input v-model="mobileForm.code" maxlength="6" placeholder="6 位验证码" autocomplete="one-time-code" />
            <el-button :disabled="codeSeconds > 0" :loading="codeSending" @click="sendCode">
              {{ codeSeconds > 0 ? `${codeSeconds} 秒后重发` : '获取验证码' }}
            </el-button>
          </div>
        </el-form-item>
        <el-form-item label="登录密码">
          <el-input v-model="mobileForm.password" type="password" show-password autocomplete="current-password" placeholder="确认是你本人操作" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="mobileVisible = false">取消</el-button>
        <el-button type="primary" :loading="mobileSaving" @click="saveMobile">保存</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="alertVisible" title="余额提醒" width="480px">
      <el-form label-width="80px" @submit.prevent="saveAlert">
        <el-form-item label="开启提醒">
          <el-switch v-model="alertForm.enabled" />
        </el-form-item>
        <el-form-item v-if="alertForm.enabled" label="提醒阈值">
          <el-input v-model="alertForm.threshold" placeholder="如 1000">
            <template #append>元</template>
          </el-input>
          <div class="tip">余额跌破这个数时发短信提醒充值；一直没充值不会重复发，充值到阈值以上后再跌破会再提醒，每天最多 3 条。</div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="alertVisible = false">取消</el-button>
        <el-button type="primary" :loading="alertSaving" @click="saveAlert">保存</el-button>
      </template>
    </el-dialog>

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
          <el-form-item v-if="me?.sms_available" label="验证码">
            <div class="code-row">
              <el-input v-model="secretCode" maxlength="6" placeholder="6 位验证码" autocomplete="one-time-code" />
              <el-button :disabled="secretCountdown.seconds.value > 0" :loading="secretCodeSending" @click="sendSecretCode">
                {{ secretCountdown.seconds.value > 0 ? `${secretCountdown.seconds.value} 秒后重发` : '获取验证码' }}
              </el-button>
            </div>
            <div class="cell-tip">发到当前账号绑定的手机 {{ me.mobile }}</div>
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


.cell-tip {
  margin-top: 4px;
  color: #909399;
  font-size: 12px;
}

.muted {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}

.code-row {
  display: flex;
  gap: 8px;
  width: 100%;
}

.secret-form {
  margin-top: 16px;
}

.secret {
  margin-top: 16px;
  word-break: break-all;
}
</style>
