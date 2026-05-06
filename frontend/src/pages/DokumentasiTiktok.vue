<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>TikTok Shop Open API</p>
        <h1>Dokumentasi TikTok</h1>
      </div>
      <a class="primary" :href="sourceUrl" target="_blank" rel="noreferrer">Buka Sumber</a>
    </header>

    <div class="summary-grid">
      <article v-for="item in summary" :key="item.label" class="summary-card">
        <span>{{ item.label }}</span>
        <strong>{{ item.value }}</strong>
      </article>
    </div>

    <section class="panel">
      <div class="panel-title">
        <div>
          <h2>Alur Dashboard</h2>
          <p>Urutan tombol yang dipakai untuk menghubungkan TikTok Shop ke aplikasi.</p>
        </div>
      </div>

      <div class="flow-grid">
        <article v-for="step in flow" :key="step.title" class="flow-card">
          <span>{{ step.number }}</span>
          <strong>{{ step.title }}</strong>
          <p>{{ step.description }}</p>
          <code>{{ step.endpoint }}</code>
        </article>
      </div>
    </section>

    <section class="docs-layout">
      <aside class="doc-nav panel">
        <div class="panel-title compact">
          <h2>Sub Link Dokumentasi</h2>
        </div>

        <label class="search-box">
          <span>Cari API</span>
          <input v-model="query" type="search" placeholder="token, shop, product..." />
        </label>

        <div class="module-list">
          <details v-for="module in filteredModules" :key="module.name" open>
            <summary>
              <span>{{ module.name }}</span>
              <small>{{ module.items.length }} link</small>
            </summary>
            <a
              v-for="item in module.items"
              :key="item.name"
              :href="item.url"
              target="_blank"
              rel="noreferrer"
            >
              {{ item.name }}
            </a>
          </details>
        </div>
      </aside>

      <div class="doc-content">
        <section v-for="api in apis" :key="api.name" class="panel">
          <div class="panel-title compact">
            <div>
              <h2>{{ api.name }}</h2>
              <p>{{ api.description }}</p>
            </div>
          </div>

          <dl class="endpoint-list">
            <div>
              <dt>Method</dt>
              <dd>{{ api.method }}</dd>
            </div>
            <div>
              <dt>URL</dt>
              <dd>{{ api.url }}</dd>
            </div>
            <div>
              <dt>Dipakai Tombol</dt>
              <dd>{{ api.button }}</dd>
            </div>
          </dl>

          <ParameterTable :rows="api.params" />
        </section>

        <section class="panel">
          <div class="panel-title compact">
            <h2>Signature API Shop</h2>
          </div>
          <pre><code>{{ signNotes }}</code></pre>
        </section>
      </div>
    </section>
  </section>
</template>

<script setup>
import { computed, defineComponent, h, ref } from 'vue'

const sourceUrl = 'https://www.postman.com/tiktok-shop-open/tiktok-shop-public-workspace/documentation/zy4oj7h/tiktok-shop-open-api'

const summary = [
  { label: 'OAuth Host', value: 'auth.tiktok-shops.com' },
  { label: 'Open API Host', value: 'open-api.tiktokglobalshop.com' },
  { label: 'Version', value: '202309' },
  { label: 'Header Token', value: 'x-tts-access-token' }
]

const flow = [
  {
    number: '01',
    title: 'AUTH',
    description: 'Membuka halaman persetujuan TikTok Shop dan menyimpan code dari callback.',
    endpoint: '/api/omnichannel/auth-tiktok-agnishopbjm'
  },
  {
    number: '02',
    title: 'GET TOKEN',
    description: 'Menukar authorization code menjadi access token dan refresh token.',
    endpoint: '/api/omnichannel/get-token-tiktok-agnishopbjm'
  },
  {
    number: '03',
    title: 'REFRESH TOKEN',
    description: 'Memperpanjang token TikTok aktif memakai refresh token terakhir.',
    endpoint: '/api/omnichannel/refresh-token-tiktok-agnishopbjm'
  },
  {
    number: '04',
    title: 'GET AUTH SHOP',
    description: 'Mengambil daftar shop yang sudah memberi akses dan menyimpan cipher shop.',
    endpoint: '/api/omnichannel/get-auth-shop-tiktok-agnishopbjm'
  }
]

