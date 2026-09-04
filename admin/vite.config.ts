import { fileURLToPath, URL } from 'node:url'

import vue from '@vitejs/plugin-vue'
import type { ConfigEnv, UserConfigExport } from 'vite'
import { loadEnv } from 'vite'
import { createSvgIconsPlugin } from 'vite-plugin-svg-icons'
import path from 'path'

export default ({ mode }: ConfigEnv): UserConfigExport => {
  const root = process.cwd()
  const env = loadEnv(mode, root)
  return {
    base: env.VITE_PUBLIC_PATH,
    plugins: [
      vue(),
      createSvgIconsPlugin({
        iconDirs: [path.resolve(process.cwd(), 'src/assets')]
      })
    ],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url))
      }
    },
    build: {
      rollupOptions: {
        output: {
          manualChunks(id) {
            if (id.includes('node_modules')) {
              return 'vendor'
            }
          }
        }
      },
      chunkSizeWarningLimit: 3000
    },
    server: {
      port: 3000,
      proxy: {
        [env.VITE_PROXY_PATH]: {
          target: env.VITE_API_URL,
          changeOrigin: true,
          ...(mode === 'e2e'
            ? {}
            : {
                rewrite: (path) => path.replace(env.VITE_PROXY_PATH, '')
              })
        },
      }
    },
  }
}
