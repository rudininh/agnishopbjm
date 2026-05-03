<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Shopee Open Platform</p>
        <h1>Dokumentasi Shopee</h1>
      </div>
      <div class="header-actions">
        <span :class="['sync-status', docsStatus]">{{ docsStatusText }}</span>
        <a class="primary" :href="sourceUrl" target="_blank" rel="noreferrer">Buka Sumber</a>
      </div>
    </header>

    <div class="summary-grid">
      <article class="summary-card">
        <span>Module</span>
        <strong>{{ api.moduleName }}</strong>
      </article>
      <article class="summary-card">
        <span>API Type</span>
        <strong>{{ api.apiType }}</strong>
      </article>
      <article class="summary-card">
        <span>Permission</span>
        <strong>{{ api.permission }}</strong>
      </article>
      <article class="summary-card">
        <span>Update</span>
        <strong>{{ api.updateLog }}</strong>
      </article>
    </div>

    <section class="panel">
      <div class="panel-title">
        <div>
          <h2>{{ api.name }}</h2>
          <p>{{ api.define }}</p>
        </div>
      </div>

      <dl class="endpoint-list">
        <div>
          <dt>Production URL</dt>
          <dd>{{ api.url }}</dd>
        </div>
        <div>
          <dt>Test URL</dt>
          <dd>{{ api.testUrl }}</dd>
        </div>
        <div>
          <dt>Path</dt>
          <dd>{{ api.path }}</dd>
        </div>
      </dl>
    </section>

    <section class="docs-layout">
      <aside class="doc-nav panel">
        <div class="panel-title compact">
          <h2>Sub Link Dokumentasi</h2>
        </div>

        <label class="search-box">
          <span>Cari API</span>
          <input v-model="query" type="search" placeholder="product, order, ams..." />
        </label>

        <div class="module-list">
          <details v-for="module in filteredModules" :key="module.moduleId" :open="module.moduleName === 'AMS'">
            <summary>
              <span>{{ module.moduleName }}</span>
          <small>{{ module.items.length }} link</small>
            </summary>
            <a
              v-for="item in module.items"
              :key="item.id"
            :href="docLink(module.moduleId, item.name, item.type)"
              target="_blank"
              rel="noreferrer"
            >
              {{ item.name }}
            </a>
          </details>
        </div>
      </aside>

      <div class="doc-content">
        <section class="panel">
          <div class="panel-title compact">
            <h2>Common Parameters</h2>
          </div>
          <ParameterTable :rows="commonParams" />
        </section>

        <section class="panel">
          <div class="panel-title compact">
            <h2>Request Parameters</h2>
          </div>
          <ParameterTable :rows="requestParams" />
        </section>

        <section class="panel">
          <div class="panel-title compact">
            <h2>Response Parameters</h2>
          </div>
          <ParameterTable :rows="responseRows" />
        </section>

        <section class="panel">
          <div class="panel-title compact">
            <h2>Error List</h2>
          </div>
          <div class="error-grid">
            <article v-for="(error, index) in errors" :key="`${error.name}-${index}`">
              <strong>{{ error.name }}</strong>
              <p>{{ error.description }}</p>
            </article>
          </div>
        </section>

        <section class="panel">
          <div class="panel-title compact">
            <h2>Sample cURL</h2>
          </div>
          <pre><code>{{ curlSample }}</code></pre>
        </section>
      </div>
    </section>
  </section>
</template>

<script setup>
import { computed, defineComponent, h, onMounted, ref } from 'vue'
import { shopeeDocsService } from '@/services'

const sourceUrl = 'https://open.shopee.com/documents/v2/v2.ams.get_open_campaign_added_product?module=127&type=1'

const api = {
  name: 'v2.ams.get_open_campaign_added_product',
  moduleName: 'AMS',
  apiType: 'Shop',
  permission: 'Affiliate Marketing Solution Management',
  updateLog: '2025-10-15 - New API',
  define: 'Retrieve all products currently in the Open Campaign, including campaign status, commission rate, and promotion period.',
  url: 'https://partner.shopeemobile.com/api/v2/ams/get_open_campaign_added_product',
  testUrl: 'https://partner.test-stable.shopeemobile.com/api/v2/ams/get_open_campaign_added_product',
  path: '/api/v2/ams/get_open_campaign_added_product'
}