const modules = [
  {
    name: 'Authorization',
    items: [
      { name: 'OAuth Authorize', url: 'https://auth.tiktok-shops.com/openapi/v2/oauth/authorize' },
      { name: 'Get Token', url: 'https://auth.tiktok-shops.com/api/v2/token/get' },
      { name: 'Refresh Token', url: 'https://auth.tiktok-shops.com/api/v2/token/refresh' },
      { name: 'Get Authorized Shop', url: 'https://www.postman.com/tiktok-shop-open/tiktok-shop-public-workspace/request/58kmvlb/get-authorized-shop' }
    ]
  },
  {
    name: 'Product',
    items: [
      { name: 'Get Product Detail', url: 'https://open-api.tiktokglobalshop.com/product/202309/products/{product_id}' },
      { name: 'Update Inventory', url: 'https://open-api.tiktokglobalshop.com/product/202309/products/{product_id}/inventory/update' }
    ]
  }
]

const apis = [
  {
    name: 'OAuth Authorize',
    description: 'Redirect seller ke halaman persetujuan aplikasi TikTok Shop.',
    method: 'GET',
    url: 'https://auth.tiktok-shops.com/openapi/v2/oauth/authorize',
    button: 'AUTH',
    params: [
      row('app_key', 'string', 'Yes', 'App key dari TikTok Shop Open Platform.'),
      row('state', 'string', 'No', 'Di aplikasi ini berisi account key: tiktok-agnishopbjm.'),
      row('redirect_uri', 'string', 'Yes', 'Callback Laravel: /api/tiktok/callback.')
    ]
  },
  {
    name: 'Get Token',
    description: 'Menukar auth_code dari callback menjadi token.',
    method: 'GET',
    url: 'https://auth.tiktok-shops.com/api/v2/token/get',
    button: 'GET TOKEN',
    params: [
      row('app_key', 'string', 'Yes', 'App key.'),
      row('app_secret', 'string', 'Yes', 'App secret.'),
      row('auth_code', 'string', 'Yes', 'Code dari tiktok_callbacks.'),
      row('grant_type', 'string', 'Yes', 'authorized_code.')
    ]
  },
  {
    name: 'Refresh Token',
    description: 'Membuat access token baru dari refresh token aktif.',
    method: 'GET',
    url: 'https://auth.tiktok-shops.com/api/v2/token/refresh',
    button: 'REFRESH TOKEN',
    params: [
      row('app_key', 'string', 'Yes', 'App key.'),
      row('app_secret', 'string', 'Yes', 'App secret.'),
      row('refresh_token', 'string', 'Yes', 'Refresh token terakhir.'),
      row('grant_type', 'string', 'Yes', 'refresh_token.')
    ]
  },
  {
    name: 'Get Authorized Shop',
    description: 'Mengambil shop, region, seller type, dan cipher yang dibutuhkan endpoint produk.',
    method: 'GET',
    url: 'https://open-api.tiktokglobalshop.com/authorization/202309/shops',
    button: 'GET AUTH SHOP',
    params: [
      row('app_key', 'string', 'Yes', 'App key.'),
      row('timestamp', 'int', 'Yes', 'Unix timestamp.'),
      row('sign', 'string', 'Yes', 'HMAC-SHA256 signature.'),
      row('x-tts-access-token', 'header', 'Yes', 'Access token TikTok aktif.')
    ]
  }
]

const signNotes = `Signature TikTok Shop:
1. Ambil query parameter, kecuali sign dan access_token.
2. Urutkan key secara alfabetis.
3. Gabungkan: path + key1value1 + key2value2.
4. Bungkus dengan app_secret di awal dan akhir.
5. Hitung HMAC-SHA256 memakai app_secret.

Untuk GET Authorized Shop:
path = /authorization/202309/shops
query = app_key + timestamp
header = x-tts-access-token`

const query = ref('')

const filteredModules = computed(() => {
  const keyword = query.value.trim().toLowerCase()
  if (!keyword) return modules

  return modules
    .map((module) => ({
      ...module,
      items: module.items.filter((item) => item.name.toLowerCase().includes(keyword) || module.name.toLowerCase().includes(keyword))
    }))
    .filter((module) => module.items.length)
})

