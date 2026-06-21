<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Marketplace</p>
        <h1>Sinkronisasi Otomatis</h1>
      </div>
      <div class="header-actions">
        <button class="ghost" type="button" @click="loadAll" :disabled="loading">{{ loading ? 'Memuat...' : 'Refresh' }}</button>
        <button class="primary" type="button" @click="instantCheckOrders" :disabled="runningInstantCheck">
          {{ runningInstantCheck ? 'Instant check...' : 'Instant Check 1 Jam' }}
        </button>
        <button class="primary" type="button" @click="retryOpenIssues" :disabled="runningRetryOpenIssues">
          {{ runningRetryOpenIssues ? 'Retry issue...' : 'Retry Open Issues' }}
        </button>
        <button class="primary" type="button" @click="pollShopeeOrders" :disabled="runningOrderPoll">
          {{ runningOrderPoll ? 'Cek pesanan...' : 'Cek Pesanan Shopee Sekarang' }}
        </button>
        <button class="primary" type="button" @click="pollTiktokOrders" :disabled="runningTiktokOrderPoll">
          {{ runningTiktokOrderPoll ? 'Cek TikTok...' : 'Cek Pesanan TikTok Sekarang' }}
        </button>
        <button :class="browserAutoSyncEnabled ? 'danger' : 'primary'" type="button" @click="toggleBrowserAutoSync" :disabled="isBrowserAutoSyncLocked">
          {{ browserAutoSyncEnabled ? 'Matikan Auto Browser' : 'Aktifkan Auto Browser' }}
        </button>
        <button class="danger" type="button" @click="syncShopeeToTiktok" :disabled="runningShopeeToTiktok">
          {{ runningShopeeToTiktok ? 'Sinkron real...' : 'Sync Real Shopee -> TikTok' }}
        </button>
        <button class="primary" type="button" @click="runSafetyCheck" :disabled="runningSafety">
          {{ runningSafety ? 'Menjalankan...' : 'Run Safety Check Now' }}
        </button>
        <button class="ghost" type="button" @click="previewBulkUpdateEmptySkus" :disabled="runningBulkSkuPreview">
          {{ runningBulkSkuPreview ? 'Preview SKU...' : 'Preview Bulk SKU' }}
        </button>
        <button class="ghost" type="button" @click="bulkUpdateEmptySkus" :disabled="runningBulkSkuUpdate">
          {{ runningBulkSkuUpdate ? 'Update SKU...' : 'Bulk Update SKU Kosong' }}
        </button>
      </div>
    </header>

    <p v-if="notice" :class="['notice', noticeType]">{{ notice }}</p>

    <section v-if="dashboard.alerts?.length" class="alert-stack">
      <article v-for="alert in dashboard.alerts" :key="`${alert.type}-${alert.created_at || alert.title}`" :class="['alert-card', alert.severity === 'error' ? 'error' : 'warning']">
        <div>
          <strong>{{ alert.title }}</strong>
          <span>{{ formatDate(alert.created_at) }}</span>
        </div>
        <p>{{ alert.message }}</p>
      </article>
    </section>

    <section class="browser-auto-strip">
      <div>
        <span>Auto Browser</span>
        <strong :class="['badge', browserAutoSyncEnabled ? 'success' : 'neutral']">{{ isBrowserAutoSyncLocked ? 'Locked' : (browserAutoSyncEnabled ? 'Active' : 'Off') }}</strong>
      </div>
      <div><span>Running</span><strong>{{ browserAutoSyncRunning ? 'Sedang cek order' : '-' }}</strong></div>
      <div><span>Last run</span><strong>{{ formatDate(browserAutoSyncLastRun) }}</strong></div>
      <div><span>Countdown</span><strong>{{ browserAutoSyncCountdownLabel }}</strong></div>
      <div><span>Next run</span><strong>{{ browserAutoSyncNextRunLabel }}</strong></div>
    </section>

    <section class="stb-worker-strip">
      <article>
        <span>STB Worker</span>
        <strong :class="['badge', stbStatus.worker_online ? 'success' : 'error']">{{ stbStatus.worker_online ? 'Online' : 'Offline' }}</strong>
      </article>
      <article>
        <span>Last STB Heartbeat</span>
        <strong>{{ formatDate(stbStatus.last_heartbeat_at) }}</strong>
      </article>
      <article>
        <span>Last Order Sync by STB</span>
        <strong>{{ formatDate(stbStatus.last_order_sync_at) }}</strong>
      </article>
      <article>
        <span>Queue Worker Status</span>
        <strong :class="['badge', queueStatusClass]">{{ queueStatusLabel }}</strong>
      </article>
      <article>
        <span>Scheduler Status</span>
        <strong :class="['badge', schedulerStatusClass]">{{ schedulerStatusLabel }}</strong>
      </article>
    </section>

    <section class="runtime-strip">
      <article>
        <span>Owner aktif</span>
        <strong :class="['badge', runtimeOwnerClass]">{{ runtimeOwnerLabel }}</strong>
      </article>
      <article>
        <span>Local heartbeat</span>
        <strong :class="['badge', runtimeStatus.local_is_active ? 'success' : 'error']">{{ runtimeStatus.local_is_active ? 'Aktif' : 'Timeout' }}</strong>
      </article>
      <article>
        <span>Last seen local</span>
        <strong>{{ formatDate(runtimeStatus.local_last_seen_at) }}</strong>
      </article>
      <article>
        <span>Online backup</span>
        <strong :class="['badge', runtimeStatus.online_backup_enabled ? 'success' : 'neutral']">{{ runtimeStatus.online_backup_enabled ? 'Standby' : 'Off' }}</strong>
      </article>
      <article>
        <span>Real mode</span>
        <strong :class="['badge', runtimeStatus.online_backup_real_enabled ? 'error' : 'neutral']">{{ runtimeStatus.online_backup_real_enabled ? 'ON' : 'OFF' }}</strong>
      </article>
      <article>
        <span>Catatan</span>
        <strong>{{ runtimeStatus.last_decision_reason || '-' }}</strong>
      </article>
      <article class="runtime-actions">
        <span>Pengaturan runtime</span>
        <div>
          <button class="ghost" type="button" @click="toggleOnlineBackup" :disabled="updatingRuntime">
            {{ runtimeStatus.online_backup_enabled ? 'Matikan Backup' : 'Aktifkan Backup' }}
          </button>
          <button class="ghost" type="button" @click="checkOnlineBackupDecision" :disabled="updatingRuntime">Cek Owner</button>
          <button class="ghost" type="button" @click="runBackupDryRun" :disabled="updatingRuntime">Dry Run Backup</button>
          <button class="ghost" type="button" @click="runSchedulerTick" :disabled="updatingRuntime">Scheduler Tick</button>
          <button :class="runtimeStatus.online_backup_real_enabled ? 'danger' : 'ghost'" type="button" @click="toggleRealMode" :disabled="updatingRuntime">
            {{ runtimeStatus.online_backup_real_enabled ? 'Matikan Real' : 'Aktifkan Real' }}
          </button>
          <button class="danger" type="button" @click="runRealBackup" :disabled="updatingRuntime || !runtimeStatus.online_backup_real_enabled">
            Run Real Backup
          </button>
          <button :class="runtimeStatus.active_owner === 'paused' ? 'primary' : 'danger'" type="button" @click="toggleRuntimePause" :disabled="updatingRuntime">
            {{ runtimeStatus.active_owner === 'paused' ? 'Resume Local' : 'Pause Local' }}
          </button>
        </div>
      </article>
    </section>

    <section class="bridge-strip">
      <article>
        <span>Vercel bridge</span>
        <strong :class="['badge', bridgeStatusClass]">{{ bridgeStatusLabel }}</strong>
      </article>
      <article>
        <span>HTTP status</span>
        <strong>{{ bridgeStatus.http_status || '-' }}</strong>
      </article>
      <article>
        <span>Bridge URL</span>
        <strong>{{ bridgeStatus.url || '-' }}</strong>
      </article>
      <article>
        <span>Respons</span>
        <strong>{{ bridgeMessage }}</strong>
      </article>
      <article class="bridge-actions">
        <button class="ghost" type="button" @click="loadBridgeStatus" :disabled="loadingBridgeStatus">
          {{ loadingBridgeStatus ? 'Cek...' : 'Cek Bridge' }}
        </button>
      </article>
    </section>

    <div class="status-grid">
      <article class="status-card">
        <div class="card-head">
          <span>Status Shopee</span>
          <strong :class="['badge', marketplaceStatus('shopee').connected ? 'success' : 'error']">{{ marketplaceStatus('shopee').connected ? 'Connected' : 'Disconnected' }}</strong>
        </div>
        <dl>
          <div><dt>Last webhook received</dt><dd>{{ formatDate(marketplaceStatus('shopee').last_webhook_at) }}</dd></div>
          <div><dt>Last stock update</dt><dd>{{ formatDate(marketplaceStatus('shopee').last_sync_at) }}</dd></div>
          <div><dt>Total webhook today</dt><dd>{{ marketplaceStatus('shopee').total_webhook_today || 0 }}</dd></div>
        </dl>
      </article>

      <article class="status-card">
        <div class="card-head">
          <span>Status TikTok</span>
          <strong :class="['badge', marketplaceStatus('tiktok').connected ? 'success' : 'error']">{{ marketplaceStatus('tiktok').connected ? 'Connected' : 'Disconnected' }}</strong>
        </div>
        <dl>
          <div><dt>Last webhook received</dt><dd>{{ formatDate(marketplaceStatus('tiktok').last_webhook_at) }}</dd></div>
          <div><dt>Last stock update</dt><dd>{{ formatDate(marketplaceStatus('tiktok').last_sync_at) }}</dd></div>
          <div><dt>Total webhook today</dt><dd>{{ marketplaceStatus('tiktok').total_webhook_today || 0 }}</dd></div>
        </dl>
      </article>

      <article class="status-card">
        <div class="card-head">
          <span>Auto Sync Engine</span>
          <strong :class="['badge', dashboard.engine?.status === 'active' ? 'success' : 'error']">{{ dashboard.engine?.status || 'inactive' }}</strong>
        </div>
        <dl>
          <div><dt>Realtime Sync</dt><dd>{{ dashboard.engine?.realtime_sync ? 'Active' : 'Inactive' }}</dd></div>
          <div><dt>Live Push</dt><dd>{{ dashboard.engine?.live_push ? 'Active' : 'Dry Run' }}</dd></div>
          <div><dt>Safety Check</dt><dd>{{ dashboard.engine?.safety_check ? dashboard.engine?.cron_interval : 'Inactive' }}</dd></div>
          <div><dt>Failure Notify</dt><dd>{{ failureNotificationLabel }}</dd></div>
          <div><dt>Interval cron</dt><dd>{{ dashboard.engine?.cron_interval || '-' }}</dd></div>
        </dl>
      </article>

      <article class="status-card">
        <div class="card-head">
          <span>Order Sync Backup</span>
          <strong :class="['badge', dashboard.order_sync?.status === 'active' ? 'success' : 'error']">{{ dashboard.order_sync?.status || 'inactive' }}</strong>
        </div>
        <dl>
          <div><dt>Polling Order</dt><dd>{{ dashboard.order_sync?.polling_interval || '-' }}</dd></div>
          <div><dt>Last order sync</dt><dd>{{ formatDate(dashboard.order_sync?.last_order_sync_at) }}</dd></div>
          <div><dt>Shopee orders today</dt><dd>{{ dashboard.order_sync?.shopee_orders_processed_today || 0 }}</dd></div>
          <div><dt>TikTok orders today</dt><dd>{{ dashboard.order_sync?.tiktok_orders_processed_today || 0 }}</dd></div>
          <div><dt>TikTok -> Shopee today</dt><dd>{{ dashboard.order_sync?.tiktok_to_shopee_pushes_today || 0 }}</dd></div>
          <div><dt>Open issues</dt><dd>{{ dashboard.order_sync?.open_issues || 0 }}</dd></div>
        </dl>
      </article>
    </div>

    <section class="webhook-strip">
      <div>
        <span>Webhook Shopee</span>
        <code>{{ dashboard.webhook_urls?.shopee || '-' }}</code>
      </div>
      <div>
        <span>Webhook TikTok</span>
        <code>{{ dashboard.webhook_urls?.tiktok || '-' }}</code>
      </div>
    </section>

    <div class="tabs">
      <button :class="{ active: activeTab === 'webhook' }" @click="activeTab = 'webhook'">Webhook Monitor</button>
      <button :class="{ active: activeTab === 'order' }" @click="setOrderTab('')">Order Sync</button>
      <button :class="{ active: activeTab === 'orderCancel' }" @click="setOrderTab('cancel')">Order Cancel</button>
      <button :class="{ active: activeTab === 'sync' }" @click="activeTab = 'sync'">Sync Log</button>
      <button :class="{ active: activeTab === 'anomaly' }" @click="activeTab = 'anomaly'">Anomali Stok</button>
      <button :class="{ active: activeTab === 'skuHistory' }" @click="activeTab = 'skuHistory'">History SKU</button>
      <button :class="{ active: activeTab === 'watchdog' }" @click="activeTab = 'watchdog'">Watchdog</button>
      <button :class="{ active: activeTab === 'report' }" @click="activeTab = 'report'">Report</button>
      <button :class="{ active: activeTab === 'queue' }" @click="activeTab = 'queue'">Queue</button>
      <button :class="{ active: activeTab === 'runtime' }" @click="activeTab = 'runtime'">Runtime</button>
      <button :class="{ active: activeTab === 'safety' }" @click="activeTab = 'safety'">Cron Safety Check</button>
    </div>

    <section v-if="activeTab === 'webhook'" class="panel">
      <div class="filter-row">
        <select v-model="webhookFilters.marketplace" @change="loadWebhookLogs(1)">
          <option value="">Semua marketplace</option>
          <option value="shopee">Shopee</option>
          <option value="tiktok">TikTok</option>
        </select>
        <select v-model="webhookFilters.status" @change="loadWebhookLogs(1)">
          <option value="">Semua status</option>
          <option value="success">Success</option>
          <option value="error">Error</option>
          <option value="pending">Pending</option>
        </select>
        <input v-model="webhookFilters.date" type="date" @change="loadWebhookLogs(1)" />
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Time</th><th>Marketplace</th><th>Event</th><th>SKU</th><th>Qty</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="row in webhookLogs" :key="row.id">
              <td>{{ formatDate(row.created_at) }}</td>
              <td>{{ labelMarketplace(row.marketplace) }}</td>
              <td>{{ row.event_type || '-' }}</td>
              <td>{{ row.sku || '-' }}</td>
              <td>{{ row.qty ?? '-' }}</td>
              <td><span :class="['badge', row.status === 'success' ? 'success' : row.status === 'error' ? 'error' : 'neutral']">{{ row.status }}</span></td>
            </tr>
            <tr v-if="!webhookLogs.length"><td colspan="6" class="empty">Belum ada webhook log.</td></tr>
          </tbody>
        </table>
      </div>
      <Pagination :pagination="webhookPagination" @change="loadWebhookLogs" />
    </section>

    <section v-if="activeTab === 'order' || activeTab === 'orderCancel'" class="panel">
      <div class="safety-summary order-summary">
        <div><span>Last order sync</span><strong>{{ formatDate(orderSync.summary?.last_order_sync_at) }}</strong></div>
        <div><span>Shopee orders today</span><strong>{{ orderSync.summary?.shopee_orders_processed_today || 0 }}</strong></div>
        <div><span>TikTok orders today</span><strong>{{ orderSync.summary?.tiktok_orders_processed_today || 0 }}</strong></div>
        <div><span>TikTok -> Shopee today</span><strong>{{ orderSync.summary?.tiktok_to_shopee_pushes_today || 0 }}</strong></div>
        <div><span>Open issues</span><strong>{{ orderSync.summary?.open_issues || 0 }}</strong></div>
      </div>
      <div v-if="orderSync.summary?.latest_open_issue_message" class="issue-banner">
        <div>
          <span>Masalah aktif terakhir</span>
          <strong>{{ formatDate(orderSync.summary?.latest_open_issue_at) }} - {{ orderSync.summary?.latest_open_issue_status || '-' }}</strong>
        </div>
        <p>{{ orderSync.summary.latest_open_issue_message }}</p>
      </div>
      <div class="filter-row">
        <div class="segmented">
          <button type="button" :class="{ active: orderFilters.order_class === '' }" @click="setOrderClass('')">Semua</button>
          <button type="button" :class="{ active: orderFilters.order_class === 'instant' }" @click="setOrderClass('instant')">Order Instant</button>
          <button type="button" :class="{ active: orderFilters.order_class === 'cancel' }" @click="setOrderClass('cancel')">Order Cancel</button>
        </div>
        <select v-model="orderFilters.type" @change="loadOrderSync(1)">
          <option value="">Semua jenis</option>
          <option value="shopee_order">Shopee Order</option>
          <option value="shopee_stock_refresh">Shopee Stock Refresh</option>
          <option value="tiktok_order">TikTok Order</option>
        </select>
        <select v-model="orderFilters.status" @change="loadOrderSync(1)">
          <option value="">Semua status</option>
          <option value="success">Success</option>
          <option value="skipped">Skipped / Dilewati</option>
          <option value="error">Error</option>
        </select>
        <input v-model="orderFilters.date" type="date" @change="loadOrderSync(1)" />
        <input v-model.trim="orderFilters.search" type="search" placeholder="Cari order / SKU / message" @keyup.enter="loadOrderSync(1)" />
      </div>
      <div class="table-actions">
        <button class="ghost" type="button" @click="exportOrderSync" :disabled="exportingOrderSync">
          {{ exportingOrderSync ? 'Mengekspor...' : 'Export Order Sync CSV' }}
        </button>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Time</th><th>Jenis</th><th>Target</th><th>Order/SKU</th><th>Old Stock</th><th>New Stock</th><th>Status</th><th>Message</th></tr></thead>
          <tbody>
            <tr v-for="row in orderSync.items" :key="row.id" class="clickable-row" @click="openOrderSyncDetail(row.id)">
              <td>{{ formatDate(row.created_at) }}</td>
              <td>{{ labelMarketplace(row.source_marketplace) }}</td>
              <td>{{ labelMarketplace(row.target_marketplace) }}</td>
              <td>{{ row.sku || '-' }}</td>
              <td>{{ row.old_stock ?? '-' }}</td>
              <td>{{ row.new_stock ?? '-' }}</td>
              <td><span :class="['badge', row.status === 'success' ? 'success' : row.status === 'error' ? 'error' : 'neutral']">{{ row.status }}</span></td>
              <td>{{ row.message || '-' }}</td>
            </tr>
            <tr v-if="!orderSync.items.length"><td colspan="8" class="empty">Belum ada histori order sync.</td></tr>
          </tbody>
        </table>
      </div>
      <Pagination :pagination="orderSync.pagination" @change="loadOrderSync" />
    </section>

    <section v-if="activeTab === 'sync'" class="panel">
      <div class="filter-row">
        <select v-model="syncFilters.marketplace" @change="loadSyncLogs(1)">
          <option value="">Semua marketplace</option>
          <option value="shopee">Shopee</option>
          <option value="tiktok">TikTok</option>
          <option value="manual_shopee_master">Manual Shopee Master</option>
          <option value="shopee_order">Shopee Order</option>
          <option value="shopee_stock_refresh">Shopee Stock Refresh</option>
          <option value="tiktok_order">TikTok Order</option>
          <option value="safety_check">Safety Check</option>
        </select>
        <select v-model="syncFilters.status" @change="loadSyncLogs(1)">
          <option value="">Semua status</option>
          <option value="success">Success</option>
          <option value="skipped">Skipped / Dilewati</option>
          <option value="error">Error</option>
        </select>
        <input v-model="syncFilters.date" type="date" @change="loadSyncLogs(1)" />
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Time</th><th>Source Marketplace</th><th>Target Marketplace</th><th>SKU</th><th>Old Stock</th><th>New Stock</th><th>Status</th><th>Message</th></tr></thead>
          <tbody>
            <tr v-for="row in syncLogs" :key="row.id">
              <td>{{ formatDate(row.created_at) }}</td>
              <td>{{ labelMarketplace(row.source_marketplace) }}</td>
              <td>{{ labelMarketplace(row.target_marketplace) }}</td>
              <td>{{ row.sku || '-' }}</td>
              <td>{{ row.old_stock ?? '-' }}</td>
              <td>{{ row.new_stock ?? '-' }}</td>
              <td><span :class="['badge', row.status === 'success' ? 'success' : row.status === 'error' ? 'error' : 'neutral']">{{ row.status }}</span></td>
              <td>{{ row.message || '-' }}</td>
            </tr>
            <tr v-if="!syncLogs.length"><td colspan="8" class="empty">Belum ada sync log.</td></tr>
          </tbody>
        </table>
      </div>
      <Pagination :pagination="syncPagination" @change="loadSyncLogs" />
    </section>

    <section v-if="activeTab === 'runtime'" class="panel">
      <div class="safety-summary">
        <div><span>Owner aktif</span><strong>{{ runtimeOwnerLabel }}</strong></div>
        <div><span>Local heartbeat</span><strong>{{ runtimeStatus.local_is_active ? 'Aktif' : 'Timeout' }}</strong></div>
        <div><span>Timeout</span><strong>{{ runtimeStatus.heartbeat_timeout_minutes || 10 }} menit</strong></div>
        <div><span>Online backup</span><strong>{{ runtimeStatus.online_backup_enabled ? 'Standby' : 'Off' }}</strong></div>
        <div><span>Last dry-run</span><strong>{{ formatDate(runtimeStatus.runner_last_dry_run_at) }}</strong></div>
        <div><span>Real mode</span><strong>{{ runtimeStatus.online_backup_real_enabled ? 'ON' : 'OFF' }}</strong></div>
        <div><span>Last real-run</span><strong>{{ formatDate(runtimeStatus.runner_last_real_run_at) }}</strong></div>
        <div><span>Last scheduler</span><strong>{{ formatDate(runtimeStatus.runner_last_scheduler_tick_at) }}</strong></div>
        <div><span>Scheduler mode</span><strong>{{ runtimeStatus.runner_last_scheduler_status || '-' }}</strong></div>
      </div>
      <section class="readiness-panel">
        <header>
          <div>
            <span>Readiness</span>
            <strong>{{ runtimeReadiness.ready_for_real_run ? 'Siap real-run' : 'Belum siap real-run' }}</strong>
          </div>
          <button class="ghost" type="button" @click="loadRuntimeReadiness" :disabled="loadingReadiness">
            {{ loadingReadiness ? 'Cek...' : 'Cek Readiness' }}
          </button>
        </header>
        <div class="readiness-grid">
          <article v-for="item in runtimeReadiness.checks || []" :key="item.key">
            <span :class="['badge', readinessClass(item.status)]">{{ item.status }}</span>
            <strong>{{ item.label }}</strong>
            <p>{{ item.message }}</p>
          </article>
        </div>
      </section>
      <div class="table-actions">
        <button class="ghost" type="button" @click="loadRuntimeEvents(1)">Refresh Riwayat Runtime</button>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Time</th><th>Event</th><th>Owner</th><th>Local</th><th>Online Backup</th><th>Message</th></tr></thead>
          <tbody>
            <tr v-for="row in runtimeEvents.items" :key="row.id">
              <td>{{ formatDate(row.created_at) }}</td>
              <td><span class="badge neutral">{{ row.event_type }}</span></td>
              <td>{{ row.active_owner || '-' }}</td>
              <td>{{ row.local_is_active ? 'Aktif' : 'Timeout' }}</td>
              <td>{{ row.online_backup_enabled ? 'Standby' : 'Off' }}</td>
              <td>{{ row.message || '-' }}</td>
            </tr>
            <tr v-if="!runtimeEvents.items.length"><td colspan="6" class="empty">Belum ada riwayat runtime.</td></tr>
          </tbody>
        </table>
      </div>
      <Pagination :pagination="runtimeEvents.pagination" @change="loadRuntimeEvents" />
    </section>

    <section v-if="activeTab === 'anomaly'" class="panel">
      <div class="safety-summary">
        <div><span>Total anomali</span><strong>{{ stockAnomalies.summary?.total_anomalies || 0 }}</strong></div>
        <div><span>Stok beda</span><strong>{{ stockAnomalies.summary?.stock_mismatch || 0 }}</strong></div>
        <div><span>Cache Shopee kosong</span><strong>{{ stockAnomalies.summary?.missing_shopee_stock || 0 }}</strong></div>
        <div><span>Cache TikTok kosong</span><strong>{{ stockAnomalies.summary?.missing_tiktok_stock || 0 }}</strong></div>
        <div><span>Mapping belum lengkap</span><strong>{{ stockAnomalies.summary?.incomplete_mapping || 0 }}</strong></div>
      </div>
      <div class="filter-row anomaly-filter-row">
        <select v-model="stockAnomalyFilters.type" @change="loadStockAnomalies(1)">
          <option value="">Semua anomali</option>
          <option value="stock_mismatch">Stok beda</option>
          <option value="missing_shopee_stock">Cache Shopee kosong</option>
          <option value="missing_tiktok_stock">Cache TikTok kosong</option>
          <option value="incomplete_mapping">Mapping belum lengkap</option>
        </select>
        <input v-model.trim="stockAnomalyFilters.search" type="search" placeholder="Cari SKU / produk / varian" @keyup.enter="loadStockAnomalies(1)" />
        <button class="ghost" type="button" @click="loadStockAnomalies(1)" :disabled="loadingStockAnomalies">
          {{ loadingStockAnomalies ? 'Memuat...' : 'Cari Anomali' }}
        </button>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>SKU</th><th>Produk</th><th>Varian</th><th>Shopee</th><th>TikTok</th><th>Selisih</th><th>Issue</th><th>Aksi</th></tr></thead>
          <tbody>
            <tr v-for="row in stockAnomalies.items" :key="`${row.sku}-${row.issue_type}`">
              <td>{{ row.sku || '-' }}</td>
              <td>{{ row.product_name || '-' }}</td>
              <td>{{ row.variant_name || '-' }}</td>
              <td>{{ row.shopee_stock ?? '-' }}</td>
              <td>{{ row.tiktok_stock ?? '-' }}</td>
              <td>{{ row.difference ?? '-' }}</td>
              <td>
                <span :class="['badge', row.severity === 'error' ? 'error' : 'neutral']">{{ row.issue_type }}</span>
                <p class="cell-note">{{ row.message }}</p>
              </td>
              <td class="row-actions">
                <button class="ghost" type="button" @click="syncStockAnomaly(row, 'shopee')" :disabled="runningAnomalyKey === anomalyActionKey(row, 'shopee') || row.shopee_stock === null">
                  Shopee -> TikTok
                </button>
                <button class="ghost" type="button" @click="syncStockAnomaly(row, 'tiktok')" :disabled="runningAnomalyKey === anomalyActionKey(row, 'tiktok') || row.tiktok_stock === null">
                  TikTok -> Shopee
                </button>
              </td>
            </tr>
            <tr v-if="!stockAnomalies.items.length"><td colspan="8" class="empty">Tidak ada anomali stok untuk filter ini.</td></tr>
          </tbody>
        </table>
      </div>
      <Pagination :pagination="stockAnomalies.pagination" @change="loadStockAnomalies" />
    </section>

    <section v-if="activeTab === 'skuHistory'" class="panel">
      <div class="filter-row">
        <select v-model="skuHistoryFilters.channel" @change="loadSkuChangeHistory(1)">
          <option value="">Semua channel</option>
          <option value="shopee">Shopee</option>
          <option value="tiktok">TikTok</option>
        </select>
        <select v-model="skuHistoryFilters.status" @change="loadSkuChangeHistory(1)">
          <option value="">Semua status</option>
          <option value="ok">OK</option>
          <option value="success">Success</option>
          <option value="dry_run">Dry Run</option>
          <option value="error">Error</option>
          <option value="skipped">Skipped</option>
        </select>
        <input v-model="skuHistoryFilters.date" type="date" @change="loadSkuChangeHistory(1)" />
        <input v-model.trim="skuHistoryFilters.search" type="search" placeholder="Cari SKU / produk / variant ID" @keyup.enter="loadSkuChangeHistory(1)" />
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Time</th><th>Channel</th><th>Produk</th><th>Variant</th><th>SKU Lama</th><th>SKU Baru</th><th>Status</th><th>Action</th></tr></thead>
          <tbody>
            <tr v-for="row in skuHistory.items" :key="row.id">
              <td>{{ formatDate(row.created_at) }}</td>
              <td>{{ labelMarketplace(row.channel) }}</td>
              <td>{{ row.product_name || row.internal_sku || row.product_id || '-' }}</td>
              <td>{{ row.variant_name || row.variant_id || '-' }}</td>
              <td>{{ row.old_seller_sku || 'Tidak ada SKU' }}</td>
              <td>{{ row.new_seller_sku || '-' }}</td>
              <td><span :class="['badge', row.status === 'error' ? 'error' : row.status === 'ok' || row.status === 'success' ? 'success' : 'neutral']">{{ row.status }}</span></td>
              <td>{{ row.action || '-' }}</td>
            </tr>
            <tr v-if="!skuHistory.items.length"><td colspan="8" class="empty">Belum ada history perubahan SKU.</td></tr>
          </tbody>
        </table>
      </div>
      <Pagination :pagination="skuHistory.pagination" @change="loadSkuChangeHistory" />
    </section>

    <section v-if="activeTab === 'watchdog'" class="panel">
      <div class="safety-summary">
        <div><span>Order perlu cek</span><strong>{{ orderWatchdog.summary?.watch_count || 0 }}</strong></div>
        <div><span>Order dicek</span><strong>{{ orderWatchdog.summary?.checked_orders || 0 }}</strong></div>
        <div><span>Ambang menit</span><strong>{{ orderWatchdog.summary?.threshold_minutes || 5 }}</strong></div>
        <div><span>Window jam</span><strong>{{ orderWatchdog.summary?.window_hours || 24 }}</strong></div>
      </div>
      <div class="filter-row compact-filter-row">
        <input v-model.number="watchdogFilters.minutes" type="number" min="1" max="180" />
        <input v-model.number="watchdogFilters.hours" type="number" min="1" max="168" />
        <button class="ghost" type="button" @click="loadOrderWatchdog" :disabled="loadingWatchdog">
          {{ loadingWatchdog ? 'Memuat...' : 'Refresh Watchdog' }}
        </button>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Time</th><th>Order</th><th>Source</th><th>Target</th><th>Umur</th><th>Status</th><th>Message</th></tr></thead>
          <tbody>
            <tr v-for="row in orderWatchdog.items" :key="row.id">
              <td>{{ formatDate(row.created_at) }}</td>
              <td>{{ row.order_ref || '-' }}</td>
              <td>{{ labelMarketplace(row.source_marketplace) }}</td>
              <td>{{ labelMarketplace(row.target_marketplace) }}</td>
              <td>{{ row.age_minutes }} menit</td>
              <td><span class="badge error">{{ row.status }}</span></td>
              <td>{{ row.message }}</td>
            </tr>
            <tr v-if="!orderWatchdog.items.length"><td colspan="7" class="empty">Tidak ada order yang tertahan menurut watchdog.</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="activeTab === 'report'" class="panel">
      <div class="safety-summary">
        <div><span>Total mapping aktif</span><strong>{{ reconciliationReport.summary?.total_active_mappings || 0 }}</strong></div>
        <div><span>Sinkron</span><strong>{{ reconciliationReport.summary?.aligned || 0 }}</strong></div>
        <div><span>Total anomali</span><strong>{{ reconciliationReport.summary?.total_anomalies || 0 }}</strong></div>
        <div><span>Generated</span><strong>{{ formatDate(reconciliationReport.generated_at) }}</strong></div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>SKU</th><th>Produk</th><th>Varian</th><th>Shopee</th><th>TikTok</th><th>Issue</th><th>Message</th></tr></thead>
          <tbody>
            <tr v-for="row in reconciliationReport.items" :key="`${row.sku}-${row.issue_type}`">
              <td>{{ row.sku || '-' }}</td>
              <td>{{ row.product_name || '-' }}</td>
              <td>{{ row.variant_name || '-' }}</td>
              <td>{{ row.shopee_stock ?? '-' }}</td>
              <td>{{ row.tiktok_stock ?? '-' }}</td>
              <td><span :class="['badge', row.severity === 'error' ? 'error' : 'neutral']">{{ row.issue_type }}</span></td>
              <td>{{ row.message || '-' }}</td>
            </tr>
            <tr v-if="!reconciliationReport.items.length"><td colspan="7" class="empty">Report bersih, tidak ada anomali.</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="activeTab === 'queue'" class="panel">
      <div class="safety-summary">
        <div><span>Pending</span><strong>{{ queueDashboard.summary?.pending || 0 }}</strong></div>
        <div><span>Processing</span><strong>{{ queueDashboard.summary?.processing || 0 }}</strong></div>
        <div><span>Success</span><strong>{{ queueDashboard.summary?.success || 0 }}</strong></div>
        <div><span>Failed</span><strong>{{ queueDashboard.summary?.failed || 0 }}</strong></div>
        <div><span>Total</span><strong>{{ queueDashboard.summary?.total || 0 }}</strong></div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Time</th><th>Source</th><th>Target</th><th>SKU/Order</th><th>Status</th><th>Old</th><th>New</th><th>Message</th></tr></thead>
          <tbody>
            <tr v-for="row in queueDashboard.items" :key="row.id">
              <td>{{ formatDate(row.created_at) }}</td>
              <td>{{ labelMarketplace(row.source_marketplace) }}</td>
              <td>{{ labelMarketplace(row.target_marketplace) }}</td>
              <td>{{ row.sku || '-' }}</td>
              <td><span :class="['badge', row.status === 'success' ? 'success' : row.status === 'error' || row.status === 'skipped' ? 'error' : 'neutral']">{{ row.status }}</span></td>
              <td>{{ row.old_stock ?? '-' }}</td>
              <td>{{ row.new_stock ?? '-' }}</td>
              <td>{{ row.message || '-' }}</td>
            </tr>
            <tr v-if="!queueDashboard.items.length"><td colspan="8" class="empty">Belum ada item queue/sync pada window ini.</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="activeTab === 'safety'" class="panel">
      <div class="safety-summary">
        <div><span>Last run</span><strong>{{ formatDate(safety.summary?.last_run) }}</strong></div>
        <div><span>Next run</span><strong>{{ formatDate(safety.summary?.next_run) }}</strong></div>
        <div><span>Total checked</span><strong>{{ safety.summary?.total_checked || 0 }}</strong></div>
        <div><span>Total corrected</span><strong>{{ safety.summary?.total_corrected || 0 }}</strong></div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Time</th><th>SKU</th><th>Shopee Stock</th><th>TikTok Stock</th><th>Action</th></tr></thead>
          <tbody>
            <tr v-for="row in safety.items" :key="row.id">
              <td>{{ formatDate(row.created_at) }}</td>
              <td>{{ row.sku || '-' }}</td>
              <td>{{ row.old_stock ?? '-' }}</td>
              <td>{{ row.new_stock ?? '-' }}</td>
              <td>{{ row.message || '-' }}</td>
            </tr>
            <tr v-if="!safety.items.length"><td colspan="5" class="empty">Belum ada histori safety check.</td></tr>
          </tbody>
        </table>
      </div>
      <Pagination :pagination="safety.pagination" @change="loadSafety" />
    </section>

    <div v-if="detailModal.open" class="modal-backdrop" @click.self="closeOrderSyncDetail">
      <section class="detail-modal">
        <header class="modal-head">
          <div>
            <p>Order Sync Detail</p>
            <h2>{{ detailModal.data?.order_ref || detailModal.data?.log?.sku || '-' }}</h2>
          </div>
          <div class="modal-actions">
            <button class="primary" type="button" @click="retryOrderSyncDetail" :disabled="detailModal.retrying || detailModal.loading">
              {{ detailModal.retrying ? 'Retry...' : 'Retry Sync' }}
            </button>
            <button class="ghost" type="button" @click="closeOrderSyncDetail">Tutup</button>
          </div>
        </header>

        <p v-if="detailModal.loading" class="empty">Memuat detail...</p>
        <template v-else>
          <div class="detail-grid">
            <div><span>Status Order</span><strong>{{ detailModal.data?.order?.order_status || '-' }}</strong></div>
            <div><span>Created</span><strong>{{ formatDate(detailModal.data?.order?.create_time) }}</strong></div>
            <div><span>Updated</span><strong>{{ formatDate(detailModal.data?.order?.update_time) }}</strong></div>
            <div><span>Log Status</span><strong>{{ detailModal.data?.log?.status || '-' }}</strong></div>
          </div>

          <h3>Produk Order</h3>
          <div class="detail-items">
            <article v-for="(item, index) in detailModal.data?.order?.items || []" :key="`${item.item_id}-${item.model_id}-${index}`">
              <img v-if="item.image_url" :src="item.image_url" alt="" />
              <div>
                <strong>{{ item.product_name || '-' }}</strong>
                <span>Varian: {{ item.variant_name || '-' }}</span>
                <span>Qty: {{ item.qty ?? '-' }}</span>
                <span>SKU: {{ item.seller_sku || '-' }}</span>
                <span>Item/Model: {{ item.item_id || '-' }} / {{ item.model_id || '-' }}</span>
              </div>
            </article>
            <p v-if="!(detailModal.data?.order?.items || []).length" class="empty">Detail produk order belum tersedia untuk log ini.</p>
          </div>

          <h3>Update Stok</h3>
          <div class="table-wrap detail-table">
            <table>
              <thead><tr><th>Time</th><th>Jenis</th><th>Target</th><th>SKU</th><th>Perubahan</th><th>Status</th><th>Message</th></tr></thead>
              <tbody>
                <tr v-for="row in detailModal.data?.stock_updates || []" :key="row.id">
                  <td>{{ formatDate(row.time) }}</td>
                  <td>{{ labelMarketplace(row.type) }}</td>
                  <td>{{ labelMarketplace(row.target) }}</td>
                  <td>{{ row.sku || '-' }}</td>
                  <td>{{ row.old_stock ?? '-' }} -> {{ row.new_stock ?? '-' }}</td>
                  <td><span :class="['badge', row.status === 'success' ? 'success' : row.status === 'error' ? 'error' : 'neutral']">{{ row.status }}</span></td>
                  <td>{{ row.message || '-' }}</td>
                </tr>
                <tr v-if="!(detailModal.data?.stock_updates || []).length"><td colspan="7" class="empty">Belum ada update stok terkait.</td></tr>
              </tbody>
            </table>
          </div>
        </template>
      </section>
    </div>

    <div v-if="bulkSkuPreview.open" class="modal-backdrop" @click.self="closeBulkSkuPreview">
      <section class="detail-modal">
        <header class="modal-head">
          <div>
            <p>Preview Bulk Update SKU</p>
            <h2>{{ bulkSkuPreview.data?.total_candidates || 0 }} kandidat SKU kosong</h2>
          </div>
          <div class="modal-actions">
            <button class="primary" type="button" @click="bulkUpdateEmptySkusFromPreview" :disabled="runningBulkSkuUpdate || bulkSkuPreview.loading || !(bulkSkuPreview.data?.processed || 0)">
              {{ runningBulkSkuUpdate ? 'Update SKU...' : 'Update Semua di Preview' }}
            </button>
            <button class="ghost" type="button" @click="closeBulkSkuPreview">Tutup</button>
          </div>
        </header>
        <p v-if="bulkSkuPreview.loading" class="empty">Memuat preview SKU...</p>
        <template v-else>
          <div class="safety-summary">
            <div><span>Kandidat</span><strong>{{ bulkSkuPreview.data?.total_candidates || 0 }}</strong></div>
            <div><span>Ditampilkan</span><strong>{{ bulkSkuPreview.data?.processed || 0 }}</strong></div>
            <div><span>Limit per klik</span><strong>20</strong></div>
          </div>
          <div class="table-wrap detail-table">
            <table>
              <thead><tr><th>Produk</th><th>Varian</th><th>SKU Template</th><th>Shopee</th><th>TikTok</th><th>Status</th></tr></thead>
              <tbody>
                <tr v-for="row in bulkSkuPreview.data?.items || []" :key="`${row.stock_master_id}-${row.seller_sku}`">
                  <td>{{ row.product_name || '-' }}</td>
                  <td>{{ row.variant_name || '-' }}</td>
                  <td>{{ row.seller_sku || '-' }}</td>
                  <td>{{ row.apply_shopee ? 'Update' : '-' }}</td>
                  <td>{{ row.apply_tiktok ? 'Update' : '-' }}</td>
                  <td><span :class="['badge', row.status === 'error' ? 'error' : 'neutral']">{{ row.status }}</span></td>
                </tr>
                <tr v-if="!(bulkSkuPreview.data?.items || []).length"><td colspan="6" class="empty">Tidak ada SKU kosong untuk diupdate.</td></tr>
              </tbody>
            </table>
          </div>
        </template>
      </section>
    </div>
  </section>
