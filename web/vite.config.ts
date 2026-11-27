import { fileURLToPath, URL } from 'node:url'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

export default defineConfig({
  // 管理后台前端入口（只是页面地址；调后端接口仍是 /api/admin/...）
  base: '/yhylhf/',
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  build: {
    // Element Plus 全量引入，单个 chunk 约 1MB，后台系统可以接受
    chunkSizeWarningLimit: 1500,
  },
  server: {
    port: 5175,
    strictPort: true,
    proxy: {
      '/api': {
        // docker-compose.yml 把容器的 9501 映射到宿主机 9502
        target: 'http://127.0.0.1:9502',
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/api/, ''),
      },
    },
  },
})
