const FILE_DEFINITIONS = [
  ['basic-info', 'Informasi Dasar', 'mass_update_basic_info.xlsx'],
  ['sales-info', 'Informasi Penjualan', 'mass_update_sales_info.xlsx'],
  ['media-info', 'Informasi Media', 'mass_update_media_info.xlsx'],
  ['shipping-info', 'Informasi Pengiriman', 'mass_update_shipping_info.xlsx'],
  ['dts-info', 'Informasi Dikirim Dalam', 'mass_update_dts_info.xlsx'],
  ['republish-items', 'Tampilkan Ulang Produk secara Massal', 'mass_republish_items.xlsx']
]

const JOB_STATUS = {
  menunggu_stb: ['Menunggu STB', 'warning', 'Menunggu STB selesai sinkronisasi sebelum upload dijalankan.'],
  berjalan: ['Berjalan', 'info', 'Worker sedang memproses file Mass Update secara berurutan.'],
  menunggu_verifikasi: ['Perlu verifikasi manual', 'warning', 'Login, OTP, CAPTCHA, atau verifikasi tambahan perlu diselesaikan di browser Gitashopcollection.'],
  dibatalkan_aman: ['Dibatalkan demi keamanan', 'warning', 'Upload tidak dijalankan karena STB masih sinkronisasi atau statusnya tidak dapat dipastikan.'],
  selesai_dengan_gagal: ['Selesai dengan kegagalan', 'error', 'Satu atau lebih file gagal sehingga file berikutnya tidak diunggah.'],
  selesai: ['Selesai', 'success', 'Enam file Mass Update telah diproses sesuai audit job.']
}

const FILE_STATUS = {
  menunggu: ['Menunggu', 'neutral'],
  dibuat: ['Dibuat', 'info'],
  diunggah: ['Diunggah', 'info'],
  memproses: ['Diproses Shopee', 'warning'],
  selesai: ['Selesai', 'success'],
  gagal: ['Gagal', 'error'],
  menunggu_verifikasi: ['Perlu verifikasi manual', 'warning']
}

const TERMINAL_STATUSES = new Set(['menunggu_verifikasi', 'dibatalkan_aman', 'selesai_dengan_gagal', 'selesai'])
const WITA_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des']
const UNSAFE_MESSAGE_PATTERN = /authorization|bearer|cookie|html|profile|raw response|token/i

export const isMassUploadTerminal = (status) => TERMINAL_STATUSES.has(status)

export const formatMassUploadWita = (value) => {
  if (!value) return '-'

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '-'

  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Makassar',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23'
  }).formatToParts(date).reduce((result, part) => ({ ...result, [part.type]: part.value }), {})

  return `${parts.day} ${WITA_MONTHS[Number(parts.month) - 1]} ${parts.year}, ${parts.hour}.${parts.minute} WITA`
}

const safeMessage = (message, fallback) => {
  const normalized = typeof message === 'string' ? message.trim() : ''

  if (!normalized) return fallback
  if (UNSAFE_MESSAGE_PATTERN.test(normalized)) return 'Detail aman tersedia pada audit job.'

  return normalized
}

const toFileViewModel = ([fileType, typeLabel, defaultFilename], file = {}) => {
  const status = file.status || 'menunggu'
  const [statusLabel, statusTone] = FILE_STATUS[status] || ['Status tidak dikenal', 'neutral']
  const processedCount = Number.isFinite(Number(file.shopee_processed_count)) ? Number(file.shopee_processed_count) : null
  const shopeeStatus = typeof file.shopee_status === 'string' ? file.shopee_status.trim() : ''

  return {
    id: file.id || null,
    sequence: file.sequence || null,
    fileType,
    typeLabel,
    filename: file.filename || defaultFilename,
    rowCount: Number.isFinite(Number(file.row_count)) ? Number(file.row_count) : 0,
    rowCountLabel: `${Number.isFinite(Number(file.row_count)) ? Number(file.row_count) : 0} baris`,
    hashPrefix: typeof file.sha256 === 'string' && file.sha256 ? file.sha256.slice(0, 12) : '-',
    status,
    statusLabel,
    statusTone,
    shopeeStatus: shopeeStatus || '-',
    shopeeProcessedCount: processedCount,
    shopeeProcessedLabel: processedCount === null
      ? (shopeeStatus || 'Belum diproses')
      : `${shopeeStatus || 'Status Shopee'}: ${processedCount} diproses`,
    createdAt: formatMassUploadWita(file.created_at_worker || file.created_at),
    uploadedAt: formatMassUploadWita(file.uploaded_at),
    completedAt: formatMassUploadWita(file.completed_at),
    message: safeMessage(file.message, '-')
  }
}

export const toMassUploadViewModel = (job) => {
  if (!job) return null

  const [statusLabel, statusTone, fallbackMessage] = JOB_STATUS[job.status] || ['Status tidak dikenal', 'neutral', 'Status job tidak dapat dikenali.']
  const filesByType = new Map((Array.isArray(job.files) ? job.files : []).map((file) => [file.file_type, file]))
  const files = FILE_DEFINITIONS.map((definition) => toFileViewModel(definition, filesByType.get(definition[0])))
  const activeFile = files.find((file) => ['dibuat', 'diunggah', 'memproses', 'menunggu_verifikasi'].includes(file.status)) || null
  const isTerminal = isMassUploadTerminal(job.status)

  return {
    id: job.id || null,
    accountKey: 'shopee-gitacollectionbjm',
    shopName: 'Gitashopcollection',
    status: job.status || 'tidak_dikenal',
    statusLabel,
    statusTone,
    isTerminal,
    canStartNewJob: isTerminal,
    message: safeMessage(job.message, fallbackMessage),
    requestedAt: formatMassUploadWita(job.requested_at),
    startedAt: formatMassUploadWita(job.started_at),
    finishedAt: formatMassUploadWita(job.finished_at),
    activeFile,
    files
  }
}
