import { ElNotification, type NotificationHandle } from 'element-plus'
import { h, onBeforeUnmount, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { AdminTodos } from '@/api/dashboard'
import { usePermissionStore } from '@/stores/permission'
import { useTodoStore } from '@/stores/todo'

const INTERVAL = 60_000

const ITEMS: { key: keyof AdminTodos; label: string; tab: string; action: string }[] = [
  { key: 'pending_funding_requests', label: '待审批加款', tab: 'requests', action: '去审批' },
  { key: 'pending_payer_accounts', label: '待审核付款账号', tab: 'payers', action: '去审核' },
]

/**
 * 右下角待办提醒：有 funding.view 的管理员在后台任意页面，每分钟、每次换页面查一次待办，
 * 数量比上次提醒时多了就在右下角弹出（要手动关），点哪一项跳到加款申请页对应的页签。
 * 在后台布局里调用，退出登录离开布局时停止。
 */
export function useTodoReminder() {
  const permission = usePermissionStore()
  const store = useTodoStore()
  const route = useRoute()
  const router = useRouter()
  let timer: ReturnType<typeof setInterval> | undefined
  let handle: NotificationHandle | null = null
  let stopped = false

  function close() {
    handle?.close()
    handle = null
  }

  function open(tab: string) {
    close()
    router.push({ path: '/merchant/funding-requests', query: { tab } })
  }

  /** 内容跟着 store 走，弹出后数量变了也会更新 */
  function render() {
    const todos = store.todos
    return h(
      'div',
      ITEMS.filter((item) => (todos?.[item.key] ?? 0) > 0).map((item) =>
        h('div', { style: 'cursor: pointer; line-height: 26px', onClick: () => open(item.tab) }, [
          `${item.label} `,
          h('b', { style: 'color: #e6a23c' }, String(todos?.[item.key])),
          ' 条',
          h('span', { style: 'margin-left: 8px; color: #409eff' }, `${item.action} ›`),
        ]),
      ),
    )
  }

  async function check() {
    if (stopped || document.hidden) {
      return
    }
    let todos: AdminTodos
    try {
      todos = await store.refresh(true)
    } catch {
      return
    }
    if (stopped) {
      return
    }
    if (store.total === 0) {
      close()
    } else {
      const last = store.notified()
      if (ITEMS.some((item) => todos[item.key] > (last?.[item.key] ?? 0))) {
        // 有新的待办：关掉旧的重新弹，引起注意
        close()
        handle = ElNotification({
          title: '有新的待办',
          message: render,
          type: 'warning',
          position: 'bottom-right',
          duration: 0,
          onClose: () => {
            handle = null
          },
        })
      }
    }
    // 处理掉一些后基准跟着降，之后再来新的还会提醒
    store.setNotified(todos)
  }

  function onVisibilityChange() {
    if (!document.hidden) {
      check()
    }
  }

  // 都处理完了（包括在加款申请页审核后马上刷新的）就收起提醒
  watch(
    () => store.total,
    (total) => total === 0 && close(),
  )

  let enabled = false
  watch(
    () => route.path,
    () => enabled && check(),
  )

  permission
    .load()
    .then(() => {
      if (stopped || !permission.can('funding.view')) {
        return
      }
      enabled = true
      check()
      timer = setInterval(check, INTERVAL)
      document.addEventListener('visibilitychange', onVisibilityChange)
    })
    .catch(() => {})

  onBeforeUnmount(() => {
    stopped = true
    clearInterval(timer)
    document.removeEventListener('visibilitychange', onVisibilityChange)
    close()
  })
}
