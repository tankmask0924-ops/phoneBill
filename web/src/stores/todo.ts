import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { type AdminOrderTodos, type AdminTodos, dashboardApi } from '@/api/dashboard'

/** 上次右下角提醒时的数量，存在浏览器里：刷新页面、多开标签页时同样的数量不再提醒 */
const NOTIFIED_KEY = 'phone-bill-admin-todo:notified'

/**
 * 待办数量：待审批加款、待审核付款账号（funding.view）；异常订单（order.view，只显示角标和首页卡片，不弹提醒）。
 * 首页卡片、菜单角标、右下角提醒共用，layout/todoReminder.ts 每分钟刷新一次。
 */
export const useTodoStore = defineStore('phone-bill-admin-todo', () => {
  const todos = ref<AdminTodos | null>(null)
  /** 加款申请页的两个页签加起来，显示在菜单角标上 */
  const total = computed(() => (todos.value ? todos.value.pending_funding_requests + todos.value.pending_payer_accounts : 0))
  let pending: Promise<AdminTodos> | null = null

  const orderTodos = ref<AdminOrderTodos | null>(null)
  /** 异常订单数，显示在「异常订单」菜单角标上 */
  const abnormalCount = computed(() => orderTodos.value?.abnormal_orders ?? 0)
  let pendingOrders: Promise<AdminOrderTodos> | null = null

  /** 同 refresh()，查异常订单数 */
  async function refreshOrders(silent = false): Promise<AdminOrderTodos> {
    pendingOrders ??= dashboardApi.orderTodos(silent).finally(() => {
      pendingOrders = null
    })
    orderTodos.value = await pendingOrders
    return orderTodos.value
  }

  /** 同时只发一个请求；silent 表示出错不弹提示（轮询用） */
  async function refresh(silent = false): Promise<AdminTodos> {
    pending ??= dashboardApi.todos(silent).finally(() => {
      pending = null
    })
    todos.value = await pending
    return todos.value
  }

  function notified(): AdminTodos | null {
    try {
      const raw = localStorage.getItem(NOTIFIED_KEY)
      return raw ? (JSON.parse(raw) as AdminTodos) : null
    } catch {
      return null
    }
  }

  function setNotified(value: AdminTodos | null) {
    try {
      if (value) {
        localStorage.setItem(NOTIFIED_KEY, JSON.stringify(value))
      } else {
        localStorage.removeItem(NOTIFIED_KEY)
      }
    } catch {
      // 浏览器禁用了存储，只是刷新后会再提醒一次
    }
  }

  /** 登录时调用：清掉上个账号的数量，登录后有待办就提醒一次 */
  function reset() {
    todos.value = null
    orderTodos.value = null
    setNotified(null)
  }

  return { todos, total, refresh, orderTodos, abnormalCount, refreshOrders, notified, setNotified, reset }
})
