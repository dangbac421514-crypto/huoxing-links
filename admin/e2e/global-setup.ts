import { spawnSync } from 'node:child_process'
import { existsSync, mkdirSync, symlinkSync, writeFileSync } from 'node:fs'
import path from 'node:path'

const e2eDir = path.resolve(process.cwd(), 'e2e')
const stateFile = path.join(e2eDir, '.feedback-e2e-state.json')
const testEnv = path.resolve(process.cwd(), '../serve/bin/test-env')
const seed = path.resolve(process.cwd(), '../serve/tests/Support/seed-feedback-e2e.php')

function extractJsonObject(stdout: string): string {
  const lines = stdout
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line.length > 0)
  for (let index = lines.length - 1; index >= 0; index -= 1) {
    const line = lines[index]
    if (!line.startsWith('{') || !line.endsWith('}')) {
      continue
    }
    JSON.parse(line)
    return line
  }
  throw new Error('E2E seed did not print a JSON object on stdout')
}

export default function globalSetup(): void {
  const publicDir = path.resolve(process.cwd(), '../serve/public')
  const storageDir = path.resolve(process.cwd(), '../serve/storage/app/public')
  mkdirSync(storageDir, { recursive: true })
  if (!existsSync(path.join(publicDir, 'storage'))) symlinkSync('../storage/app/public', path.join(publicDir, 'storage'))
  if (process.env.E2E_BUILT === '1' && !existsSync(path.join(publicDir, 'web'))) {
    symlinkSync('../../admin/dist', path.join(publicDir, 'web'))
  }
  const result = spawnSync(testEnv, ['php', seed], {
    encoding: 'utf8',
    env: process.env,
  })
  if (result.status !== 0) {
    throw new Error(result.stderr || `E2E seed exited ${String(result.status)}`)
  }
  const json = extractJsonObject(result.stdout)
  const parsed = JSON.parse(json) as {
    username?: string
    password?: string
    channel_code?: string
  }
  const keys = Object.keys(parsed)
  if (!parsed.username || !parsed.password || !parsed.channel_code) {
    throw new Error('E2E seed JSON keys missing')
  }
  process.env.FEEDBACK_E2E_STATE = json
  writeFileSync(stateFile, json, { mode: 0o600 })
  console.log(`e2e seed ready; JSON keys present: ${keys.join(',')}`)
}
