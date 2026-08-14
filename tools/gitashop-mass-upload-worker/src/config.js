import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const rootDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const defaultProfileDir = path.join(rootDir, 'tools', 'gitashop-mass-upload-worker', '.profile')
const gitaProfileDir = path.join(rootDir, 'tools', 'gita-order-scraper', '.profile')

export function backendEnvTokenFromContents(contents, key = 'GITASHOP_MASS_UPLOAD_WORKER_TOKEN') {
  const line = String(contents).split(/\r?\n/).find((item) => item.startsWith(`${key}=`))
  if (!line) return ''
  return line.slice(key.length + 1).trim().replace(/^[']|[']$/g, '')
}

export function assertDedicatedProfile(profileDir) {
  if (path.resolve(profileDir).toLowerCase() === path.resolve(gitaProfileDir).toLowerCase()) {
    throw new Error('Gitashop mass upload worker must not use the Gita order scraper profile.')
  }
}

export function loadMassUploadWorkerConfig(env = process.env, dependencies = {}) {
  const readBackendEnv = dependencies.readBackendEnv || (() => fs.readFileSync(path.join(rootDir, 'backend', '.env'), 'utf8'))
  const profileDir = path.resolve(env.GITASHOP_MASS_UPLOAD_PROFILE_DIR || defaultProfileDir)
  assertDedicatedProfile(profileDir)
  const token = backendEnvTokenFromContents(readBackendEnv())
  if (!token) throw new Error('GITASHOP_MASS_UPLOAD_WORKER_TOKEN is missing from backend/.env.')
  return {
    apiBaseUrl: String(env.GITASHOP_MASS_UPLOAD_API_BASE_URL || 'http://agnishopbjm-laravel.test/api').replace(/\/$/, ''),
    token,
    profileDir,
    headless: String(env.GITASHOP_MASS_UPLOAD_HEADLESS || 'false').toLowerCase() === 'true',
    pollMs: Math.max(1000, Number(env.GITASHOP_MASS_UPLOAD_POLL_SECONDS || 5) * 1000),
    timeoutMs: Math.max(10000, Number(env.GITASHOP_MASS_UPLOAD_TIMEOUT_SECONDS || 60) * 1000),
    workerName: String(env.GITASHOP_MASS_UPLOAD_WORKER_NAME || 'gitashop-mass-upload-worker').slice(0, 150)
  }
}