const commonParams = [
  { name: 'partner_id', type: 'int', required: 'Yes', sample: '1', description: 'Partner ID assigned after successful registration.' },
  { name: 'timestamp', type: 'timestamp', required: 'Yes', sample: '1610000000', description: 'Request timestamp. Expires in 5 minutes.' },
  { name: 'access_token', type: 'string', required: 'Yes', sample: 'c09222e3fc40ffb25fc947f738b1abf1', description: 'Token used to identify API permission. Valid for multiple uses and expires in 4 hours.' },
  { name: 'shop_id', type: 'int', required: 'Yes', sample: '600000', description: 'Shopee unique identifier for a shop.' },
  { name: 'sign', type: 'string', required: 'Yes', sample: 'e318d3e...', description: 'HMAC-SHA256 signature from partner_id, path, timestamp, access_token, shop_id, and partner_key.' }
]

const requestParams = [
  { name: 'page_size', type: 'int32', required: 'Yes', sample: '20', description: 'Maximum entries per page. Valid range: 1 to 100.' },
  { name: 'cursor', type: 'string', required: 'No', sample: '1234,5678', description: 'Starting entry for current page. Use next cursor from the previous response.' },
  { name: 'sort_by', type: 'string', required: 'No', sample: 'commission_rate', description: 'Sort by commission_rate ascending, or -commission_rate descending. Default is update_time and commission_id descending.' },
  { name: 'search_type', type: 'string', required: 'No', sample: 'ITEM_NAME', description: 'Search type: ITEM_NAME or ITEM_ID.' },
  { name: 'search_content', type: 'string', required: 'No', sample: 'test', description: 'Search item_name or item_id. Multiple item_id values are comma-separated, max 50 items.' }
]

const responseRows = [
  { name: 'error', type: 'string', sample: 'error_business', description: 'Error type. Empty if no error happened.' },
  { name: 'message', type: 'string', sample: 'Please agree to the AMS T&C...', description: 'Error details. Empty if no error happened.' },
  { name: 'request_id', type: 'string', sample: 'b937c04e...', description: 'API request identifier for error tracking.' },
  { name: 'response.item_list[].item_id', type: 'int64', sample: '123', description: 'Item ID.' },
  { name: 'response.item_list[].item_name', type: 'string', sample: 'test', description: 'Item name.' },
  { name: 'response.item_list[].campaign_id', type: 'int64', sample: '123', description: 'Campaign ID.' },
  { name: 'response.item_list[].campaign_status', type: 'string', sample: 'Ongoing', description: 'Campaign status: Upcoming, Ongoing, or Terminating.' },
  { name: 'response.item_list[].commission_rate', type: 'float', sample: '1.11', description: 'Commission rate. 1.1 means 1.1%, supports two decimals.' },
  { name: 'response.item_list[].period_start_time', type: 'timestamp', sample: '1735660800', description: 'Period start time.' },
  { name: 'response.item_list[].period_end_time', type: 'timestamp', sample: '1735660800', description: 'Period end time. 32503651199 means no limit.' },
  { name: 'response.item_list[].pending_terminated_time', type: 'timestamp', sample: '1735660800', description: 'Pending terminated time.' },
  { name: 'response.item_list[].commission_protection_list[].commission_rate', type: 'float', sample: '1.21', description: 'Protected commission rate.' },
  { name: 'response.item_list[].commission_protection_list[].protection_period_end_time', type: 'timestamp', sample: '1735660800', description: 'Protection period end time.' },
  { name: 'response.item_list[].max_commission_rate_current_day', type: 'float', sample: '1.21', description: 'Max commission rate current day.' },
  { name: 'response.total_count', type: 'int32', sample: '1000', description: 'Total items matching the condition.' },
  { name: 'response.cursor', type: 'string', sample: '1234,5678', description: 'Pass this cursor in the next request to get the next page.' },
  { name: 'response.has_more', type: 'boolean', sample: 'true', description: 'Whether more pages are available.' }
]

const errors = [
  { name: 'error_auth', description: 'Invalid partner_id or shopid.' },
  { name: 'error_server', description: 'Something wrong. Please try later.' },
  { name: 'error_param', description: 'Invalid cursor, item_id_list, limit, page_size, search_content, search_type, sort_by, or other parameter.' },
  { name: 'error_business', description: "Seller does not have AMS access, has not agreed to AMS T&C, or commission operation is frozen." }
]

const curlSample = `curl --location --request GET 'https://partner.shopeemobile.com/api/v2/ams/get_open_campaign_added_product?access_token=access_token&cursor=1234%2C5678&page_size=20&partner_id=partner_id&search_content=test&search_type=ITEM_NAME&shop_id=shop_id&sign=sign&sort_by=commission_rate&timestamp=timestamp'`

