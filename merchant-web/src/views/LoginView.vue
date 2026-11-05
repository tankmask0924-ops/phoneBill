<script setup lang="ts">
import { Message } from '@element-plus/icons-vue'
import { ElMessage } from 'element-plus'
import axios from 'axios'
import LoginPanel from '@/components/LoginPanel.vue'
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { login, type LoginResult, resendLoginCode, type SmsChallenge, verifyLogin } from '@/api/auth'
import type { LoginForm } from '@/types'
import { useAuthStore } from '@/stores/auth'
import { useCountdown } from '@/utils/countdown'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const loading = ref(false)
const title = import.meta.env.VITE_APP_TITLE

/** 绑定了手机：第二步输验证码 */
const challenge = ref<SmsChallenge | null>(null)
const code = ref('')
const resending = ref(false)
const countdown = useCountdown()

async function onSubmit(form: LoginForm) {
  loading.value = true
  try {
    const result = await login(form)
    if ('need_sms' in result) {
      challenge.value = result
      code.value = ''
      if (result.code_sent) {
        countdown.start()
        ElMessage.success(`验证码已发送到 ${result.mobile}`)
      }
      return
    }
    await finish(result)
  } finally {
    loading.value = false
  }
}

async function finish(result: LoginResult) {
  auth.setSession(result.token, result.username)
  const redirect = route.query.redirect
  await router.replace(typeof redirect === 'string' && redirect.startsWith('/') ? redirect : '/')
}

async function verify() {
  if (!/^\d{6}$/.test(code.value.trim())) {
    ElMessage.warning('请输入 6 位验证码')
    return
  }
  loading.value = true
  try {
    await finish(await verifyLogin(challenge.value!.ticket, code.value.trim()))
  } catch (e) {
    // ticket 过期或账号状态变了：回到第一步重新输账号密码
    if (axios.isAxiosError(e) && e.response?.status === 401) {
      back()
    }
  } finally {
    loading.value = false
  }
}

async function resend() {
  resending.value = true
  try {
    const { mobile } = await resendLoginCode(challenge.value!.ticket)
    countdown.start()
    ElMessage.success(`验证码已发送到 ${mobile}`)
  } catch (e) {
    if (axios.isAxiosError(e) && e.response?.status === 401) {
      back()
    }
  } finally {
    resending.value = false
  }
}

function back() {
  challenge.value = null
  countdown.stop()
}
</script>

<template>
  <LoginPanel :title="title" :loading="loading" @submit="onSubmit">
    <template v-if="challenge" #body>
      <el-form size="large" @submit.prevent="verify">
        <div class="sms-tip">账号已绑定手机 {{ challenge.mobile }}，请输入收到的短信验证码</div>
        <el-form-item>
          <div class="code-row">
            <el-input v-model="code" placeholder="6 位验证码" maxlength="6" :prefix-icon="Message" autocomplete="one-time-code" />
            <el-button :disabled="countdown.seconds.value > 0" :loading="resending" @click="resend">
              {{ countdown.seconds.value > 0 ? `${countdown.seconds.value} 秒后重发` : '获取验证码' }}
            </el-button>
          </div>
        </el-form-item>
        <el-button type="primary" size="large" class="submit" native-type="submit" :loading="loading">登录</el-button>
        <div class="back">
          <el-button link type="primary" @click="back">返回重新输入账号</el-button>
        </div>
      </el-form>
    </template>
    <template #footer>
      <span class="hint">账号由平台开通，忘记密码或换了手机请联系平台</span>
    </template>
  </LoginPanel>
</template>

<style scoped>
.hint {
  color: #909399;
  font-size: 12px;
}

.sms-tip {
  margin-bottom: 16px;
  color: #606266;
  font-size: 14px;
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
</style>
