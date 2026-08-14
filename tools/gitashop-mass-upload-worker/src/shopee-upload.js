export const UPLOAD_PAGE_URL = 'https://seller.shopee.co.id/portal/product-mass/mass-update/upload'

export function normalizeShopName(value) {
  return String(value || '').toLowerCase().replace(/[^a-z0-9]/g, '')
}

export function assertExpectedShop(actual, expected) {
  if (normalizeShopName(actual) !== normalizeShopName(expected)) {
    throw new Error(`Expected shop ${expected} is not active.`)
  }
}

export function classifySellerCentreState(state) {
  if (state && (state.captcha || state.otp || state.verification)) return 'needs_verification'
  if (state && state.login) return 'needs_login'
  if (!state || !state.uploadReady) return 'unexpected_page'
  return 'ready'
}

export async function waitForMassUpdateUploadReady(page, timeoutMs) {
  await page.waitForFunction(() => {
    const text = document.body?.innerText || ''
    return Boolean(document.querySelector('input[type=file]'))
      && /Pilih atau letakkan file excel di sini/i.test(text)
      && /Mass Update/i.test(text)
  }, undefined, { timeout: timeoutMs })
}

export async function inspectSellerCentreState(page, expectedName = '') {
  return page.evaluate((expected) => {
    const text = document.body?.innerText || ''
    const fileInput = document.querySelector('input[type=file]')
    const expectedNormalized = String(expected || '').toLowerCase().replace(/[^a-z0-9]/g, '')
    const shopText = [...document.querySelectorAll('body *')]
      .filter((element) => {
        if (!(element instanceof HTMLElement) || element.children.length > 0) return false
        const visibleText = (element.innerText || element.textContent || '').trim()
        const normalizedText = visibleText.toLowerCase().replace(/[^a-z0-9]/g, '')
        const rect = element.getBoundingClientRect()
        const style = window.getComputedStyle(element)
        return expectedNormalized !== ''
          && normalizedText === expectedNormalized
          && rect.width > 0
          && rect.height > 0
          && style.visibility !== 'hidden'
          && style.display !== 'none'
      })
      .map((element) => (element.innerText || element.textContent || '').trim())
      .join(' | ')

    return {
      login: /\b(login|masuk)\b/i.test(text) && /password|email|nomor telepon/i.test(text),
      otp: /\bOTP\b|kode verifikasi/i.test(text),
      captcha: /captcha|verifikasi keamanan/i.test(text),
      verification: /verifikasi tambahan|konfirmasi identitas/i.test(text),
      uploadReady: Boolean(fileInput) && /Pilih atau letakkan file excel di sini/i.test(text),
      shopText
    }
  }, expectedName)
}

export async function validateActiveShop(page, expectedName) {
  const state = await inspectSellerCentreState(page, expectedName)
  const classification = classifySellerCentreState(state)
  if (classification !== 'ready') throw new Error(`Seller Centre requires safe stop: ${classification}.`)
  assertExpectedShop(state.shopText, expectedName)
}

export async function uploadMassUpdateFile(page, filePath, expectedFileType, timeoutMs) {
  const input = page.locator('input[type=file]')
  if (await input.count() !== 1) throw new Error('Mass Update file input was not found exactly once.')
  await input.setInputFiles(filePath, { timeout: timeoutMs })
  return { expectedFileType }
}

function sellerCentreMinuteKey(value) {
  const match = String(value || '').match(/(\d{2})\/(\d{2})\/(\d{4})\s+(\d{2}):(\d{2})/)
  if (!match) return ''
  return `${match[3]}-${match[2]}-${match[1]} ${match[4]}:${match[5]}`
}

function uploadMinuteKey(value) {
  const normalized = String(value || '').trim().replace('T', ' ')
  const match = normalized.match(/(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})/)
  if (!match) return ''
  return `${match[1]}-${match[2]}-${match[3]} ${match[4]}:${match[5]}`
}

export function findNewCompletedMassUpdateDocument(rows, baselineRows, expectedFilename, expectedCount, uploadedAt = '') {
  const baseline = new Set((baselineRows || []).map((row) => String(row).trim()))
  const escapedBase = String(expectedFilename).replace(/\.[^.]+$/, '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  const extension = String(expectedFilename).split('.').at(-1).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  const filenamePattern = new RegExp(`${escapedBase}(?:\\(\\d+\\))?\\.${extension}`, 'i')
  const minimumMinute = uploadMinuteKey(uploadedAt)

  for (const row of rows || []) {
    const text = String(row).trim().replace(/\s+/g, ' ')
    if (baseline.has(text) || !filenamePattern.test(text) || !/\bSelesai\b/i.test(text)) continue
    if (minimumMinute && sellerCentreMinuteKey(text) < minimumMinute) continue
    const numbers = [...text.matchAll(/\b\d+\b/g)].map((match) => Number(match[0]))
    if (numbers.at(-1) === expectedCount) return { shopeeStatus: 'Selesai', processedCount: expectedCount }
  }

  return null
}

export async function massUpdateDocumentRows(page) {
  return page.evaluate(() => [...document.querySelectorAll('tr,[role=row],.eds-table__row')]
    .map((row) => (row.innerText || '').trim().replace(/\s+/g, ' '))
    .filter(Boolean))
}

export async function waitForShopeeProcessing(page, baselineRows, expectedFilename, expectedCount, timeoutMs, uploadedAt = '') {
  const deadline = Date.now() + timeoutMs
  while (Date.now() < deadline) {
    const result = findNewCompletedMassUpdateDocument(await massUpdateDocumentRows(page), baselineRows, expectedFilename, expectedCount, uploadedAt)
    if (result) return result
    await page.waitForTimeout(500)
  }
  throw new Error('Seller Centre completion row was not found before timeout.')
}