</template>

<script setup>
import { computed, defineComponent, h, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { omnichannelService } from '@/services'

const BROWSER_AUTO_SYNC_KEY = 'marketplace_auto_sync_browser_enabled'
const BROWSER_AUTO_SYNC_INTERVAL_MS = 60 * 1000
const BROWSER_AUTO_SYNC_SAFETY_EVERY_RUNS = 15
const STOCK_ANOMALY_AUTO_REFRESH_MS = 15000
const RUNTIME_HEARTBEAT_MS = 60 * 1000

const loading = ref(false)
const runningSafety = ref(false)
const runningShopeeToTiktok = ref(false)
const runningOrderPoll = ref(false)
const runningTiktokOrderPoll = ref(false)
const runningInstantCheck = ref(false)
const runningRetryOpenIssues = ref(false)
const runningBulkSkuUpdate = ref(false)
const runningBulkSkuPreview = ref(false)
const updatingRuntime = ref(false)
const browserAutoSyncEnabled = ref(false)
const browserAutoSyncRunning = ref(false)
const browserAutoSyncLastRun = ref(null)
const browserAutoSyncNextRun = ref(null)
const browserAutoSyncCountdownSeconds = ref(0)
const exportingOrderSync = ref(false)
const activeTab = ref('webhook')
const notice = ref('')
const noticeType = ref('success')
const dashboard = ref({ statuses: {}, engine: {}, safety: {}, order_sync: {}, webhook_urls: {} })
const runtimeStatus = ref({})
const stbStatus = ref({})
const runtimeEvents = ref({ items: [], pagination: { page: 1, last_page: 1, total: 0 } })
const bridgeStatus = ref({})
const runtimeReadiness = ref({ checks: [], summary: {}, ready_for_real_run: false })
const webhookLogs = ref([])
const syncLogs = ref([])
const webhookPagination = ref({ page: 1, last_page: 1, total: 0 })
const syncPagination = ref({ page: 1, last_page: 1, total: 0 })
const safety = ref({ summary: {}, items: [], pagination: { page: 1, last_page: 1, total: 0 } })
const orderSync = ref({ summary: {}, items: [], pagination: { page: 1, last_page: 1, total: 0 } })
const stockAnomalies = ref({ summary: {}, items: [], pagination: { page: 1, last_page: 1, total: 0 } })
const skuHistory = ref({ items: [], pagination: { page: 1, last_page: 1, total: 0 } })
const orderWatchdog = ref({ summary: {}, items: [] })
const reconciliationReport = ref({ summary: {}, items: [], generated_at: null })
const queueDashboard = ref({ summary: {}, items: [] })
const loadingStockAnomalies = ref(false)
const loadingWatchdog = ref(false)
const loadingBridgeStatus = ref(false)
const loadingReadiness = ref(false)
const runningAnomalyKey = ref('')
const detailModal = reactive({ open: false, loading: false, retrying: false, data: null })
const bulkSkuPreview = reactive({ open: false, loading: false, data: null })
const webhookFilters = reactive({ marketplace: '', status: '', date: '' })
const syncFilters = reactive({ marketplace: '', status: '', date: '' })
const orderFilters = reactive({ type: '', status: '', date: '', search: '', order_class: '' })
const stockAnomalyFilters = reactive({ type: '', search: '' })
const skuHistoryFilters = reactive({ channel: '', status: '', date: '', search: '' })
const watchdogFilters = reactive({ minutes: 5, hours: 24 })
let browserAutoSyncTimer = null
let browserAutoSyncCountdownTimer = null
let stockAnomalyAutoRefreshTimer = null
let runtimeHeartbeatTimer = null
let browserAutoSyncRunCount = 0

const Pagination = defineComponent({
  props: { pagination: { type: Object, required: true } },
  emits: ['change'],
  setup(props, { emit }) {
    return () => h('div', { class: 'pagination' }, [
      h('button', { class: 'ghost', disabled: props.pagination.page <= 1, onClick: () => emit('change', props.pagination.page - 1) }, 'Prev'),
      h('span', `Halaman ${props.pagination.page || 1} / ${props.pagination.last_page || 1} | ${props.pagination.total || 0} data`),
      h('button', { class: 'ghost', disabled: props.pagination.page >= props.pagination.last_page, onClick: () => emit('change', props.pagination.page + 1) }, 'Next')
    ])
  }
})

const marketplaceStatus = (marketplace) => dashboard.value.statuses?.[marketplace] || {}
const labelMarketplace = (value) => {
  const text = String(value || '-')
  if (text === 'shopee') return 'Shopee'
  if (text === 'tiktok') return 'TikTok'
  if (text === 'safety_check') return 'Safety Check'
  if (text === 'manual_shopee_master') return 'Manual Shopee Master'
  if (text === 'manual_anomaly_tiktok_master') return 'Manual TikTok Master'
  if (text === 'shopee_order') return 'Shopee Order'
  if (text === 'shopee_stock_refresh') return 'Shopee Stock Refresh'
  if (text === 'tiktok_order') return 'TikTok Order'
  if (text === 'all') return 'Shopee + TikTok'
  return text
}
const formatDate = (value) => value ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '-'
const formatTime = (value) => value ? new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date(value)) : '-'
const browserAutoSyncCountdownLabel = computed(() => {
  if (isBrowserAutoSyncLocked.value) return '-'
  if (!browserAutoSyncEnabled.value) return '-'
  if (browserAutoSyncRunning.value) return 'Sedang berjalan'

  const seconds = Math.max(0, browserAutoSyncCountdownSeconds.value)
  const minutes = Math.floor(seconds / 60)
  const remainingSeconds = seconds % 60

  return `${String(minutes).padStart(2, '0')}:${String(remainingSeconds).padStart(2, '0')}`
})
const browserAutoSyncNextRunLabel = computed(() => browserAutoSyncNextRun.value ? `${formatTime(browserAutoSyncNextRun.value)} WITA` : '-')
const isStbWorkerMode = computed(() => Boolean(stbStatus.value.stb_sync_worker || runtimeStatus.value.stb_sync_worker))
const isBrowserAutoSyncLocked = computed(() => isStbWorkerMode.value || stbStatus.value.auto_browser_enabled === false || runtimeStatus.value.enable_auto_browser === false)
const runtimeOwnerLabel = computed(() => {
  if (runtimeStatus.value.active_owner === 'stb_sync_worker') return 'STB worker'
  if (runtimeStatus.value.active_owner === 'online_backup') return 'Online backup'
  if (runtimeStatus.value.active_owner === 'paused') return 'Pause'
  return 'Local'
})
const runtimeOwnerClass = computed(() => {
  if (runtimeStatus.value.active_owner === 'stb_sync_worker') return 'success'
  if (runtimeStatus.value.active_owner === 'online_backup') return 'neutral'
  if (runtimeStatus.value.active_owner === 'paused') return 'error'
  return 'success'
})
const queueStatusLabel = computed(() => {
  const status = stbStatus.value.queue_status?.status
  if (!status) return '-'
  if (status === 'running') return 'Running'
  if (status === 'unknown') return 'Unknown'
  return status
})
const queueStatusClass = computed(() => {
  const status = stbStatus.value.queue_status?.status
  if (status === 'running') return 'success'
  if (status === 'unknown') return 'neutral'
  return 'error'
})
const schedulerStatusLabel = computed(() => {
  const status = stbStatus.value.scheduler_status?.status
  if (!status) return '-'
  return status === 'online' ? 'Online' : 'Offline'
})
const schedulerStatusClass = computed(() => stbStatus.value.scheduler_status?.status === 'online' ? 'success' : 'error')
const bridgeStatusClass = computed(() => {
  if (bridgeStatus.value.status === 'ok') return 'success'
  if (bridgeStatus.value.status === 'warning') return 'neutral'
  return 'error'
})
const bridgeStatusLabel = computed(() => {
  if (!bridgeStatus.value.status) return 'Belum dicek'
  if (bridgeStatus.value.secured) return 'Locked'
  if (bridgeStatus.value.bridge?.bridge === 'skipped') return 'Skipped'
  return bridgeStatus.value.status
})
const bridgeMessage = computed(() => {
  if (bridgeStatus.value.secured) return 'Bridge hidup dan terkunci token.'
  if (bridgeStatus.value.message) return bridgeStatus.value.message
  if (bridgeStatus.value.bridge?.reason) return bridgeStatus.value.bridge.reason
  if (bridgeStatus.value.bridge?.data?.message) return bridgeStatus.value.bridge.data.message
  return '-'
})
const readinessClass = (status) => {
  if (status === 'ok') return 'success'
  if (status === 'danger') return 'error'
  return 'neutral'
}
const failureNotificationLabel = computed(() => {
  const config = dashboard.value.engine?.failure_notifications
  if (!config?.enabled) return 'Off'

  const channels = []
  if (config.telegram) channels.push('Telegram')
  if (config.whatsapp) channels.push('WhatsApp')

  return channels.length ? channels.join(' + ') : 'Active, channel belum lengkap'
})

