import assert from 'node:assert/strict'
import test from 'node:test'
import { assertDedicatedProfile, backendEnvTokenFromContents, loadMassUploadWorkerConfig } from '../src/config.js'

test('reads the dedicated token from backend environment content', () => {
  assert.equal(backendEnvTokenFromContents('GITASHOP_MASS_UPLOAD_WORKER_TOKEN=worker-token\n'), 'worker-token')
  const config = loadMassUploadWorkerConfig({}, { readBackendEnv: () => 'GITASHOP_MASS_UPLOAD_WORKER_TOKEN=worker-token\n' })
  assert.equal(config.token, 'worker-token')
  assert.equal(config.headless, false)
})

test('rejects the Gita order worker profile', () => {
  assert.throws(() => assertDedicatedProfile('tools/gita-order-scraper/.profile'), /must not use/i)
})
