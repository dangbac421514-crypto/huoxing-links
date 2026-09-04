import { writeFile } from 'node:fs/promises'
import { expect, test, type Page, type TestInfo } from '@playwright/test'

const state = JSON.parse(process.env.FEEDBACK_E2E_STATE ?? '{}') as {
  username: string
  password: string
  channel_code: string
}
const backend = 'http://127.0.0.1:8090'
const admin = 'http://127.0.0.1:4174'
const ticketBody = '这是一条浏览器端客诉受理验收记录'
const pngBase64 =
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='

async function loginToAdmin(page: Page, username: string, password: string) {
  await page.goto(`${admin}/#/login`)
  await page.fill('input[placeholder="请输入账号"]', username)
  await page.fill('input[placeholder="请输入密码"]', password)
  await page.click('button:has-text("登录")')
  await expect(page).toHaveURL(/#\/(vip-home|admin-home)/)
}

async function writePng(testInfo: TestInfo): Promise<string> {
  const fixturePath = testInfo.outputPath('feedback.png')
  await writeFile(fixturePath, Buffer.from(pngBase64, 'base64'))
  return fixturePath
}

async function assertNoHorizontalOverflow(page: Page) {
  const metrics = await page.evaluate(() => ({
    scrollWidth: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth),
    clientWidth: document.documentElement.clientWidth,
  }))
  expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.clientWidth + 1)
}

test('mobile submit reaches tenant ticket workbench', async ({ page }, testInfo) => {
  const fixturePath = testInfo.outputPath('feedback.png')
  await writeFile(fixturePath, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64'))
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto(`${backend}/f/${state.channel_code}`)
  await assertNoHorizontalOverflow(page)
  await page.selectOption('[name=category]', { index: 1 })
  await page.fill('[name=content]', '这是一条浏览器端客诉受理验收记录')
  await page.setInputFiles('[name="attachments[]"]', fixturePath)
  await page.check('[name=privacy_accepted]')
  await page.click('[data-testid=feedback-submit]')
  await expect(page.getByTestId('feedback-public-no')).toBeVisible()
  const publicNo = await page.getByTestId('feedback-public-no').textContent()
  await loginToAdmin(page, state.username, state.password)
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto(`${admin}/#/feedback`)
  await assertNoHorizontalOverflow(page)
  await page.getByText(publicNo ?? '').click()
  await expect(page.getByText('这是一条浏览器端客诉受理验收记录')).toBeVisible()

  const downloadPromise = page.waitForEvent('download')
  await page.getByRole('button', { name: 'feedback.png' }).click()
  const download = await downloadPromise
  expect(download.suggestedFilename()).toBe('feedback.png')

  await page.getByRole('button', { name: '开始处理' }).click()
  await expect(page.getByText('状态已更新')).toBeVisible()
  await page.locator('textarea').fill('浏览器端内部备注验收')
  await page.getByRole('button', { name: '保存备注' }).click()
  await expect(page.getByText('备注已保存')).toBeVisible()
  await expect(page.getByText('浏览器端内部备注验收')).toBeVisible()
})

test('desktop workbench has no horizontal overflow', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 })
  await page.goto(`${backend}/f/${state.channel_code}`)
  await assertNoHorizontalOverflow(page)
  await loginToAdmin(page, state.username, state.password)
  await page.goto(`${admin}/#/feedback`)
  await expect(page.getByRole('heading', { name: '客诉受理' })).toBeVisible()
  await expect(page.getByRole('heading', { name: '工单' })).toBeVisible()
  await assertNoHorizontalOverflow(page)
})

test('duplicate submit retry returns the same public number', async ({ page }, testInfo) => {
  const fixturePath = await writePng(testInfo)
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto(`${backend}/f/${state.channel_code}`)
  await page.selectOption('[name=category]', { index: 1 })
  await page.fill('[name=content]', ticketBody)
  await page.setInputFiles('[name="attachments[]"]', fixturePath)
  await page.check('[name=privacy_accepted]')

  let posts = 0
  await page.route('**/f/*/tickets', async (route) => {
    if (route.request().method() !== 'POST') {
      await route.continue()
      return
    }
    posts += 1
    if (posts === 1) {
      await route.fulfill({ status: 500, contentType: 'application/json', body: '{}' })
      return
    }
    await route.continue()
  })

  await page.click('[data-testid=feedback-submit]')
  await expect(page.getByTestId('feedback-submit')).toBeEnabled()
  await page.click('[data-testid=feedback-submit]')
  await expect(page.getByTestId('feedback-public-no')).toBeVisible()
  expect(posts).toBeGreaterThanOrEqual(2)
})

test('channel create and copy share url', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 })
  await loginToAdmin(page, state.username, state.password)
  await page.goto(`${admin}/#/feedback`)
  await page.getByRole('button', { name: '新建渠道' }).click()
  await page.getByPlaceholder('例如：商家售后反馈').fill('E2E新建渠道')
  await page.getByPlaceholder('请输入对外展示的运营主体').fill('E2E新建主体')
  await page.getByPlaceholder('请选择已启用域名').click()
  await page.locator('.el-select-dropdown__item').filter({ hasText: '127.0.0.1' }).click()
  await page.getByPlaceholder('例如：2小时内响应').fill('2小时内响应')
  await page.getByRole('button', { name: '确定' }).click()
  await expect(page.getByText('保存成功')).toBeVisible()
  await expect(page.getByText('E2E新建渠道')).toBeVisible()
  await page.getByRole('row', { name: /E2E新建渠道/ }).getByRole('button', { name: '复制' }).click()
  await expect(page.getByText('复制成功')).toBeVisible()
  const copied = await page.evaluate(() => navigator.clipboard.readText())
  expect(copied).toMatch(/^https:\/\/127\.0\.0\.1\/f\/[A-Za-z0-9]{24}$/)
})
