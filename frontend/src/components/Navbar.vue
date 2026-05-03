<template>
  <aside class="sidebar">
    <RouterLink to="/dashboard" class="brand">
      <span class="brand-mark">A</span>
      <span>
        <strong>Agni Admin</strong>
        <small>Omnichannel</small>
      </span>
    </RouterLink>

    <nav class="menu">
      <RouterLink to="/dashboard">Dashboard</RouterLink>
      <RouterLink to="/stok-shopee">Stok Shopee</RouterLink>
      <RouterLink to="/stok-tiktok">Stok TikTok</RouterLink>
      <RouterLink to="/stock-master">Stock Master</RouterLink>
      <RouterLink to="/sync-shopee-to-tiktok">Sync Shopee ke TikTok</RouterLink>
      <RouterLink to="/dokumentasi-shopee">Dokumentasi Shopee</RouterLink>
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
}

.brand {
  display: flex;
  gap: 12px;
  align-items: center;
  text-decoration: none;
  margin-bottom: 28px;
}

.brand-mark {
  width: 40px;
  height: 40px;
  border-radius: 8px;
  display: grid;
  place-items: center;
  background: #fff;
  color: #0f5fc7;
  font-weight: 800;
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
