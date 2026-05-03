<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Kontrol Persediaan</p>
        <h1>Stock Master</h1>
      </div>
      <button class="primary" @click="loadData" :disabled="loading">{{ loading ? 'Memuat...' : 'Tampilkan Data' }}</button>
    </header>

    <div class="stats" v-if="summary">
      <article class="stat ok"><span>Varian Match</span><strong>{{ summary.total_match || 0 }}</strong></article>
      <article class="stat warn"><span>Variant Missing</span><strong>{{ summary.total_variant_missing || 0 }}</strong></article>
      <article class="stat danger"><span>Product Missing</span><strong>{{ summary.total_product_missing || 0 }}</strong></article>
      <article class="stat diff"><span>Stok Tidak Sama</span><strong>{{ mismatchCount }}</strong></article>
    </div>

    <div class="panel">
      <label class="filter">
        <input type="checkbox" v-model="onlyMismatch">
        Tampilkan hanya stok tidak sama
      </label>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>No</th>
              <th>Nama Produk</th>
              <th>Varian</th>
              <th>Stok Shopee</th>
              <th>Stok TikTok</th>
              <th>Status Varian</th>
              <th>Status Stok</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(item, index) in visibleItems" :key="item.id" :class="{ mismatch: isMismatch(item) }">
              <td>{{ index + 1 }}</td>
              <td>{{ item.product_name }}</td>
              <td>{{ item.variant_name }}</td>
              <td class="center">{{ item.stock_shopee }}</td>
              <td class="center">{{ item.stock_tiktok }}</td>
              <td><span :class="variantClass(item.status_tiktok)">{{ item.status_tiktok }}</span></td>
              <td><span :class="isMismatch(item) ? 'bad' : 'good'">{{ isMismatch(item) ? 'STOK TIDAK SAMA' : 'STOK SAMA' }}</span></td>
            </tr>
            <tr v-if="!visibleItems.length">
              <td colspan="7" class="empty">Belum ada data ditampilkan.</td>
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
const onlyMismatch = ref(false)
const summary = ref(null)
const items = ref([])

const isMismatch = (item) => Number(item.stock_shopee || 0) !== Number(item.stock_tiktok || 0)
const mismatchCount = computed(() => items.value.filter(isMismatch).length)
const visibleItems = computed(() => onlyMismatch.value ? items.value.filter(isMismatch) : items.value)
const variantClass = (status) => status === 'MATCH' ? 'good' : status === 'VARIANT MISSING' ? 'warning' : 'bad'

const loadData = async () => {
  loading.value = true
  try {
    const response = await omnichannelService.stockMaster()
    summary.value = response.data.summary
    items.value = response.data.items || []
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.page-shell { margin-left: 240px; padding: 28px; }
.page-header { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 18px; }
.page-header p { color: #64748b; margin-bottom: 4px; }
.primary { background: #15803d; color: #fff; border: 0; border-radius: 6px; padding: 10px 14px; cursor: pointer; }
.stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
.stat { border-radius: 8px; color: #fff; padding: 14px; }
.stat span { display: block; font-size: 13px; margin-bottom: 6px; }
.stat strong { font-size: 24px; }
.ok { background: #15803d; }
.warn { background: #ea580c; }
.danger { background: #dc2626; }
.diff { background: #6d28d9; }
.panel { background: #fff; border: 1px solid #d9e2ec; border-radius: 8px; padding: 16px; }
.filter { display: inline-flex; gap: 8px; align-items: center; margin-bottom: 12px; font-weight: 700; }
.table-wrap { max-height: 70vh; overflow: auto; }
table { width: 100%; border-collapse: collapse; font-size: 14px; }
th, td { border-bottom: 1px solid #e5e7eb; padding: 10px; text-align: left; vertical-align: top; }
thead th { position: sticky; top: 0; background: #1f2937; color: #fff; }
.center { text-align: center; }
.mismatch { background: #fff1f2; }
.good { color: #15803d; font-weight: 800; }
.warning { color: #ea580c; font-weight: 800; }
.bad { color: #dc2626; font-weight: 800; }
.empty { text-align: center; color: #64748b; }
@media (max-width: 820px) { .page-shell { margin-left: 0; padding: 18px; } .stats { grid-template-columns: 1fr; } }
</style>
