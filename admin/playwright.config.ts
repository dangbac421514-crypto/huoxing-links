import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  workers: 1,
  timeout: 90_000,
  expect: { timeout: 15_000 },
  retries: 0,
  reporter: [['list']],
  globalSetup: './e2e/global-setup.ts',
  use: {
    baseURL: 'http://127.0.0.1:4174',
    trace: 'on-first-retry',
    viewport: { width: 1280, height: 800 },
    permissions: ['clipboard-read', 'clipboard-write'],
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  webServer: [
    {
      command:
        'cd ../serve && PUBLIC_ORIGIN=https://127.0.0.1 ALLOWED_SHARE_HOSTS=127.0.0.1 bin/test-env php artisan serve --host=127.0.0.1 --port=8090',
      url: 'http://127.0.0.1:8090/api/config',
      reuseExistingServer: !process.env.CI,
      timeout: 120_000,
    },
    {
      command:
        'VITE_PROXY_PATH=/api VITE_API_URL=http://127.0.0.1:8090 VITE_PUBLIC_PATH=/ npx --yes --no-audit --package=pnpm@9.15.9 -- pnpm dev --mode e2e --host 127.0.0.1 --port 4174',
      url: 'http://127.0.0.1:4174',
      reuseExistingServer: !process.env.CI,
      timeout: 120_000,
    },
  ],
})