const loadDashboard = async () => {
  const { data } = await omnichannelService.autoSyncDashboard()
  dashboard.value = data.data || {}
}

const loadRuntimeStatus = async () => {
  const { data } = await omnichannelService.autoSyncRuntimeStatus()
  runtimeStatus.value = data.data || {}
}

const loadStbStatus = async () => {
  const { data } = await omnichannelService.stbRuntimeStatus()
  stbStatus.value = data || {}
  if (isBrowserAutoSyncLocked.value && browserAutoSyncEnabled.value) {
    stopBrowserAutoSync()
  }
}

const loadBridgeStatus = async () => {
  loadingBridgeStatus.value = true
  try {
    const { data } = await omnichannelService.autoSyncBridgeStatus()
    bridgeStatus.value = data || {}
  } catch (error) {
    bridgeStatus.value = {
      status: 'error',
      message: error?.response?.data?.message || error?.message || 'Bridge status gagal dicek.'
    }
  } finally {
    loadingBridgeStatus.value = false
  }
}

const loadRuntimeReadiness = async () => {
  loadingReadiness.value = true
  try {
    const { data } = await omnichannelService.autoSyncRuntimeReadiness()
    runtimeReadiness.value = data || runtimeReadiness.value
  } finally {
    loadingReadiness.value = false
  }
}

