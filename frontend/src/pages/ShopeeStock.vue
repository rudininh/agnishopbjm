<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Marketplace</p>
        <h1>Stok Shopee</h1>
      </div>
      <button class="primary shopee" @click="loadData" :disabled="loading">{{ loading ? 'Memuat...' : 'Tampilkan Produk' }}</button>
    </header>

    <div class="panel">
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
            <template v-for="(item, index) in items" :key="item.item_id">
              <tr class="clickable" @click="toggle(item.item_id)">
                <td>{{ index + 1 }}</td>
                <td>{{ item.nama }}</td>
                <td>{{ item.sku || '-' }}</td>
                <td class="center">{{ totalStock(item.models) }}</td>
                <td class="right">{{ formatCurrency(totalValue(item.models)) }}</td>
              </tr>
              <tr v-if="expanded[item.item_id]" class="detail-row">
                <td colspan="5">
                  <ul>
                    <li v-for="model in item.models" :key="model.model_id">
                      {{ model.name || '-' }} - Stok: {{ model.stock || 0 }} - Harga: {{ formatCurrency(model.price || 0) }} - Total: {{ formatCurrency((model.stock || 0) * (model.price || 0)) }}
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
    </div>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue'
import { omnichannelService } from '@/services'

const items = ref([])
const expanded = ref({})
const loading = ref(false)

const formatCurrency = (value) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value || 0)
const totalStock = (models) => (models || []).reduce((sum, item) => sum + Number(item.stock || 0), 0)
const totalValue = (models) => (models || []).reduce((sum, item) => sum + Number(item.stock || 0) * Number(item.price || 0), 0)
const grandStock = computed(() => items.value.reduce((sum, item) => sum + totalStock(item.models), 0))
const grandValue = computed(() => items.value.reduce((sum, item) => sum + totalValue(item.models), 0))

const toggle = (id) => {
  expanded.value[id] = !expanded.value[id]
}

const loadData = async () => {
  loading.value = true
  try {
    const response = await omnichannelService.shopeeItems()
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
.primary { color: #fff; border: 0; border-radius: 6px; padding: 10px 14px; cursor: pointer; }
.shopee { background: #f97316; }
.panel { background: #fff; border: 1px solid #d9e2ec; border-radius: 8px; padding: 16px; }
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
@media (max-width: 820px) { .page-shell { margin-left: 0; padding: 18px; } }
</style>
