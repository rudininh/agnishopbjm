import fs from 'node:fs/promises'
import os from 'node:os'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { loadMassUploadWorkerConfig } from './config.js'
import { MassUploadClient } from './client.js'
import { UPLOAD_PAGE_URL, massUpdateDocumentRows, validateActiveShop, uploadMassUpdateFile, waitForMassUpdateUploadReady, waitForShopeeProcessing } from './shopee-upload.js'

export async function runMassUploadWorker(config, dependencies = {}) {
  const client = dependencies.client || new MassUploadClient(config)
  const browserApi = dependencies.browserApi || await import('playwright')
  const temporaryDirectory = await fs.mkdtemp(path.join(os.tmpdir(), 'gitashop-mass-upload-'))
  let context
  try {
    await client.heartbeat()
    const claim = await client.claim()
    if (!claim) return { status: 'idle' }

    const { job, file } = claim
    try {
      context = await browserApi.chromium.launchPersistentContext(config.profileDir, { headless: config.headless })
      const page = context.pages()[0] || await context.newPage()
      await page.goto(claim.upload_url || UPLOAD_PAGE_URL, { waitUntil: 'domcontentloaded', timeout: config.timeoutMs })
      await waitForMassUpdateUploadReady(page, config.timeoutMs)
      await validateActiveShop(page, job.expected_shop_name)
      const expectedProcessedCount = file.shopee_expected_processed_count ?? file.row_count
      let leaseError = null
      const renewLease = async () => {
        try {
          await client.renew(job.id, claim.claim_token)
        } catch (error) {
          leaseError = error
        }
      }
      await renewLease()
      if (leaseError) throw leaseError
      const leaseTimer = setInterval(() => { void renewLease() }, Math.min(30000, Math.max(5000, Math.floor(config.timeoutMs / 4))))
      let result
      try {
        if (file.status === 'memproses') {
          result = await waitForShopeeProcessing(page, [], file.filename, expectedProcessedCount, config.timeoutMs, file.uploaded_at)
        } else {
          const baselineRows = await massUpdateDocumentRows(page)
          const filePath = await client.download(job.id, file, claim.claim_token, temporaryDirectory)
          await uploadMassUpdateFile(page, filePath, file.file_type, config.timeoutMs)
          await client.event(job.id, file.id, { claimToken: claim.claim_token, status: 'diunggah', message: 'File diteruskan ke Seller Centre.' })
          await client.event(job.id, file.id, { claimToken: claim.claim_token, status: 'memproses', message: 'Menunggu hasil pemrosesan Seller Centre.' })
          result = await waitForShopeeProcessing(page, baselineRows, file.filename, expectedProcessedCount, config.timeoutMs, new Date().toISOString())
        }
        if (leaseError) throw leaseError
      } finally {
        clearInterval(leaseTimer)
      }
      await client.event(job.id, file.id, { claimToken: claim.claim_token, status: 'selesai', shopee_status: result.shopeeStatus, shopee_processed_count: result.processedCount, message: 'Seller Centre selesai memproses file.' })
      return { status: 'success', jobId: job.id, fileId: file.id }
    } catch (error) {
      const verification = true
      await client.event(job.id, file.id, {
        claimToken: claim.claim_token,
        status: verification ? 'menunggu_verifikasi' : 'gagal',
        error_code: verification ? 'seller_centre_verification' : 'seller_centre_upload',
        message: verification ? 'Seller Centre membutuhkan verifikasi atau halaman upload tidak aman.' : 'Seller Centre tidak dapat menyelesaikan upload.'
      })
      return { status: verification ? 'needs_verification' : 'failed', reason: error.message }
    }
  } finally {
    await context?.close()
    await fs.rm(temporaryDirectory, { recursive: true, force: true })
  }
}

async function acquireWorkerLock(lockPath) {
  await fs.mkdir(path.dirname(lockPath), { recursive: true })
  try {
    const handle = await fs.open(lockPath, 'wx')
    await handle.writeFile(JSON.stringify({ pid: process.pid, startedAt: new Date().toISOString() }))
    return async () => {
      await handle.close()
      await fs.rm(lockPath, { force: true })
    }
  } catch (error) {
    if (error?.code !== 'EEXIST') throw error
    try {
      const previous = JSON.parse(await fs.readFile(lockPath, 'utf8'))
      if (Number.isInteger(previous?.pid)) {
        try {
          process.kill(previous.pid, 0)
          return null
        } catch (processError) {
          if (processError?.code !== 'ESRCH') return null
        }
      }
    } catch {}
    await fs.rm(lockPath, { force: true })
    return acquireWorkerLock(lockPath)
  }
}

async function main() {
  const config = loadMassUploadWorkerConfig()
  const once = process.argv.includes('--once')
  const releaseLock = await acquireWorkerLock(path.join(path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..'), 'backend', 'storage', 'app', 'gitashop-mass-upload-worker.lock'))
  if (!releaseLock) {
    console.log('Gitashop mass upload worker: already_running')
    return
  }
  try {
    do {
      const result = await runMassUploadWorker(config)
      console.log(`Gitashop mass upload worker: ${result.status}${result.reason ? `; reason=${result.reason}` : ''}`)
      if (once) return
      await new Promise((resolve) => setTimeout(resolve, config.pollMs))
    } while (true)
  } finally {
    await releaseLock()
  }
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
  main().catch((error) => {
    console.error(`Gitashop mass upload worker: failed; reason=${error.message}`)
    process.exitCode = 1
  })
}