const loadRuntimeEvents = async (page = runtimeEvents.value.pagination?.page || 1) => {
  const { data } = await omnichannelService.autoSyncRuntimeEvents({ page, per_page: 20 })
  runtimeEvents.value = {
    items: data.items || [],
    pagination: data.pagination || runtimeEvents.value.pagination
  }
}

const sendRuntimeHeartbeat = async () => {
  const { data } = await omnichannelService.autoSyncRuntimeHeartbeat({
    machine_name: 'Localhost Dashboard',
    source: 'marketplace_auto_sync_page'
  })
  runtimeStatus.value = data.data || {}
}

const startRuntimeHeartbeat = async () => {
  await sendRuntimeHeartbeat()
  if (runtimeHeartbeatTimer) window.clearInterval(runtimeHeartbeatTimer)
  runtimeHeartbeatTimer = window.setInterval(() => {
    if (!document.hidden) {
      sendRuntimeHeartbeat().catch(() => {})
    }
  }, RUNTIME_HEARTBEAT_MS)
}

const updateRuntimeSettings = async (payload) => {
  updatingRuntime.value = true
  notice.value = ''
  try {
    const { data } = await omnichannelService.updateAutoSyncRuntimeSettings(payload)
    runtimeStatus.value = data.data || {}
    notice.value = runtimeStatus.value.last_decision_reason || 'Pengaturan runtime tersimpan.'
    noticeType.value = 'success'
    await loadRuntimeEvents(1)
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Pengaturan runtime gagal disimpan.'
    noticeType.value = 'error'
  } finally {
    updatingRuntime.value = false
  }
}

