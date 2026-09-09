const deliveryStateLabels = {
  mapped_pending: 'Siap dikirim saat live push diaktifkan',
  mapping_required: 'Perlu mapping varian',
  disabled: 'Akun tidak aktif'
}

export function createAdjustmentPayload({ stockMasterId, direction, quantity, reason, note = '' }) {
  const normalizedStockMasterId = Number(stockMasterId)
  const normalizedQuantity = Number(quantity)

  if (!Number.isInteger(normalizedStockMasterId) || normalizedStockMasterId < 1) {
    throw new Error('Silakan pilih varian Stock Master.')
  }

  if (!Number.isInteger(normalizedQuantity) || normalizedQuantity < 1) {
    throw new Error('Jumlah penyesuaian harus lebih dari nol.')
  }

  if (!['increase', 'decrease'].includes(direction)) {
    throw new Error('Arah penyesuaian tidak valid.')
  }

  if (!['receiving', 'sale', 'return', 'damaged', 'correction'].includes(reason)) {
    throw new Error('Alasan penyesuaian wajib dipilih.')
  }

  return {
    stock_master_id: normalizedStockMasterId,
    delta: direction === 'decrease' ? -normalizedQuantity : normalizedQuantity,
    reason,
    note: String(note).trim()
  }
}

export function formatDeliveryState(status) {
  return deliveryStateLabels[status] || 'Status pengiriman belum tersedia'
}
