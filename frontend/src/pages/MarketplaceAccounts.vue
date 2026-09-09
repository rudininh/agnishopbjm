<template>
  <section class="page-shell">
    <header class="page-header">
      <div><p>Marketplace</p><h1>Akun Marketplace</h1><span>Tambah toko baru tanpa mengubah halaman Shopee/TikTok lama.</span></div>
      <button class="primary" @click="startCreate">Tambah Akun</button>
    </header>

    <p v-if="message" :class="['message', messageTone]">{{ message }}</p>
    <section class="layout">
      <div class="panel">
        <h2>{{ editingKey ? 'Edit Akun' : 'Akun Baru' }}</h2>
        <form @submit.prevent="saveAccount">
          <label><span>Account Key</span><input v-model.trim="form.account_key" :disabled="Boolean(editingKey)" required placeholder="shopee-toko-baru" /></label>
          <label><span>Nama Toko</span><input v-model.trim="form.name" required placeholder="Shopee Toko Baru" /></label>
          <label><span>Channel</span><select v-model="form.channel" :disabled="Boolean(editingKey)"><option value="shopee">Shopee</option><option value="tiktok">TikTok</option></select></label>
          <label class="check"><input v-model="form.enabled" type="checkbox" /><span>Aktif digunakan</span></label>

          <h3>Credential {{ editingKey ? '(isi hanya jika ingin mengganti)' : '' }}</h3>
          <template v-if="form.channel === 'shopee'">
            <label><span>Partner ID</span><input v-model="form.credentials.partner_id" type="number" min="1" /></label>
            <label><span>Partner Key</span><input v-model="form.credentials.partner_key" type="password" autocomplete="new-password" /></label>
            <label><span>Host</span><input v-model.trim="form.credentials.host" placeholder="https://partner.shopeemobile.com" /></label>
            <label><span>Redirect URL</span><input v-model.trim="form.credentials.redirect_url" placeholder="https://domain/api/callback" /></label>
          </template>
          <template v-else>
            <label><span>App Key</span><input v-model.trim="form.credentials.app_key" /></label>
            <label><span>App Secret</span><input v-model="form.credentials.app_secret" type="password" autocomplete="new-password" /></label>
            <label><span>Auth Host</span><input v-model.trim="form.credentials.auth_host" placeholder="https://auth.tiktok-shops.com" /></label>
            <label><span>API Host</span><input v-model.trim="form.credentials.api_host" placeholder="https://open-api.tiktokglobalshop.com" /></label>
            <label><span>Redirect URL</span><input v-model.trim="form.credentials.redirect_url" /></label>
            <label><span>Warehouse ID</span><input v-model.trim="form.credentials.warehouse_id" /></label>
          </template>
          <div class="form-actions"><button type="button" class="ghost" @click="startCreate">Reset</button><button class="primary" :disabled="saving">{{ saving ? 'Menyimpan...' : 'Simpan Akun' }}</button></div>
        </form>
      </div>

      <div class="panel"><h2>Daftar Toko</h2><div class="account-list">
        <article v-for="account in accounts" :key="account.key" class="account-card">
          <div><strong>{{ account.name }}</strong><span>{{ account.channelLabel }} · {{ account.key }}</span></div>
          <span :class="['badge', account.enabled ? 'on' : 'off']">{{ account.enabled ? 'Aktif' : 'Nonaktif' }}</span>
          <small>{{ account.credentialsConfigured ? 'Credential tersedia' : 'Credential belum lengkap' }}</small>
          <div class="card-actions"><button class="ghost small" @click="editAccount(account)">Edit</button><button class="ghost small" @click="toggleAccount(account)">{{ account.enabled ? 'Nonaktifkan' : 'Aktifkan' }}</button><button class="ghost small" @click="testAccount(account)" :disabled="testingKey === account.key">{{ testingKey === account.key ? 'Testing...' : 'Test' }}</button></div>
        </article>
        <p v-if="!accounts.length" class="empty">Belum ada data akun. Pastikan sudah login.</p>
      </div></div>
    </section>
  </section>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { omnichannelService } from '@/services'
import { accountPayload, emptyMarketplaceAccountForm, normalizeMarketplaceAccounts } from './marketplaceAccountsState'

const accounts = ref([])
const form = reactive(emptyMarketplaceAccountForm())
const editingKey = ref('')
const saving = ref(false)
const testingKey = ref('')
const message = ref('')
const messageTone = ref('info')