const toggleOnlineBackup = () => {
  updateRuntimeSettings({ online_backup_enabled: !runtimeStatus.value.online_backup_enabled })
}

const toggleRuntimePause = () => {
  updateRuntimeSettings({ active_owner: runtimeStatus.value.active_owner === 'paused' ? 'local' : 'paused' })
}

const toggleRealMode = () => {
  if (runtimeStatus.value.online_backup_real_enabled) {
    updateRuntimeSettings({ online_backup_real_enabled: false })
    return
  }

  const confirmText = window.prompt('Untuk mengaktifkan real mode, ketik: AKTIFKAN REAL BACKUP')
  if (confirmText !== 'AKTIFKAN REAL BACKUP') {
    notice.value = 'Real mode batal diaktifkan. Teks konfirmasi tidak sesuai.'
    noticeType.value = 'error'
    return
  }

  updateRuntimeSettings({
    online_backup_enabled: true,
    online_backup_real_enabled: true,
    confirm_text: confirmText
  })
}

const checkOnlineBackupDecision = async () => {
  updatingRuntime.value = true
  notice.value = ''
  try {
    const { data } = await omnichannelService.autoSyncRuntimeOnlineBackupTick()
    runtimeStatus.value = data.data || {}
    notice.value = runtimeStatus.value.last_decision_reason || 'Status owner runtime dicek.'
    noticeType.value = 'success'
    await loadRuntimeEvents(1)
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Cek owner runtime gagal.'
    noticeType.value = 'error'
  } finally {
    updatingRuntime.value = false
  }
}

const runBackupDryRun = async () => {
  updatingRuntime.value = true
  notice.value = ''
  try {
    const { data } = await omnichannelService.autoSyncBackupRunnerDryRun()
    runtimeStatus.value = data.data || {}
    notice.value = data.message || 'Dry-run backup selesai.'
    noticeType.value = data.allowed ? 'success' : 'error'
    await loadRuntimeEvents(1)
  } catch (error) {
    if (error?.response?.status === 409) {
      runtimeStatus.value = error.response.data?.data || runtimeStatus.value
      notice.value = error.response.data?.message || 'Dry-run backup sedang dikunci proses lain.'
    } else {
      notice.value = error?.response?.data?.message || error?.message || 'Dry-run backup gagal.'
    }
    noticeType.value = 'error'
    await loadRuntimeEvents(1)
  } finally {
    updatingRuntime.value = false
  }
}

const runRealBackup = async () => {
  const confirmed = window.confirm('Jalankan real backup order sync sekarang? Ini hanya aktif jika real mode sudah ON dan local timeout.')
  if (!confirmed) return

  updatingRuntime.value = true
  notice.value = ''
  try {
    const { data } = await omnichannelService.autoSyncBackupRunnerRun({ hours: 1 })
    runtimeStatus.value = data.data || {}
    notice.value = data.message || 'Real backup selesai.'
    noticeType.value = data.status === 'warning' ? 'error' : 'success'
    await Promise.all([loadRuntimeEvents(1), loadOrderSync(1), loadSyncLogs(1), loadDashboard()])
  } catch (error) {
    runtimeStatus.value = error?.response?.data?.data || runtimeStatus.value
    notice.value = error?.response?.data?.message || error?.message || 'Real backup gagal atau diblokir.'
    noticeType.value = 'error'
    await loadRuntimeEvents(1)
  } finally {
    updatingRuntime.value = false
  }
}

const runSchedulerTick = async () => {
  updatingRuntime.value = true
  notice.value = ''
  try {
    const { data } = await omnichannelService.autoSyncBackupRunnerSchedulerTick({ hours: 1 })
    runtimeStatus.value = data.data || {}
    notice.value = data.message || 'Scheduler tick selesai.'
    noticeType.value = data.status === 'warning' ? 'error' : 'success'
    await Promise.all([loadRuntimeEvents(1), loadOrderSync(1), loadSyncLogs(1), loadDashboard()])
  } catch (error) {
    runtimeStatus.value = error?.response?.data?.data || runtimeStatus.value
    notice.value = error?.response?.data?.message || error?.message || 'Scheduler tick gagal atau diblokir.'
    noticeType.value = 'error'
    await loadRuntimeEvents(1)
  } finally {
    updatingRuntime.value = false
  }
}

const loadWebhookLogs = async (page = webhookPagination.value.page || 1) => {
  const { data } = await omnichannelService.autoSyncWebhookLogs({ ...webhookFilters, page, per_page: 20 })
  webhookLogs.value = data.items || []
  webhookPagination.value = data.pagination || webhookPagination.value
}

