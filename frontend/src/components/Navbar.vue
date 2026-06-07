<template>
  <aside class="sidebar">
    <RouterLink to="/dashboard" class="brand">
      <img class="brand-mark" src="/agni-logo.png?v=20260505" alt="Agni Shop Banjarmasin" />
      <span>
        <strong>Agni Shop</strong>
        <small>Omnichannel</small>
      </span>
    </RouterLink>

    <nav class="menu">
      <RouterLink to="/dashboard">Dashboard</RouterLink>
      <RouterLink to="/stok-shopee">Stok Shopee</RouterLink>
      <RouterLink to="/stok-tiktok">Stok TikTok</RouterLink>
      <RouterLink to="/stock-master">Stock Master</RouterLink>
      <RouterLink to="/sku-mapping">SKU Mapping</RouterLink>
      <RouterLink to="/tambah-varian-tiktok">Tambah Varian TikTok</RouterLink>
      <RouterLink to="/tambah-varian-shopee">Tambah Varian Shopee</RouterLink>
      <RouterLink to="/analisa-product-variant">Analisa Product & Variant</RouterLink>
      <RouterLink to="/detail-produk-marketplace">Detail Produk</RouterLink>
      <RouterLink to="/sync-shopee-to-tiktok">Sync Shopee ke TikTok</RouterLink>
      <RouterLink to="/pos-offline">POS Offline</RouterLink>
      <div class="menu-group">
        <span>Marketplace</span>
        <RouterLink to="/marketplace/auto-sync">Sinkronisasi Otomatis</RouterLink>
        <RouterLink to="/marketplace/cetak-resi">Cetak Resi</RouterLink>
        <RouterLink to="/marketplace/stock-anomalies">Anomali Stok</RouterLink>
      </div>
      <RouterLink to="/dokumentasi-shopee">Dokumentasi Shopee</RouterLink>
      <RouterLink to="/dokumentasi-tiktok">Dokumentasi TikTok</RouterLink>
    </nav>
  </aside>
</template>

<script setup>
import { RouterLink } from 'vue-router'
</script>

<style scoped>
.sidebar {
  position: fixed;
  inset: 0 auto 0 0;
  width: 240px;
  background: #0f5fc7;
  color: #fff;
  display: flex;
  flex-direction: column;
  padding: 18px;
  z-index: 10;
  overflow-y: auto;
}

.brand {
  display: flex;
  gap: 12px;
  align-items: center;
  text-decoration: none;
  margin-bottom: 28px;
}

.brand-mark {
  width: 44px;
  height: 44px;
  border-radius: 8px;
  background: #fff;
  object-fit: cover;
  display: block;
  box-shadow: 0 1px 3px rgba(15, 23, 42, .18);
}

.brand strong,
.brand small {
  display: block;
}

.brand small {
  color: #dbeafe;
  margin-top: 2px;
}

.menu {
  display: grid;
  gap: 8px;
}

.menu a,
.logout {
  border: 0;
  border-radius: 6px;
  color: #fff;
  background: transparent;
  text-align: left;
  text-decoration: none;
  padding: 10px 12px;
  font-size: 14px;
  cursor: pointer;
}

.menu a:hover,
.menu a.router-link-active {
  background: rgba(255, 255, 255, .16);
}

.menu-group {
  display: grid;
  gap: 6px;
  margin-top: 6px;
}

.menu-group span {
  color: #bfdbfe;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .08em;
  padding: 8px 12px 0;
  text-transform: uppercase;
}

.logout {
  margin-top: auto;
  background: rgba(185, 28, 28, .9);
}

@media (max-width: 820px) {
  .sidebar {
    position: static;
    width: 100%;
  }
}
</style>