const fallbackModules = [
  moduleItem(127, 'AMS', [
    'v2.ams.get_open_campaign_added_product',
    'v2.ams.get_open_campaign_not_added_product',
    'v2.ams.batch_add_products_to_open_campaign',
    'v2.ams.add_all_products_to_open_campaign',
    'v2.ams.get_auto_add_new_product_toggle_status',
    'v2.ams.update_auto_add_new_product_setting',
    'v2.ams.batch_edit_products_open_campaign_setting',
    'v2.ams.edit_all_products_open_campaign_setting',
    'v2.ams.batch_remove_products_open_campaign_setting',
    'v2.ams.remove_all_products_open_campaign_setting',
    'v2.ams.get_open_campaign_batch_task_result',
    'v2.ams.get_optimization_suggestion_product',
    'v2.ams.batch_get_products_suggested_rate',
    'v2.ams.get_shop_suggested_rate',
    'v2.ams.get_targeted_campaign_addable_product_list',
    'v2.ams.get_recommended_affiliate_list',
    'v2.ams.get_managed_affiliate_list',
    'v2.ams.query_affiliate_list',
    'v2.ams.create_new_targeted_campaign',
    'v2.ams.get_targeted_campaign_list',
    'v2.ams.get_targeted_campaign_settings',
    'v2.ams.update_basic_info_of_targeted_campaign',
    'v2.ams.edit_product_list_of_targeted_campaign',
    'v2.ams.edit_affiliate_list_of_targeted_campaign',
    'v2.ams.terminate_targeted_campaign',
    'v2.ams.get_performance_data_update_time',
    'v2.ams.get_shop_performance',
    'v2.ams.get_product_performance',
    'v2.ams.get_affiliate_performance',
    'v2.ams.get_content_performance',
    'v2.ams.get_campaign_key_metrics_performance',
    'v2.ams.get_open_campaign_performance',
    'v2.ams.get_targeted_campaign_performance',
    'v2.ams.get_conversion_report',
    'v2.ams.get_validation_list',
    'v2.ams.get_validation_report'
  ], 2666),
  moduleItem(89, 'Product', ['v2.product.get_category', 'v2.product.get_item_list', 'v2.product.get_item_base_info', 'v2.product.add_item', 'v2.product.update_item', 'v2.product.update_stock', 'v2.product.search_item']),
  moduleItem(94, 'Order', ['v2.order.get_order_list', 'v2.order.get_order_detail', 'v2.order.get_shipment_list', 'v2.order.cancel_order', 'v2.order.set_note']),
  moduleItem(95, 'Logistics', ['v2.logistics.get_shipping_parameter', 'v2.logistics.ship_order', 'v2.logistics.get_tracking_number', 'v2.logistics.create_shipping_document', 'v2.logistics.download_shipping_document']),
  moduleItem(104, 'Public', ['v2.public.get_shops_by_partner', 'v2.public.get_merchants_by_partner', 'v2.public.get_access_token', 'v2.public.refresh_access_token', 'v2.public.get_shopee_ip_ranges']),
  moduleItem(92, 'Shop', ['v2.shop.get_shop_info', 'v2.shop.get_profile', 'v2.shop.update_profile', 'v2.shop.get_shop_notification']),
  moduleItem(93, 'Merchant', ['v2.merchant.get_merchant_info', 'v2.merchant.get_shop_list_by_merchant', 'v2.merchant.get_merchant_warehouse_list']),
  moduleItem(97, 'Payment', ['v2.payment.get_escrow_detail', 'v2.payment.get_payout_detail', 'v2.payment.get_payment_method_list', 'v2.payment.get_wallet_transaction_list']),
  moduleItem(99, 'Discount', ['v2.discount.add_discount', 'v2.discount.get_discount', 'v2.discount.get_discount_list', 'v2.discount.update_discount', 'v2.discount.end_discount']),
  moduleItem(102, 'Returns', ['v2.returns.get_return_list', 'v2.returns.get_return_detail', 'v2.returns.confirm', 'v2.returns.dispute']),
  moduleItem(105, 'Push', ['v2.push.set_app_push_config', 'v2.push.get_app_push_config', 'v2.push.get_lost_push_message'])
]

const query = ref('')
const docsStatus = ref('loading')
const modules = ref(fallbackModules)

const filteredModules = computed(() => {
  const keyword = query.value.trim().toLowerCase()
  if (!keyword) return modules.value

  return modules.value
    .map((module) => ({
      ...module,
      items: module.items.filter((item) => item.name.toLowerCase().includes(keyword) || module.moduleName.toLowerCase().includes(keyword))
    }))
    .filter((module) => module.items.length)
})

const docsStatusText = computed(() => {
  if (docsStatus.value === 'live') return 'Data live Shopee'
  if (docsStatus.value === 'fallback') return 'Fallback lokal'
  return 'Memuat data'
})

function moduleItem(moduleId, moduleName, names, startId = 600) {
  return {
    moduleId,
    moduleName,
    items: names.map((name, index) => ({ id: startId + index, name, type: 1 }))
  }
}