const loadSyncLogs = async (page = syncPagination.value.page || 1) => {
  const { data } = await omnichannelService.autoSyncLogs({ ...syncFilters, page, per_page: 20 })
  syncLogs.value = data.items || []
  syncPagination.value = data.pagination || syncPagination.value
}

const loadSafety = async (page = safety.value.pagination?.page || 1) => {
  const { data } = await omnichannelService.autoSyncSafety({ page, per_page: 20 })
  safety.value = {
    summary: data.summary || {},
    items: data.items || [],
    pagination: data.pagination || safety.value.pagination
  }
}

const loadOrderSync = async (page = orderSync.value.pagination?.page || 1) => {
  const { data } = await omnichannelService.autoSyncOrderSync({ ...orderFilters, page, per_page: 20 })
  orderSync.value = {
    summary: data.summary || {},
    items: data.items || [],
    pagination: data.pagination || orderSync.value.pagination
  }
}

const setOrderClass = (orderClass) => {
  orderFilters.order_class = orderClass
  if (orderClass === 'cancel') activeTab.value = 'orderCancel'
  if (orderClass !== 'cancel' && activeTab.value === 'orderCancel') activeTab.value = 'order'
  loadOrderSync(1)
}

const setOrderTab = (orderClass = '') => {
  activeTab.value = orderClass === 'cancel' ? 'orderCancel' : 'order'
  orderFilters.order_class = orderClass
  loadOrderSync(1)
}

const loadStockAnomalies = async (page = stockAnomalies.value.pagination?.page || 1) => {
  if (loadingStockAnomalies.value) return
  loadingStockAnomalies.value = true
  try {
    const { data } = await omnichannelService.autoSyncStockAnomalies({ ...stockAnomalyFilters, page, per_page: 20 })
    stockAnomalies.value = {
      summary: data.summary || {},
      items: data.items || [],
      pagination: data.pagination || stockAnomalies.value.pagination
    }
  } finally {
    loadingStockAnomalies.value = false
  }
}

const refreshStockAnomaliesIfVisible = () => {
  if (document.hidden || activeTab.value !== 'anomaly' || runningAnomalyKey.value) return
  loadStockAnomalies(stockAnomalies.value.pagination?.page || 1)
}

const loadSkuChangeHistory = async (page = skuHistory.value.pagination?.page || 1) => {
  const { data } = await omnichannelService.autoSyncSkuChangeHistory({ ...skuHistoryFilters, page, per_page: 20 })
  skuHistory.value = {
    items: data.items || [],
    pagination: data.pagination || skuHistory.value.pagination
  }
}

const loadOrderWatchdog = async () => {
  loadingWatchdog.value = true
  try {
    const { data } = await omnichannelService.autoSyncOrderWatchdog({ ...watchdogFilters })
    orderWatchdog.value = {
      summary: data.summary || {},
      items: data.items || []
    }
  } finally {
    loadingWatchdog.value = false
  }
}

const loadReconciliationReport = async () => {
  const { data } = await omnichannelService.autoSyncReconciliationReport()
  reconciliationReport.value = {
    summary: data.summary || {},
    items: data.items || [],
    generated_at: data.generated_at || null
  }
}

const loadQueueDashboard = async () => {
  const { data } = await omnichannelService.autoSyncQueueDashboard({ hours: 24, limit: 50 })
  queueDashboard.value = {
    summary: data.summary || {},
    items: data.items || []
  }
}

const loadAll = async () => {
  loading.value = true
  notice.value = ''
  try {
    await Promise.all([
      loadDashboard(),
      loadRuntimeStatus(),
      loadStbStatus(),
      loadBridgeStatus(),
      loadRuntimeReadiness(),
      loadRuntimeEvents(1),
      loadWebhookLogs(1),
      loadOrderSync(1),
      loadSyncLogs(1),
      loadSafety(1),
      loadStockAnomalies(1),
      loadSkuChangeHistory(1),
      loadOrderWatchdog(),
      loadReconciliationReport(),
      loadQueueDashboard()
    ])
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Data sinkronisasi gagal dimuat.'
    noticeType.value = 'error'
  } finally {
    loading.value = false
  }
}

const instantCheckOrders = async () => {
  runningInstantCheck.value = true
  notice.value = ''
  try {
    const { data } = await omnichannelService.instantAutoSyncCheck('all')
    const shopee = data.shopee || {}
    const tiktok = data.tiktok || {}
    notice.value = [
      data.message || 'Instant check selesai.',
      `Shopee baru: ${shopee.processed || 0}`,
      `TikTok baru: ${tiktok.processed || 0}`,
      `Gagal: ${(shopee.failed || 0) + (tiktok.failed || 0)}`
    ].join(' | ')
    noticeType.value = data.status === 'warning' ? 'error' : 'success'
    orderFilters.order_class = 'instant'
    await Promise.all([loadDashboard(), loadOrderSync(1), loadSyncLogs(1), loadSafety(1), loadStockAnomalies(1), loadOrderWatchdog(), loadQueueDashboard()])
    activeTab.value = 'order'
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Instant check order gagal dijalankan.'
    noticeType.value = 'error'
  } finally {
    runningInstantCheck.value = false
  }
}

const retryOpenIssues = async () => {
  runningRetryOpenIssues.value = true
  notice.value = ''
  try {
    const { data } = await omnichannelService.retryAutoSyncOpenIssues(10)
    notice.value = [
      data.message || 'Retry open issues selesai.',
      `Dicek: ${data.checked || 0}`,
      `Berhasil: ${data.success || 0}`,
      `Gagal: ${data.failed || 0}`
    ].join(' | ')
    noticeType.value = data.status === 'warning' ? 'error' : 'success'
    await Promise.all([loadDashboard(), loadOrderSync(1), loadSyncLogs(1), loadSafety(1), loadStockAnomalies(1), loadOrderWatchdog(), loadQueueDashboard()])
    activeTab.value = 'order'
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Retry open issues gagal.'
    noticeType.value = 'error'
  } finally {
    runningRetryOpenIssues.value = false
  }
}

const bulkUpdateEmptySkus = async () => {
  const confirmed = window.confirm('Update SKU kosong ke marketplace memakai SKU template mapping? Default maksimal 20 varian per klik.')
  if (!confirmed) return

  runningBulkSkuUpdate.value = true
  notice.value = ''
  try {
    const { data } = await omnichannelService.bulkUpdateAutoSyncEmptySkus(20)
    notice.value = [
      data.message || 'Bulk update SKU kosong selesai.',
      `Kandidat: ${data.total_candidates || 0}`,
      `Diproses: ${data.processed || 0}`,
      `Berhasil: ${data.success || 0}`,
      `Gagal: ${data.failed || 0}`
    ].join(' | ')
    noticeType.value = data.status === 'warning' ? 'error' : 'success'
    await Promise.all([loadDashboard(), loadOrderSync(1), loadSyncLogs(1), loadStockAnomalies(1), loadSkuChangeHistory(1), loadReconciliationReport(), loadQueueDashboard()])
    activeTab.value = 'skuHistory'
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Bulk update SKU kosong gagal.'
    noticeType.value = 'error'
  } finally {
    runningBulkSkuUpdate.value = false
  }
}

const previewBulkUpdateEmptySkus = async () => {
  runningBulkSkuPreview.value = true
  bulkSkuPreview.open = true
  bulkSkuPreview.loading = true
  bulkSkuPreview.data = null
  notice.value = ''
  try {
    const { data } = await omnichannelService.bulkUpdateAutoSyncEmptySkus(20, true)
    bulkSkuPreview.data = data
    notice.value = data.message || 'Preview bulk update SKU kosong selesai.'
    noticeType.value = 'success'
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Preview bulk update SKU kosong gagal.'
    noticeType.value = 'error'
    bulkSkuPreview.open = false
  } finally {
    bulkSkuPreview.loading = false
    runningBulkSkuPreview.value = false
  }
}

const closeBulkSkuPreview = () => {
  bulkSkuPreview.open = false
  bulkSkuPreview.loading = false
  bulkSkuPreview.data = null
}

const bulkUpdateEmptySkusFromPreview = async () => {
  runningBulkSkuUpdate.value = true
  notice.value = ''
  try {
    const { data } = await omnichannelService.bulkUpdateAutoSyncEmptySkus(20, false)
    notice.value = [
      data.message || 'Bulk update SKU kosong selesai.',
      `Kandidat: ${data.total_candidates || 0}`,
      `Diproses: ${data.processed || 0}`,
      `Berhasil: ${data.success || 0}`,
      `Gagal: ${data.failed || 0}`
    ].join(' | ')
    noticeType.value = data.status === 'warning' ? 'error' : 'success'
    closeBulkSkuPreview()
    await Promise.all([loadDashboard(), loadOrderSync(1), loadSyncLogs(1), loadStockAnomalies(1), loadSkuChangeHistory(1), loadReconciliationReport(), loadQueueDashboard()])
    activeTab.value = 'skuHistory'
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Bulk update SKU kosong gagal.'
    noticeType.value = 'error'
  } finally {
    runningBulkSkuUpdate.value = false
  }
}

const runSafetyCheck = async () => {
  runningSafety.value = true
  notice.value = ''
  try {
    const { data } = await omnichannelService.runAutoSyncSafetyCheck()
    notice.value = data.message || 'Safety check selesai.'
    noticeType.value = 'success'
    await Promise.all([loadDashboard(), loadOrderSync(1), loadSyncLogs(1), loadSafety(1), loadStockAnomalies(1), loadReconciliationReport(), loadQueueDashboard()])
    activeTab.value = 'safety'
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Safety check gagal dijalankan.'
    noticeType.value = 'error'
  } finally {
    runningSafety.value = false
  }
}

const syncShopeeToTiktok = async () => {
  const confirmed = window.confirm('Sinkron real semua stok TikTok mengikuti Shopee sekarang? Aksi ini akan mengirim update stok ke TikTok.')
  if (!confirmed) return

  runningShopeeToTiktok.value = true
  notice.value = ''
  try {
    const { data } = await omnichannelService.syncAutoSyncShopeeToTiktok()
    const parts = [
      data.message || 'Sinkron Shopee ke TikTok selesai.',
      `Dicek: ${data.checked || 0}`,
      `Dikirim: ${data.pushed || 0}`,
      `Sama: ${data.unchanged || 0}`,
      `Dilewati: ${data.skipped || 0}`,
      `Nonaktif TikTok: ${data.skipped_inactive_tiktok || 0}`,
      `Stok Shopee kosong: ${data.skipped_missing_shopee_stock || 0}`,
      `Gagal: ${data.failed || 0}`
    ]
    notice.value = parts.join(' | ')
    noticeType.value = data.status === 'warning' || (data.failed || 0) > 0 ? 'error' : 'success'
    await Promise.all([loadDashboard(), loadSyncLogs(1), loadSafety(1), loadStockAnomalies(1), loadReconciliationReport(), loadQueueDashboard()])
    activeTab.value = 'sync'
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Sinkron Shopee ke TikTok gagal dijalankan.'
    noticeType.value = 'error'
  } finally {
    runningShopeeToTiktok.value = false
  }
}

