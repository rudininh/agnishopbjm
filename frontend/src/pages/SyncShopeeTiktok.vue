<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Sinkronisasi</p>
        <h1>Sync Shopee ke TikTok</h1>
      </div>
      <button class="primary" @click="runSync" :disabled="loading">{{ loading ? 'Memproses...' : 'Sync Sekarang' }}</button>
    </header>

    <div class="summary">
      <strong>Total Data: {{ rows.length }}</strong>
      <strong>Stok Tidak Sama: {{ mismatchCount }}</strong>
      <label><input type="checkbox" v-model="onlyMismatch"> Hanya mismatch</label>
    </div>

    <div class="log">{{ logText || 'Belum ada proses sync.' }}</div>

    <div class="panel">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>No</th>
              <th>TikTok Product ID</th>
              <th>TikTok SKU</th>
              <th>Stock Shopee</th>
              <th>Stock TikTok</th>
              <th>Status</th>
              <th>Error</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, index) in visibleRows" :key="`${row.product_id}-${row.sku}-${index}`" :class="{ mismatch: row.is_mismatch }">
              <td>{{ index + 1 }}</td>
              <td>{{ row.product_id }}</td>
              <td>{{ row.sku }}</td>
              <td class="center">{{ row.shopee_stock }}</td>
              <td class="center">{{ row.tiktok_stock }}</td>
              <td><span :class="row.status === 'SUCCESS' ? 'update' : 'skip'">{{ row.status === 'SUCCESS' ? 'UPDATE' : 'STOK SAMA' }}</span></td>
              <td>{{ row.error || '-' }}</td>
            </tr>
            <tr v-if="!visibleRows.length">
              <td colspan="7" class="empty">Belum ada data.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue'
import { omnichannelService } from '@/services'

const loading = ref(false)
const rows = ref([])
const logText = ref('')
const onlyMismatch = ref(false)

const mismatchCount = computed(() => rows.value.filter((row) => row.is_mismatch).length)
const visibleRows = computed(() => onlyMismatch.value ? rows.value.filter((row) => row.is_mismatch) : rows.value)

const runSync = async () => {
  loading.value = true
  logText.value = 'Memulai sinkronisasi...'
  try {
    const response = await omnichannelService.syncShopeeToTiktok()
    rows.value = response.data.items || []
    logText.value = `Selesai. Update: ${response.data.success || 0}, skip: ${response.data.skipped || 0}, failed: ${response.data.failed || 0}. Mode: ${response.data.mode || 'live'}.`
  } catch (error) {
    logText.value = `Error: ${error.message}`
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.page-shell { margin-left: 240px; padding: 28px; }
.page-header { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 18px; }
.page-header p { color: #64748b; margin-bottom: 4px; }
.primary { background: #6d28d9; color: #fff; border: 0; border-radius: 6px; padding: 10px 14px; cursor: pointer; }
.summary { display: flex; flex-wrap: wrap; gap: 18px; align-items: center; background: #e0f2fe; border: 1px solid #bae6fd; border-radius: 8px; padding: 12px; margin-bottom: 12px; }
.summary label { margin-left: auto; display: inline-flex; gap: 8px; }
.log { background: #0b1220; color: #86efac; border-radius: 8px; padding: 12px; font-family: Consolas, monospace; min-height: 90px; margin-bottom: 14px; }
.panel { background: #fff; border: 1px solid #d9e2ec; border-radius: 8px; padding: 16px; }
.table-wrap { max-height: 60vh; overflow: auto; }
table { width: 100%; border-collapse: collapse; font-size: 14px; }
th, td { border-bottom: 1px solid #e5e7eb; padding: 10px; text-align: left; vertical-align: top; }
thead th { position: sticky; top: 0; background: #1f2937; color: #fff; }
.center { text-align: center; }
.mismatch { background: #fef3c7; }
.update, .skip { border-radius: 999px; color: #fff; display: inline-block; padding: 4px 8px; font-size: 12px; font-weight: 800; }
.update { background: #15803d; }
.skip { background: #64748b; }
.empty { text-align: center; color: #64748b; }
@media (max-width: 820px) { .page-shell { margin-left: 0; padding: 18px; } .summary label { margin-left: 0; } }
</style>
