<script setup lang="ts">
import { Iphone, Lock, Message } from '@element-plus/icons-vue'
import { ElMessage } from 'element-plus'
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { bindMobile, sendBindCode } from '@/api/auth'
import LoginPanel from '@/components/LoginPanel.vue'
import { useAuthStore } from '@/stores/auth'
import { useMeStore } from '@/stores/me'
import { useSiteStore } from '@/stores/site'
import { useCountdown } from '@/utils/countdown'

/** 平台启用了短信后，没绑定手机的账号登录后先到这里绑定，绑定前其他页面都进不去（后端同样拦截） */
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const meStore = useMeStore()
const site = useSiteStore()

const form = reactive({ mobile: '', code: '', password: '' })
const sending = ref(false)
const saving = ref(false)
const countdown = useCountdown()

async function sendCode() {
  if (!/^1[3-9]\d{9}$/.test(form.mobile.trim())) {
    ElMessage.warning('请输入正确的手机号')
    return
  }
  sending.value = true
  try {
    await sendBindCode(form.mobile.trim())
    countdown.start()
    ElMessage.success('验证码已发送')
  } finally {
    sending.value = false
  }
}

async function save() {
  if (!/^1[3-9]\d{9}$/.test(form.mobile.trim())) {
    ElMessage.warning('请输入正确的手机号')
    return
  }
  if (!/^\d{6}$/.test(form.code.trim()) || !form.password) {
    ElMessage.warning('请填写 6 位验证码和登录密码')
    return
  }
  saving.value = true
  try {
    const { mobile } = await bindMobile({ mobile: form.mobile.trim(), code: form.code.trim(), password: form.password })
    meStore.setMobile(mobile)
    ElMessage.success('手机已绑定')
    const redirect = route.query.redirect
    await router.replace(typeof redirect === 'string' && redirect.startsWith('/') ? redirect : '/')
  } finally {
    saving.value = false
  }
}

function logout() {
  auth.clear()
  meStore.clear()
  router.push({ name: 'login' })
}
</script>

<template>
  <LoginPanel :title="site.name">
    <template #body>
      <el-form size="large" @submit.prevent="save">
        <div class="tip">
          为了账户安全，请先绑定手机（{{ meStore.me?.username }}）。绑定后重置签名密钥需要短信验证码，余额提醒也发到这个手机。
        </div>
        <el-form-item>
          <el-input v-model="form.mobile" placeholder="11 位手机号" maxlength="11" :prefix-icon="Iphone" />
        </el-form-item>
        <el-form-item>
          <div class="code-row">
            <el-input v-model="form.code" placeholder="6 位验证码" maxlength="6" :prefix-icon="Message" autocomplete="one-time-code" />
            <el-button :disabled="countdown.seconds.value > 0" :loading="sending" @click="sendCode">
              {{ countdown.seconds.value > 0 ? `${countdown.seconds.value} 秒后重发` : '获取验证码' }}
            </el-button>
          </div>
        </el-form-item>
        <el-form-item>
          <el-input
            v-model="form.password"
            type="password"
            placeholder="登录密码（确认是你本人操作）"
            :prefix-icon="Lock"
            show-password
            autocomplete="current-password"
          />
        </el-form-item>
        <el-button type="primary" size="large" class="submit" native-type="submit" :loading="saving">绑定并进入</el-button>
        <div class="back">
          <el-button link type="primary" @click="logout">退出登录</el-button>
        </div>
      </el-form>
    </template>
    <template #footer>
      <span class="hint">收不到验证码请联系平台</span>
    </template>
  </LoginPanel>
</template>

<style scoped>
.tip {
  margin-bottom: 16px;
  color: #606266;
  font-size: 14px;
  line-height: 1.6;
}

.code-row {
  display: flex;
  gap: 8px;
  width: 100%;
}

.submit {
  width: 100%;
}

.back {
  margin-top: 8px;
  text-align: center;
}

.hint {
  color: #909399;
  font-size: 12px;
}
</style>