const pollShopeeOrders = async () => {
  runningOrderPoll.value = true
  notice.value = ''
  try {
    const { data } = await omnichannelService.pollAutoSyncShopeeOrders(24)
    const parts = [
      data.message || 'Polling order Shopee selesai.',
      `Order baru diproses: ${data.processed || 0}`,
      `Berhasil: ${data.success || 0}`,
      `Sudah pernah diproses: ${data.already_processed || 0}`,
      `Dilewati karena data belum lengkap: ${data.skipped || 0}`,
      `Gagal: ${data.failed || 0}`
    ]
    notice.value = parts.join(' | ')
    noticeType.value = data.status === 'warning' || (data.failed || 0) > 0 ? 'error' : 'success'
    syncFilters.marketplace = 'shopee_order'
    orderFilters.type = 'shopee_order'
    orderFilters.order_class = ''
    await Promise.all([loadDashboard(), loadOrderSync(1), loadSyncLogs(1), loadSafety(1), loadStockAnomalies(1), loadOrderWatchdog(), loadQueueDashboard()])
    activeTab.value = 'order'
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Polling order Shopee gagal dijalankan.'
    noticeType.value = 'error'
  } finally {
    runningOrderPoll.value = false
  }
}

const pollTiktokOrders = async () => {
  runningTiktokOrderPoll.value = true
  notice.value = ''
  try {
    const { data } = await omnichannelService.pollAutoSyncTiktokOrders(24)
    const parts = [
      data.message || 'Polling order TikTok selesai.',
      `Order baru diproses: ${data.processed || 0}`,
      `Berhasil: ${data.success || 0}`,
      `Sudah pernah diproses: ${data.already_processed || 0}`,
      `Dilewati karena status belum mengubah stok: ${data.skipped || 0}`,
      `Gagal: ${data.failed || 0}`
    ]
    notice.value = parts.join(' | ')
    noticeType.value = data.status === 'warning' || (data.failed || 0) > 0 ? 'error' : 'success'
    syncFilters.marketplace = 'tiktok_order'
    orderFilters.type = 'tiktok_order'
    orderFilters.order_class = ''
    await Promise.all([loadDashboard(), loadOrderSync(1), loadSyncLogs(1), loadSafety(1), loadStockAnomalies(1), loadOrderWatchdog(), loadQueueDashboard()])
    activeTab.value = 'order'
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Polling order TikTok gagal dijalankan.'
    noticeType.value = 'error'
  } finally {
    runningTiktokOrderPoll.value = false
  }
}

const anomalyActionKey = (row, sourceMarketplace) => `${row.sku || '-'}:${sourceMarketplace}`

const syncStockAnomaly = async (row, sourceMarketplace) => {
  const key = anomalyActionKey(row, sourceMarketplace)
  runningAnomalyKey.value = key
  notice.value = ''
  try {
    const { data } = await omnichannelService.syncAutoSyncStockAnomaly({
      sku: row.sku,
      source_marketplace: sourceMarketplace
    })
    notice.value = data.message || 'Anomali stok selesai disinkronkan.'
    noticeType.value = data.status === 'error' ? 'error' : 'success'
    await Promise.all([loadDashboard(), loadStockAnomalies(1), loadSyncLogs(1), loadSafety(1), loadReconciliationReport(), loadQueueDashboard()])
    activeTab.value = 'anomaly'
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Anomali stok gagal disinkronkan.'
    noticeType.value = 'error'
  } finally {
    runningAnomalyKey.value = ''
  }
}

const clearBrowserAutoSyncTimer = () => {
  if (browserAutoSyncTimer) {
    window.clearTimeout(browserAutoSyncTimer)
    browserAutoSyncTimer = null
  }
}

const clearBrowserAutoSyncCountdown = () => {
  if (browserAutoSyncCountdownTimer) {
    window.clearInterval(browserAutoSyncCountdownTimer)
    browserAutoSyncCountdownTimer = null
  }
}

const updateBrowserAutoSyncCountdown = () => {
  if (!browserAutoSyncNextRun.value) {
    browserAutoSyncCountdownSeconds.value = 0
    return
  }

  browserAutoSyncCountdownSeconds.value = Math.max(0, Math.ceil((new Date(browserAutoSyncNextRun.value).getTime() - Date.now()) / 1000))
}

const startBrowserAutoSyncCountdown = () => {
  clearBrowserAutoSyncCountdown()
  updateBrowserAutoSyncCountdown()
  browserAutoSyncCountdownTimer = window.setInterval(updateBrowserAutoSyncCountdown, 1000)
}

const scheduleBrowserAutoSync = (delay = BROWSER_AUTO_SYNC_INTERVAL_MS) => {
  clearBrowserAutoSyncTimer()
  if (isBrowserAutoSyncLocked.value) return
  if (!browserAutoSyncEnabled.value) return

  browserAutoSyncNextRun.value = new Date(Date.now() + delay).toISOString()
  startBrowserAutoSyncCountdown()
  browserAutoSyncTimer = window.setTimeout(() => {
    runBrowserAutoSync()
  }, delay)
}

const tokenErrorText = (result) => {
  if (!result) return ''
  if (result.status === 'rejected') {
    const data = result.reason?.response?.data
    return JSON.stringify(data || result.reason?.message || '').toLowerCase()
  }

  return JSON.stringify(result.value?.data || {}).toLowerCase()
}

const hasTokenError = (result) => {
  const text = tokenErrorText(result)
  return text.includes('token') || text.includes('access_expired') || text.includes('expired') || text.includes('invalid access')
}

const refreshMarketplaceTokensFromBrowser = async (shopeeResult, tiktokResult) => {
  const actions = []
  if (hasTokenError(shopeeResult)) {
    actions.push(omnichannelService.runTokenAction('refresh-token-shopee-agnishopbjm'))
  }
  if (hasTokenError(tiktokResult)) {
    actions.push(omnichannelService.runTokenAction('refresh-token-tiktok-agnishopbjm'))
  }

  if (!actions.length) return null

  const results = await Promise.allSettled(actions)
  return {
    requested: actions.length,
    success: results.filter((item) => item.status === 'fulfilled' && (item.value?.data?.status === 'ok' || item.value?.data?.status === 'success')).length,
    failed: results.filter((item) => item.status === 'rejected' || item.value?.data?.status === 'error').length
  }
}

const runBrowserAutoSync = async () => {
  if (isBrowserAutoSyncLocked.value) {
    stopBrowserAutoSync()
    return
  }

  if (!browserAutoSyncEnabled.value || browserAutoSyncRunning.value) return

  browserAutoSyncRunning.value = true
  browserAutoSyncNextRun.value = null
  browserAutoSyncCountdownSeconds.value = 0
  try {
    let [shopeeResult, tiktokResult] = await Promise.allSettled([
      omnichannelService.pollAutoSyncShopeeOrders(24),
      omnichannelService.pollAutoSyncTiktokOrders(24)
    ])

    const tokenRefreshData = await refreshMarketplaceTokensFromBrowser(shopeeResult, tiktokResult)
    if (tokenRefreshData?.success) {
      ;[shopeeResult, tiktokResult] = await Promise.allSettled([
        omnichannelService.pollAutoSyncShopeeOrders(24),
        omnichannelService.pollAutoSyncTiktokOrders(24)
      ])
    }

    browserAutoSyncRunCount += 1
    if (browserAutoSyncRunCount % BROWSER_AUTO_SYNC_SAFETY_EVERY_RUNS === 0) {
      await omnichannelService.runAutoSyncSafetyCheck()
    }

    const shopeeData = shopeeResult.status === 'fulfilled' ? shopeeResult.value.data : null
    const tiktokData = tiktokResult.status === 'fulfilled' ? tiktokResult.value.data : null
    const failed = shopeeResult.status === 'rejected' || tiktokResult.status === 'rejected' || (shopeeData?.failed || 0) > 0 || (tiktokData?.failed || 0) > 0
    const retryData = failed ? (await omnichannelService.retryAutoSyncOpenIssues(5)).data : null

    browserAutoSyncLastRun.value = new Date().toISOString()
    notice.value = [
      'Auto Browser sync selesai.',
      `Shopee baru: ${shopeeData?.processed || 0}`,
      `TikTok baru: ${tiktokData?.processed || 0}`,
      `Gagal: ${(shopeeData?.failed || 0) + (tiktokData?.failed || 0)}`,
      tokenRefreshData ? `Token refresh: ${tokenRefreshData.success}/${tokenRefreshData.requested}` : null,
      retryData ? `Auto retry: ${retryData.success || 0}/${retryData.checked || 0}` : null
    ].filter(Boolean).join(' | ')
    noticeType.value = failed ? 'error' : 'success'

    await Promise.all([loadDashboard(), loadOrderSync(1), loadSyncLogs(1), loadSafety(1), loadStockAnomalies(1), loadOrderWatchdog(), loadReconciliationReport(), loadQueueDashboard()])
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Auto Browser sync gagal dijalankan.'
    noticeType.value = 'error'
  } finally {
    browserAutoSyncRunning.value = false
    scheduleBrowserAutoSync()
  }
}

const startBrowserAutoSync = () => {
  if (isBrowserAutoSyncLocked.value) {
    stopBrowserAutoSync()
    notice.value = 'Auto Browser dikunci oleh konfigurasi backend.'
    noticeType.value = 'error'
    return
  }

  browserAutoSyncEnabled.value = true
  window.localStorage.setItem(BROWSER_AUTO_SYNC_KEY, '1')
  runBrowserAutoSync()
}

const stopBrowserAutoSync = () => {
  browserAutoSyncEnabled.value = false
  window.localStorage.removeItem(BROWSER_AUTO_SYNC_KEY)
  browserAutoSyncNextRun.value = null
  browserAutoSyncCountdownSeconds.value = 0
  clearBrowserAutoSyncTimer()
  clearBrowserAutoSyncCountdown()
}

const toggleBrowserAutoSync = () => {
  if (isBrowserAutoSyncLocked.value) {
    stopBrowserAutoSync()
    notice.value = 'Auto Browser tidak aktif pada konfigurasi backend saat ini.'
    noticeType.value = 'error'
    return
  }

  if (browserAutoSyncEnabled.value) {
    stopBrowserAutoSync()
    notice.value = 'Auto Browser sync dimatikan.'
    noticeType.value = 'success'
    return
  }

  startBrowserAutoSync()
}

const exportOrderSync = async () => {
  exportingOrderSync.value = true
  notice.value = ''
  try {
    const response = await omnichannelService.exportAutoSyncOrderSync({ ...orderFilters })
    const blob = new Blob([response.data], { type: 'text/csv;charset=utf-8;' })
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `order-sync-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}.csv`
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
    notice.value = 'Export Order Sync CSV selesai.'
    noticeType.value = 'success'
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Export Order Sync CSV gagal.'
    noticeType.value = 'error'
  } finally {
    exportingOrderSync.value = false
  }
}

const openOrderSyncDetail = async (id) => {
  detailModal.open = true
  detailModal.loading = true
  detailModal.data = null
  try {
    const { data } = await omnichannelService.autoSyncOrderSyncDetail(id)
    detailModal.data = data
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Detail order sync gagal dimuat.'
    noticeType.value = 'error'
    detailModal.open = false
  } finally {
    detailModal.loading = false
  }
}

const closeOrderSyncDetail = () => {
  detailModal.open = false
  detailModal.loading = false
  detailModal.retrying = false
  detailModal.data = null
}

const retryOrderSyncDetail = async () => {
  const id = detailModal.data?.log?.id
  if (!id) return

  detailModal.retrying = true
  notice.value = ''
  try {
    const { data } = await omnichannelService.retryAutoSyncOrderSync(id)
    notice.value = data.message || 'Retry order sync selesai.'
    noticeType.value = data.status === 'warning' || (data.failed || 0) > 0 ? 'error' : 'success'
    await Promise.all([loadDashboard(), loadOrderSync(1), loadSyncLogs(1), loadOrderWatchdog(), loadQueueDashboard()])
    const refreshed = await omnichannelService.autoSyncOrderSyncDetail(id)
    detailModal.data = refreshed.data
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Retry order sync gagal.'
    noticeType.value = 'error'
  } finally {
    detailModal.retrying = false
  }
}