function docLink(moduleId, name, type) {
  return `https://open.shopee.com/documents/v2/${encodeURIComponent(name)}?module=${moduleId}&type=${type}`
}

function normalizeModules(rawModules) {
  return (rawModules || [])
    .filter((module) => module.type === 1 && Array.isArray(module.items))
    .map((module) => ({
      moduleId: module.module_id,
      moduleName: module.module_name,
      items: module.items
        .filter((item) => item.status === 1)
        .map((item) => ({
          id: item.id,
          name: item.name.trim(),
          type: item.type
        }))
    }))
    .filter((module) => module.items.length)
}

onMounted(async () => {
  try {
    const response = await shopeeDocsService.modules()
    const liveModules = normalizeModules(response.data.modules)
    if (liveModules.length) {
      modules.value = liveModules
      docsStatus.value = 'live'
      return
    }
    docsStatus.value = 'fallback'
  } catch (error) {
    docsStatus.value = 'fallback'
  }
})

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
            h('th', 'Sample'),
            h('th', 'Description')
          ])
        ]),
        h('tbody', props.rows.map((row) => h('tr', { key: row.name }, [
          h('td', { class: 'param-name' }, row.name),
          h('td', row.type || '-'),
          h('td', row.required || '-'),
          h('td', row.sample || '-'),
          h('td', row.description || '-')
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
.header-actions { display: flex; align-items: center; gap: 10px; }
.sync-status { border: 1px solid #cbd5e1; border-radius: 999px; color: #64748b; font-size: 12px; font-weight: 800; padding: 7px 10px; white-space: nowrap; }
.sync-status.live { background: #ecfdf5; border-color: #86efac; color: #166534; }
.sync-status.fallback { background: #fff7ed; border-color: #fed7aa; color: #9a3412; }
.primary { background: #f97316; color: #fff; border: 0; border-radius: 6px; padding: 10px 14px; cursor: pointer; text-decoration: none; font-weight: 700; }
.summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
.summary-card, .panel { background: #fff; border: 1px solid #d9e2ec; border-radius: 8px; }
.summary-card { padding: 16px; }
.summary-card span { color: #64748b; display: block; margin-bottom: 6px; }
.summary-card strong { font-size: 17px; }
.panel { padding: 18px; margin-bottom: 18px; }
.panel-title { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; margin-bottom: 12px; }
.panel-title h2 { font-size: 18px; }
.panel-title p { color: #64748b; font-size: 13px; margin-top: 4px; }
.panel-title.compact { margin-bottom: 10px; }
.endpoint-list { display: grid; gap: 10px; margin: 0; }
.endpoint-list div { display: grid; grid-template-columns: 160px minmax(0, 1fr); gap: 12px; }
.endpoint-list dt { color: #64748b; font-weight: 700; }
.endpoint-list dd { margin: 0; overflow-wrap: anywhere; }
.docs-layout { display: grid; grid-template-columns: 330px minmax(0, 1fr); gap: 18px; align-items: start; }
.doc-nav { position: sticky; top: 18px; max-height: calc(100vh - 36px); overflow: auto; }
.search-box { display: grid; gap: 6px; color: #64748b; font-size: 13px; margin-bottom: 12px; }
.search-box input { border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; font-size: 14px; }
.module-list { display: grid; gap: 8px; }
details { border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; }
summary { cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 12px; font-weight: 800; }
summary small { color: #64748b; font-weight: 600; }
details a { color: #334155; display: block; padding: 8px 12px; text-decoration: none; border-top: 1px solid #eef2f7; overflow-wrap: anywhere; font-size: 13px; }
details a:hover { background: #fff7ed; color: #c2410c; }
.table-wrap { overflow: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th, td { border-bottom: 1px solid #e5e7eb; padding: 10px; text-align: left; vertical-align: top; }
thead th { position: sticky; top: 0; background: #1f2937; color: #fff; }
.param-name { color: #0f5fc7; font-weight: 800; overflow-wrap: anywhere; }
.error-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.error-grid article { background: #fff7ed; border: 1px solid #fed7aa; border-radius: 6px; padding: 12px; }
.error-grid p { color: #475569; margin-top: 4px; }
pre { background: #111827; color: #e5e7eb; border-radius: 6px; font-size: 13px; line-height: 1.6; margin: 0; overflow: auto; padding: 14px; white-space: pre-wrap; word-break: break-word; }
@media (max-width: 1100px) { .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .docs-layout { grid-template-columns: 1fr; } .doc-nav { position: static; max-height: none; } }
@media (max-width: 820px) { .page-shell { margin-left: 0; padding: 18px; } .page-header, .header-actions, .endpoint-list div { grid-template-columns: 1fr; display: grid; } .summary-grid, .error-grid { grid-template-columns: 1fr; } }
</style>
