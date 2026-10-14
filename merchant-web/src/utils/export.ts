import { ElMessageBox } from 'element-plus'
import router from '@/router'
import { type ExportType, exportApi } from '@/api/portal'

/** 去掉空的筛选条件，跟 usePagedList 发给列表接口的一样 */
export function cleanParams(params: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v !== null && v !== undefined))
}

/**
 * 按当前筛选条件提交导出（异步生成），提示去导出列表下载。
 */
export async function submitExport(type: ExportType, params: Record<string, unknown>): Promise<void> {
  await exportApi.create(type, cleanParams(params))
  try {
    await ElMessageBox.confirm('文件生成好后在「导出列表」里下载，保留 7 天。', '已提交导出', {
      type: 'success',
      confirmButtonText: '去导出列表',
      cancelButtonText: '留在这里',
    })
  } catch {
    return
  }
  router.push({ name: 'exports' })
}
