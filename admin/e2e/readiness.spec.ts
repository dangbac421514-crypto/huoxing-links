import { expect, test, type Page } from '@playwright/test'

const state = JSON.parse(process.env.FEEDBACK_E2E_STATE ?? '{}') as {
  username: string
  password: string
  admin_username: string
  admin_password: string
}
const adminPath = process.env.E2E_BUILT === '1' ? '/web' : ''

async function login(page: Page, username: string, password: string) {
  await page.goto(`${adminPath}/#/login`)
  await page.getByPlaceholder('请输入账号', { exact: true }).fill(username)
  await page.getByPlaceholder('请输入密码', { exact: true }).fill(password)
  await page.getByRole('button', { name: '立即登录', exact: true }).click()
}

test('new administrator changes initial password before opening the dashboard', async ({ page }) => {
  await login(page, state.admin_username, state.admin_password)
  await expect(page).toHaveURL(/#\/change-password$/)
  await expect(page.getByRole('heading', { name: '首次登录，请修改密码' })).toBeVisible()
  await page.reload()
  await expect(page.getByRole('heading', { name: '首次登录，请修改密码' })).toBeVisible()
  const newPassword = `${state.admin_password}A1`
  await page.getByPlaceholder('请输入新密码', { exact: true }).fill(newPassword)
  await page.getByPlaceholder('请再次输入新密码', { exact: true }).fill(newPassword)
  await page.getByRole('button', { name: '保存并进入后台' }).click()
  await expect(page).toHaveURL(/#\/admin-home$/)
  await page.goto(`${adminPath}/#/super/domains`)
  await expect(page.getByText('127.0.0.1', { exact: true }).first()).toBeVisible()
  await page.locator('.avatar-wrapper').getByText(state.admin_username).hover()
  await page.getByText('退出', { exact: true }).click()
  await expect(page).toHaveURL(/#\/login$/)
  await login(page, state.admin_username, newPassword)
  await expect(page).toHaveURL(/#\/admin-home$/)
})

test('logout clears a session token and preserves unrelated local preferences', async ({ page }) => {
  await login(page, state.username, state.password)
  await expect(page).toHaveURL(/#\/vip-home$/)
  await page.evaluate(() => {
    const token = localStorage.getItem('token')
    if (!token) throw new Error('login did not store a token')
    sessionStorage.setItem('token', token)
    localStorage.removeItem('token')
    localStorage.setItem('readiness-preference', 'keep')
  })
  await page.reload()
  await page.locator('.avatar-wrapper').getByText(state.username).hover()
  await page.getByText('退出', { exact: true }).click()
  await expect(page).toHaveURL(/#\/login$/)
  expect(await page.evaluate(() => ({
    local: localStorage.getItem('token'),
    session: sessionStorage.getItem('token'),
    preference: localStorage.getItem('readiness-preference')
  }))).toEqual({ local: null, session: null, preference: 'keep' })
  await page.goto(`${adminPath}/#/feedback`)
  await expect(page).toHaveURL(/#\/login$/)
})

test('member creates edits copies and deletes a WeCom link through the dashboard', async ({ page }) => {
  await login(page, state.username, state.password)
  await expect(page).toHaveURL(/#\/vip-home$/)
  await page.goto(`${adminPath}/#/link/addLink`)
  await page.getByText('跳转到企业微信', { exact: true }).click()
  await page.getByRole('button', { name: '创建企业微信' }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByPlaceholder('请输入卡片标题').fill('E2E企微链接')
  await dialog.getByPlaceholder('请输入卡片描述').fill('链接管理闭环验收')
  await dialog.locator('input[type=file]').setInputFiles({
    name: 'link-icon.png',
    mimeType: 'image/png',
    buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64')
  })
  await expect(page.getByText('上传成功', { exact: true })).toBeVisible()
  await dialog.getByPlaceholder('请选择', { exact: true }).click()
  await page.locator('.el-select-dropdown__item').filter({ hasText: '127.0.0.1' }).click()
  await dialog.locator('.el-form-item').filter({ hasText: '企业微信客服链接或者获客链接' })
    .locator('input').fill('https://work.weixin.qq.com/ca/readiness-fixture')
  await dialog.getByRole('button', { name: '确定', exact: true }).click()
  await expect(dialog).not.toBeVisible()
  const row = page.getByRole('row').filter({ hasText: 'E2E企微链接' })
  await expect(row).toBeVisible()
  await row.getByRole('button', { name: '编辑', exact: true }).click()
  await expect(dialog.getByPlaceholder('请输入卡片标题')).toHaveValue('E2E企微链接')
  await dialog.getByPlaceholder('请输入卡片描述').fill('编辑已保存')
  await dialog.getByRole('button', { name: '确定', exact: true }).click()
  await expect(row).toContainText('编辑已保存')
  await row.getByRole('button', { name: '复制链接' }).click()
  await expect(page.getByText('复制成功', { exact: true })).toBeVisible()
  const shareUrl = await page.evaluate(() => navigator.clipboard.readText())
  expect(shareUrl).toMatch(/^https:\/\/127\.0\.0\.1\/\?code=[A-Za-z0-9]{8}$/)
  const code = new URL(shareUrl).searchParams.get('code')
  const target = await page.request.get(`/api/link-target/${code}`)
  expect(target.ok()).toBeTruthy()
  expect(await target.json()).toMatchObject({ code: 0, data: { target: 'https://work.weixin.qq.com/ca/readiness-fixture' } })
  await row.getByRole('button', { name: '删除', exact: true }).click()
  await page.getByRole('button', { name: '确定', exact: true }).click()
  await expect(row).not.toBeVisible()
  const deletedTarget = await page.request.get(`/api/link-target/${code}`)
  expect(deletedTarget.status()).toBe(404)
})

test('login reports a network failure and succeeds after retry', async ({ page }) => {
  const errors: string[] = []
  page.on('pageerror', error => errors.push(error.message))
  await page.route('**/api/login', route => route.abort('failed'))
  await login(page, state.username, state.password)
  await expect(page.getByText('网络连接失败，请检查网络后重试', { exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: '立即登录', exact: true })).toBeEnabled()
  await page.unroute('**/api/login')
  await page.getByRole('button', { name: '立即登录', exact: true }).click()
  await expect(page).toHaveURL(/#\/vip-home$/)
  expect(errors).toEqual([])
})

test('startup displays a retry screen when public configuration is unavailable', async ({ page }) => {
  const errors: string[] = []
  page.on('pageerror', error => errors.push(error.message))
  await page.route('**/api/config', route => route.abort('failed'))
  await page.goto(`${adminPath}/#/login`)
  await expect(page.getByText('暂时无法加载系统配置', { exact: true })).toBeVisible()
  await page.unroute('**/api/config')
  const recoveredConfig = page.waitForResponse(response => response.url().endsWith('/api/config'))
  await page.getByRole('button', { name: '重新加载', exact: true }).click()
  expect((await recoveredConfig).status()).toBe(200)
  await expect.poll(() => errors).toEqual([])
  await expect(page.getByPlaceholder('请输入账号', { exact: true })).toBeVisible().catch(error => {
    throw new Error(`${error.message}\nBrowser errors: ${errors.join('; ')}`)
  })
  expect(errors).toEqual([])
})