onMounted(async () => {
  await loadAll()
  await startRuntimeHeartbeat()
  stockAnomalyAutoRefreshTimer = setInterval(refreshStockAnomaliesIfVisible, STOCK_ANOMALY_AUTO_REFRESH_MS)
  document.addEventListener('visibilitychange', refreshStockAnomaliesIfVisible)
  if (isBrowserAutoSyncLocked.value) {
    window.localStorage.removeItem(BROWSER_AUTO_SYNC_KEY)
  } else if (window.localStorage.getItem(BROWSER_AUTO_SYNC_KEY) === '1') {
    startBrowserAutoSync()
  }
})

onBeforeUnmount(() => {
  clearInterval(stockAnomalyAutoRefreshTimer)
  document.removeEventListener('visibilitychange', refreshStockAnomaliesIfVisible)
  clearBrowserAutoSyncTimer()
  clearBrowserAutoSyncCountdown()
  if (runtimeHeartbeatTimer) window.clearInterval(runtimeHeartbeatTimer)
})
</script>

<style scoped>
.page-shell { margin-left:240px; padding:24px; color:#0f172a; }
.page-header { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin-bottom:18px; }
.page-header p { color:#64748b; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; }
.page-header h1 { font-size:28px; line-height:1.15; margin-top:4px; }
.header-actions { display:flex; gap:10px; flex-wrap:wrap; }
button { border:0; border-radius:6px; padding:9px 13px; font-weight:700; cursor:pointer; }
button:disabled { opacity:.6; cursor:not-allowed; }
.primary { background:#0f5fc7; color:#fff; }
.danger { background:#b91c1c; color:#fff; }
.ghost { background:#fff; color:#0f172a; border:1px solid #dbe3ef; }
.notice { border-radius:6px; padding:10px 12px; margin-bottom:14px; border:1px solid #bbf7d0; background:#f0fdf4; color:#166534; }
.notice.error { border-color:#fecaca; background:#fef2f2; color:#991b1b; }
.browser-auto-strip { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:10px; margin-bottom:14px; }
.browser-auto-strip div { display:flex; justify-content:space-between; align-items:center; gap:10px; background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; min-width:0; }
.browser-auto-strip span { color:#64748b; font-size:12px; font-weight:800; }
.browser-auto-strip strong:not(.badge) { color:#0f172a; font-size:13px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.stb-worker-strip { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:10px; margin-bottom:14px; }
.stb-worker-strip article { display:grid; gap:6px; background:#fff; border:1px solid #bbf7d0; border-radius:8px; padding:12px; min-width:0; }
.stb-worker-strip span { color:#166534; font-size:12px; font-weight:800; }
.stb-worker-strip strong:not(.badge) { color:#0f172a; font-size:13px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.runtime-strip { display:grid; grid-template-columns:1fr 1fr 1.2fr 1fr .8fr 1.8fr 2fr; gap:10px; margin-bottom:14px; }
.runtime-strip article { display:grid; gap:6px; background:#fff; border:1px solid #dbeafe; border-radius:8px; padding:12px; min-width:0; }
.runtime-strip span { color:#1d4ed8; font-size:12px; font-weight:800; }
.runtime-strip strong:not(.badge) { color:#0f172a; font-size:13px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.runtime-actions div { display:flex; gap:8px; flex-wrap:wrap; }
.runtime-actions button { padding:7px 9px; font-size:12px; }
.bridge-strip { display:grid; grid-template-columns:1fr 1fr 2fr 2fr auto; gap:10px; margin-bottom:14px; }
.bridge-strip article { display:grid; gap:6px; background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:12px; min-width:0; }
.bridge-strip span { color:#475569; font-size:12px; font-weight:800; }
.bridge-strip strong:not(.badge) { color:#0f172a; font-size:13px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.bridge-actions { align-content:center; }
.bridge-actions button { white-space:nowrap; }
.readiness-panel { border:1px solid #e2e8f0; border-radius:8px; padding:12px; margin-bottom:12px; background:#fff; }
.readiness-panel header { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:10px; }
.readiness-panel header span { display:block; color:#64748b; font-size:12px; font-weight:800; margin-bottom:3px; }
.readiness-panel header strong { font-size:16px; }
.readiness-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:10px; }
.readiness-grid article { border:1px solid #e2e8f0; border-radius:6px; padding:10px; background:#f8fafc; }
.readiness-grid article strong { display:block; margin-top:8px; font-size:13px; }
.readiness-grid article p { margin:6px 0 0; color:#475569; font-size:12px; line-height:1.35; }
.status-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:14px; }
.status-card,.panel { background:#fff; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 1px 2px rgba(15,23,42,.05); }
.status-card { padding:16px; }
.alert-stack { display:grid; gap:10px; margin-bottom:14px; }
.alert-card { display:grid; grid-template-columns:260px 1fr; gap:12px; align-items:start; border-radius:8px; padding:12px; border:1px solid #fde68a; background:#fffbeb; color:#78350f; }
.alert-card.error { border-color:#fecaca; background:#fef2f2; color:#991b1b; }
.alert-card div { display:grid; gap:3px; }
.alert-card strong { font-size:13px; }
.alert-card span { font-size:12px; color:inherit; opacity:.78; }
.alert-card p { margin:0; font-size:13px; line-height:1.45; overflow-wrap:anywhere; }
.card-head { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:14px; }
.card-head span { color:#475569; font-weight:800; }
.badge { display:inline-flex; align-items:center; border-radius:999px; padding:4px 9px; font-size:12px; font-weight:800; text-transform:capitalize; }
.badge.success { background:#dcfce7; color:#166534; }
.badge.error { background:#fee2e2; color:#991b1b; }
.badge.neutral { background:#e2e8f0; color:#334155; }
dl { display:grid; gap:10px; }
dt { color:#64748b; font-size:12px; }
dd { color:#0f172a; font-size:14px; font-weight:800; margin-top:2px; }
.webhook-strip { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; margin-bottom:14px; }
.webhook-strip div { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:12px; min-width:0; }
.webhook-strip span { display:block; color:#64748b; font-size:12px; font-weight:800; margin-bottom:6px; }
.webhook-strip code { display:block; overflow:auto; color:#0f172a; font-size:12px; white-space:nowrap; }
.issue-banner { display:grid; grid-template-columns:220px 1fr; gap:12px; align-items:start; border:1px solid #fed7aa; background:#fff7ed; color:#7c2d12; border-radius:8px; padding:12px; margin-bottom:12px; }
.issue-banner span { display:block; font-size:12px; font-weight:800; color:#9a3412; margin-bottom:4px; }
.issue-banner strong { font-size:13px; }
.issue-banner p { margin:0; font-size:13px; line-height:1.45; overflow-wrap:anywhere; }
.tabs { display:flex; gap:8px; margin:14px 0; border-bottom:1px solid #e2e8f0; flex-wrap:wrap; }
.tabs button { background:transparent; color:#475569; border-radius:6px 6px 0 0; border:1px solid transparent; }
.tabs button.active { background:#fff; color:#0f172a; border-color:#e2e8f0; border-bottom-color:#fff; margin-bottom:-1px; }
.panel { padding:14px; }
.filter-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:10px; margin-bottom:12px; }
.segmented { display:flex; gap:6px; border:1px solid #cbd5e1; border-radius:6px; padding:3px; background:#f8fafc; min-width:0; }
.segmented button { flex:1; padding:7px 9px; border-radius:4px; background:transparent; color:#475569; font-size:12px; white-space:nowrap; }
.segmented button.active { background:#0f5fc7; color:#fff; }
.anomaly-filter-row { grid-template-columns:minmax(190px,260px) minmax(240px,1fr) auto; align-items:stretch; }
.compact-filter-row { grid-template-columns:160px 160px auto; align-items:stretch; }
.table-actions { display:flex; justify-content:flex-end; margin-bottom:12px; }
select,input { width:100%; border:1px solid #cbd5e1; border-radius:6px; padding:9px 10px; background:#fff; }
.table-wrap { overflow:auto; border:1px solid #e2e8f0; border-radius:6px; }
.clickable-row { cursor:pointer; }
.clickable-row:hover td { background:#f8fafc; }
table { width:100%; border-collapse:collapse; font-size:13px; min-width:900px; }
th,td { padding:10px 12px; border-bottom:1px solid #edf2f7; text-align:left; vertical-align:top; }
th { background:#f8fafc; color:#475569; font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
td { color:#0f172a; }
.cell-note { margin:6px 0 0; color:#64748b; font-size:12px; line-height:1.35; }
.row-actions { display:flex; gap:8px; flex-wrap:wrap; min-width:220px; }
.row-actions button { padding:7px 9px; font-size:12px; }
.empty { text-align:center; color:#64748b; padding:22px; }
.pagination { display:flex; justify-content:flex-end; align-items:center; gap:10px; margin-top:12px; color:#475569; font-size:13px; }
.safety-summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:10px; margin-bottom:12px; }
.safety-summary div { border:1px solid #e2e8f0; border-radius:6px; padding:12px; background:#f8fafc; }
.safety-summary span { display:block; color:#64748b; font-size:12px; margin-bottom:4px; }
.safety-summary strong { font-size:15px; }
.order-summary { margin-bottom:12px; }
.modal-backdrop { position:fixed; inset:0; z-index:50; background:rgba(15,23,42,.45); display:flex; justify-content:center; align-items:flex-start; padding:48px 18px; overflow:auto; }
.detail-modal { width:min(1120px,100%); background:#fff; border-radius:8px; box-shadow:0 24px 80px rgba(15,23,42,.22); padding:18px; }
.modal-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; border-bottom:1px solid #e2e8f0; padding-bottom:12px; margin-bottom:14px; }
.modal-head p { color:#64748b; font-size:12px; font-weight:800; text-transform:uppercase; margin:0 0 4px; }
.modal-head h2 { font-size:20px; margin:0; }
.modal-actions { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
.detail-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin-bottom:14px; }
.detail-grid div { border:1px solid #e2e8f0; border-radius:6px; padding:10px; background:#f8fafc; }
.detail-grid span { display:block; color:#64748b; font-size:12px; margin-bottom:4px; }
.detail-grid strong { font-size:14px; }
.detail-modal h3 { font-size:15px; margin:16px 0 10px; }
.detail-items { display:grid; gap:10px; }
.detail-items article { display:grid; grid-template-columns:64px 1fr; gap:12px; border:1px solid #e2e8f0; border-radius:6px; padding:10px; }
.detail-items img { width:64px; height:64px; object-fit:cover; border-radius:6px; background:#f1f5f9; }
.detail-items strong { display:block; margin-bottom:5px; }
.detail-items span { display:block; color:#475569; font-size:12px; margin-top:2px; }
.detail-table table { min-width:980px; }
@media (max-width:1360px) { .status-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (max-width:1180px) { .status-grid,.safety-summary,.webhook-strip,.browser-auto-strip,.stb-worker-strip,.runtime-strip,.bridge-strip { grid-template-columns:1fr; } }
@media (max-width:820px) { .page-shell { margin-left:0; padding:16px; } .page-header,.header-actions,.modal-head,.modal-actions { flex-direction:column; align-items:stretch; } .filter-row,.issue-banner,.alert-card,.detail-grid,.detail-items article,.anomaly-filter-row,.compact-filter-row { grid-template-columns:1fr; } }
</style>
