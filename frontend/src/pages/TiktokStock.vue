<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Marketplace</p>
        <h1>Stok TikTok</h1>
      </div>
      <button class="primary" @click="loadData" :disabled="loading">{{ loading ? 'Memuat...' : 'Tampilkan Produk' }}</button>
    </header>

    <div class="panel">
      <div class="panel-head">
        <div>
          <span>TikTok Shop</span>
          <strong>AgniShopBJM</strong>
        </div>
        <small>{{ items.length }} produk</small>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>No</th>
              <th>Nama Produk</th>
              <th>SKU</th>
              <th>Stok</th>
              <th>Nilai Stok</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="(item, index) in pagedItems" :key="item.product_id">
              <tr class="clickable" @click="toggle(item.product_id)">
                <td>{{ pageStart + index + 1 }}</td>
                <td>{{ item.product_name }}</td>
                <td>{{ item.skus?.[0]?.sku_name || '-' }}</td>
                <td class="center">{{ totalStock(item.skus) }}</td>
                <td class="right">{{ formatCurrency(totalValue(item.skus)) }}</td>
              </tr>
              <tr v-if="expanded[item.product_id]" class="detail-row">
                <td colspan="5">
                  <ul>
                    <li v-for="sku in item.skus" :key="sku.sku_name">
                      {{ sku.sku_name || '-' }} - Stok: {{ sku.stock_qty || 0 }} - Harga: {{ formatCurrency(sku.price || 0) }} - Total: {{ formatCurrency(sku.subtotal || 0) }}
                    </li>
                  </ul>
                </td>
              </tr>
            </template>
            <tr v-if="!items.length">
              <td colspan="5" class="empty">Belum ada data ditampilkan.</td>
            </tr>
          </tbody>
          <tfoot v-if="items.length">
            <tr>
              <td colspan="3" class="right">Total semua produk</td>
              <td class="center">{{ grandStock }}</td>
              <td class="right">{{ formatCurrency(grandValue) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
      <div v-if="items.length" class="pagination">
        <button type="button" :disabled="page === 1" @click="setPage(page - 1)">Prev</button>
        <span>Halaman {{ page }} dari {{ totalPages }}</span>
        <button type="button" :disabled="page === totalPages" @click="setPage(page + 1)">Next</button>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue'
import { omnichannelService } from '@/services'

const items = ref([])
const expanded = ref({})
const loading = ref(false)
const page = ref(1)
const PAGE_SIZE = 20

const formatCurrency = (value) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value || 0)
const totalStock = (skus) => (skus || []).reduce((sum, item) => sum + Number(item.stock_qty || 0), 0)
const totalValue = (skus) => (skus || []).reduce((sum, item) => sum + Number(item.subtotal || 0), 0)
const grandStock = computed(() => items.value.reduce((sum, item) => sum + totalStock(item.skus), 0))
const grandValue = computed(() => items.value.reduce((sum, item) => sum + totalValue(item.skus), 0))
const totalPages = computed(() => Math.max(1, Math.ceil(items.value.length / PAGE_SIZE)))
const pageStart = computed(() => (page.value - 1) * PAGE_SIZE)
const pagedItems = computed(() => items.value.slice(pageStart.value, pageStart.value + PAGE_SIZE))
const toggle = (id) => { expanded.value[id] = !expanded.value[id] }
const setPage = (nextPage) => {
  page.value = Math.min(Math.max(Number(nextPage) || 1, 1), totalPages.value)
}

const loadData = async () => {
  loading.value = true
  try {
    const response = await omnichannelService.tiktokItems()
    items.value = response.data.items || []
    page.value = 1
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.page-shell { margin-left: 240px; padding: 28px; }
.page-header { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 18px; }
.page-header p { color: #64748b; margin-bottom: 4px; }
.primary { background: #111827; color: #fff; border: 0; border-radius: 6px; padding: 10px 14px; cursor: pointer; }
.panel { background: #fff; border: 1px solid #d9e2ec; border-radius: 8px; overflow: hidden; }
.panel-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; border-bottom: 1px solid #e5e7eb; background: #f8fafc; }
.panel-head span, .panel-head small { color: #64748b; font-size: 12px; }
.panel-head strong { color: #111827; display: block; margin-top: 3px; }
.table-wrap { max-height: 72vh; overflow: auto; }
table { width: 100%; border-collapse: collapse; font-size: 14px; }
th, td { border-bottom: 1px solid #e5e7eb; padding: 10px; text-align: left; vertical-align: top; }
thead th { position: sticky; top: 0; background: #1f2937; color: #fff; }
tfoot td { background: #eef2f7; font-weight: 800; }
.center { text-align: center; }
.right { text-align: right; }
.clickable { cursor: pointer; }
.clickable:hover { background: #eff6ff; }
.detail-row td { background: #f8fafc; }
.empty { text-align: center; color: #64748b; }
.pagination { display: flex; align-items: center; justify-content: flex-end; gap: 10px; padding: 12px 16px; border-top: 1px solid #e5e7eb; background: #fff; }
.pagination button { border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; color: #334155; cursor: pointer; font-weight: 700; padding: 7px 10px; }
.pagination button:disabled { cursor: not-allowed; opacity: .45; }
.pagination span { color: #64748b; font-size: 13px; }
@media (max-width: 820px) { .page-shell { margin-left: 0; padding: 18px; } }
</style>
