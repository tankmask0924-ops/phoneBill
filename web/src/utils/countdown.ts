import { onBeforeUnmount, ref } from 'vue'

/** 「重新获取验证码」的倒计时：start() 后 seconds 从 n 减到 0，组件卸载时自动停 */
export function useCountdown() {
  const seconds = ref(0)
  let timer: ReturnType<typeof setInterval> | undefined

  function stop() {
    if (timer !== undefined) {
      clearInterval(timer)
      timer = undefined
    }
    seconds.value = 0
  }

  function start(n = 60) {
    stop()
    seconds.value = n
    timer = setInterval(() => {
      seconds.value -= 1
      if (seconds.value <= 0) {
        stop()
      }
    }, 1000)
  }

  onBeforeUnmount(stop)

  return { seconds, start, stop }
}
