/** 本地时区的 YYYY-MM-DD */
function ymd(d: Date): string {
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

/**
 * 最近 N 天（含今天）的日期范围。订单、流水列表默认只查最近 7 天，和后端 FiltersByDateRange 一致。
 */
export function recentDays(days = 7): [string, string] {
  const today = new Date()
  const start = new Date(today.getFullYear(), today.getMonth(), today.getDate() - (days - 1))
  return [ymd(start), ymd(today)]
}

/** 秒数显示成「45 秒」「3 分 5 秒」「1 小时 2 分」，null 显示 - */
export function formatDuration(seconds: number | null | undefined): string {
  if (seconds === null || seconds === undefined) {
    return '-'
  }
  if (seconds < 60) {
    return `${seconds} 秒`
  }
  if (seconds < 3600) {
    const s = seconds % 60
    return s === 0 ? `${Math.floor(seconds / 60)} 分` : `${Math.floor(seconds / 60)} 分 ${s} 秒`
  }
  const m = Math.floor((seconds % 3600) / 60)
  return m === 0 ? `${Math.floor(seconds / 3600)} 小时` : `${Math.floor(seconds / 3600)} 小时 ${m} 分`
}
