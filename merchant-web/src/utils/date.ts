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