function startCreate() { Object.assign(form, emptyMarketplaceAccountForm()); editingKey.value = ''; message.value = '' }
function editAccount(account) { startCreate(); editingKey.value = account.key; Object.assign(form, { account_key: account.key, name: account.name, channel: account.channel, enabled: account.enabled }) }
function errorMessage(error, fallback) { return error.response?.data?.message || fallback }
async function loadAccounts() { try { accounts.value = normalizeMarketplaceAccounts((await omnichannelService.marketplaceAccounts()).data) } catch (error) { messageTone.value = 'error'; message.value = errorMessage(error, 'Daftar akun gagal dimuat.') } }
async function saveAccount() {
  saving.value = true; message.value = ''
  try {
    const payload = accountPayload(form)
    const response = editingKey.value ? await omnichannelService.updateMarketplaceAccount(editingKey.value, payload) : await omnichannelService.createMarketplaceAccount(payload)
    messageTone.value = 'info'; message.value = response.data?.message || 'Akun marketplace tersimpan.'
    startCreate(); await loadAccounts()
  } catch (error) { messageTone.value = 'error'; message.value = errorMessage(error, 'Akun gagal disimpan.') } finally { saving.value = false }
}
async function toggleAccount(account) {
  try { await omnichannelService.updateMarketplaceAccount(account.key, { enabled: !account.enabled }); await loadAccounts() } catch (error) { messageTone.value = 'error'; message.value = errorMessage(error, 'Status akun gagal diubah.') }
}
async function testAccount(account) {
  testingKey.value = account.key; message.value = ''
  try { const response = await omnichannelService.testMarketplaceAccount(account.key); messageTone.value = 'info'; message.value = response.data?.message || 'Credential akun siap digunakan.' } catch (error) { messageTone.value = 'error'; message.value = errorMessage(error, 'Test akun gagal.') } finally { testingKey.value = '' }
}
onMounted(loadAccounts)
</script>

<style scoped>
.page-shell { margin-left: 240px; padding: 28px; }
.page-header, .form-actions, .card-actions { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.page-header { margin-bottom: 20px; }
.page-header p { color: #64748b; margin: 0 0 4px; }.page-header h1 { margin: 0 0 5px; }.page-header span { color: #64748b; }
.primary, .ghost { border-radius: 7px; padding: 10px 14px; font-weight: 700; cursor: pointer; }.primary { background: #0f5fc7; color: #fff; border: 0; }.ghost { background: #fff; color: #334155; border: 1px solid #cbd5e1; }.small { padding: 7px 9px; font-size: 12px; }
.layout { display: grid; grid-template-columns: minmax(300px, 420px) 1fr; gap: 18px; }.panel { background: #fff; border: 1px solid #d9e2ec; border-radius: 9px; padding: 18px; }.panel h2 { margin: 0 0 16px; font-size: 18px; }.panel h3 { margin: 20px 0 12px; font-size: 14px; }
form { display: grid; gap: 12px; }label { display: grid; gap: 6px; }label > span { color: #64748b; font-size: 12px; font-weight: 800; text-transform: uppercase; }input, select { border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px; }.check { display: flex; align-items: center; gap: 8px; }.check span { text-transform: none; }.form-actions { justify-content: flex-end; margin-top: 6px; }
.account-list { display: grid; gap: 10px; }.account-card { display: grid; grid-template-columns: 1fr auto; gap: 8px 12px; border: 1px solid #d9e2ec; border-radius: 8px; padding: 14px; }.account-card strong, .account-card span, .account-card small { display: block; }.account-card div span { color: #64748b; font-size: 12px; margin-top: 4px; }.account-card small { color: #64748b; }.badge { align-self: start; border-radius: 999px; padding: 4px 8px; font-size: 12px; font-weight: 700; }.badge.on { color: #166534; background: #dcfce7; }.badge.off { color: #475569; background: #e2e8f0; }.card-actions { grid-column: 1 / -1; justify-content: flex-start; flex-wrap: wrap; }.message { padding: 10px 12px; border-radius: 7px; color: #1d4ed8; background: #eff6ff; }.message.error { color: #b91c1c; background: #fef2f2; }.empty { color: #64748b; }
@media (max-width: 900px) { .page-shell { margin-left: 0; padding: 18px; }.layout { grid-template-columns: 1fr; } }
</style>