function row(name, type, required, description) {
  return { name, type, required, description }
}

const ParameterTable = defineComponent({
  props: {
    rows: {
      type: Array,
      required: true
    }
  },
  setup(props) {
    return () => h('div', { class: 'table-wrap' }, [
      h('table', [
        h('thead', [
          h('tr', [
            h('th', 'Name'),
            h('th', 'Type'),
            h('th', 'Required'),
            h('th', 'Description')
          ])
        ]),
        h('tbody', props.rows.map((item) => h('tr', { key: item.name }, [
          h('td', { class: 'param-name' }, item.name),
          h('td', item.type),
          h('td', item.required),
          h('td', item.description)
        ])))
      ])
    ])
  }
})
</script>

<style scoped>
.page-shell { margin-left: 240px; padding: 28px; }
.page-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
.page-header p { color: #64748b; margin-bottom: 4px; }
h1 { font-size: 28px; }
.primary { background: #111827; color: #fff; border: 0; border-radius: 6px; padding: 10px 14px; cursor: pointer; text-decoration: none; font-weight: 700; }
.summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
.summary-card, .panel { background: #fff; border: 1px solid #d9e2ec; border-radius: 8px; }
.summary-card { padding: 16px; }
.summary-card span { color: #64748b; display: block; margin-bottom: 6px; }
.summary-card strong { font-size: 17px; overflow-wrap: anywhere; }
.panel { padding: 18px; margin-bottom: 18px; }
.panel-title { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; margin-bottom: 12px; }
.panel-title h2 { font-size: 18px; }
.panel-title p { color: #64748b; font-size: 13px; margin-top: 4px; }
.panel-title.compact { margin-bottom: 10px; }
.flow-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
.flow-card { border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px; }
.flow-card span { color: #0f5fc7; font-weight: 900; font-size: 12px; }
.flow-card strong { display: block; margin: 6px 0; }
.flow-card p { color: #475569; font-size: 13px; margin-bottom: 10px; }
.flow-card code { color: #334155; display: block; font-size: 12px; overflow-wrap: anywhere; }
.docs-layout { display: grid; grid-template-columns: 330px minmax(0, 1fr); gap: 18px; align-items: start; }
.doc-nav { position: sticky; top: 18px; max-height: calc(100vh - 36px); overflow: auto; }
.search-box { display: grid; gap: 6px; color: #64748b; font-size: 13px; margin-bottom: 12px; }
.search-box input { border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; font-size: 14px; }
.module-list { display: grid; gap: 8px; }
details { border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; }
summary { cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 12px; font-weight: 800; }
summary small { color: #64748b; font-weight: 600; }
details a { color: #334155; display: block; padding: 8px 12px; text-decoration: none; border-top: 1px solid #eef2f7; overflow-wrap: anywhere; font-size: 13px; }
details a:hover { background: #f8fafc; color: #111827; }
.endpoint-list { display: grid; gap: 10px; margin: 0 0 14px; }
.endpoint-list div { display: grid; grid-template-columns: 160px minmax(0, 1fr); gap: 12px; }
.endpoint-list dt { color: #64748b; font-weight: 700; }
.endpoint-list dd { margin: 0; overflow-wrap: anywhere; }
.table-wrap { overflow: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th, td { border-bottom: 1px solid #e5e7eb; padding: 10px; text-align: left; vertical-align: top; }
thead th { position: sticky; top: 0; background: #1f2937; color: #fff; }
.param-name { color: #0f5fc7; font-weight: 800; overflow-wrap: anywhere; }
pre { background: #111827; color: #e5e7eb; border-radius: 6px; font-size: 13px; line-height: 1.6; margin: 0; overflow: auto; padding: 14px; white-space: pre-wrap; word-break: break-word; }
@media (max-width: 1100px) { .summary-grid, .flow-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .docs-layout { grid-template-columns: 1fr; } .doc-nav { position: static; max-height: none; } }
@media (max-width: 820px) { .page-shell { margin-left: 0; padding: 18px; } .page-header, .endpoint-list div { grid-template-columns: 1fr; display: grid; } .summary-grid, .flow-grid { grid-template-columns: 1fr; } }
</style>
