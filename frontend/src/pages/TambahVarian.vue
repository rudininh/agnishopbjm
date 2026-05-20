<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>{{ pageEyebrow }}</p>
        <h1>{{ pageTitle }}</h1>
        <small class="subtitle">{{ pageSubtitle }}</small>
      </div>
      <div class="header-actions" v-if="selectedItem && canPrepareMissingVariant(selectedItem)">
        <button
          type="button"
          class="primary"
          :disabled="preparing"
          @click="prepareMissingVariant(selectedItem)"
        >
          {{ preparing ? 'Menyiapkan...' : `Buat Varian Hilang di ${missingTargetChannel(selectedItem) === 'tiktok' ? 'TikTok' : 'Shopee'}` }}
        </button>
      </div>
    </header>

    <p v-if="loadError" class="notice error">{{ loadError }}</p>
    <p v-else-if="loading && !items.length" class="notice">Memuat data etalase uji...</p>

    <div class="control-band">
      <label>
        <span>Nama etalase uji</span>
        <input v-model.trim="filters.search" type="search" placeholder="Cari etalase / produk (opsional)" @keyup.enter="loadData(true)" />
      </label>
      <button type="button" class="ghost" @click="loadData(true)" :disabled="loading">Muat etalase ini</button>
    </div>

    <div class="summary-grid" v-if="summaryItem">
      <article class="metric">
        <span>Item id dan Model id Shopee</span>
        <strong>{{ summaryItem.shopee?.item_id || '-' }}</strong>
        <small>Model ID: {{ summaryItem.shopee?.model_id || '-' }}</small>
      </article>
      <article class="metric">
        <span>Product ID TikTok</span>
        <strong>{{ resolveSelectedTiktokProductId() || '-' }}</strong>
        <small>{{ summaryItem.tiktok?.variant_name || summaryItem.variant_name || '-' }}</small>
      </article>
    </div>

    <div class="layout list-only">
      <div class="panel list-panel">
        <div class="group-head" v-if="activeGroup">
          <div class="group-title">
            <img v-if="activeGroup.image_url" :src="activeGroup.image_url" class="thumb large" :alt="activeGroup.name" />
            <div v-else class="thumb large fallback">{{ initials(activeGroup.name) }}</div>
            <div>
              <strong>{{ activeGroup.name }}</strong>
              <small>Shopee: {{ activeGroup.shopee.present }} varian, stok {{ activeGroup.shopee.total_stock }}</small>
              <small>TikTok: {{ activeGroup.tiktok.present }} varian, stok {{ activeGroup.tiktok.total_stock }}</small>
            </div>
          </div>
          <span :class="['badge', activeGroup.status]">{{ labelStatus(activeGroup.status) }}</span>
        </div>
        <div v-else class="group-empty">
          Tidak ada produk untuk filter yang dipilih.
        </div>

        <div class="table-filters">
          <label>
            <span>Arah tabel</span>
            <select v-model="filters.flow" @change="loadData(true)">
              <option v-for="option in tableFlowOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
          </label>
          <label>
            <span>Status</span>
            <select v-model="filters.status" @change="loadData(true)">
              <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
          </label>
          <div class="filter-hint">{{ tableFlowHint }} Filter ini berlaku untuk data tabel dan pagination di bawahnya.</div>
        </div>

        <div class="table-wrap">
          <table class="mapping-table">
            <colgroup>
              <col class="col-product" />
              <col class="col-channel" />
              <col class="col-channel" />
              <col class="col-status" />
              <col class="col-check" />
            </colgroup>
            <thead>
              <tr>
                <th>Varian</th>
                <th>Shopee</th>
                <th>TikTok</th>
                <th>Status</th>
                <th class="check-col">
                  <input
                    type="checkbox"
                    :checked="allDisplayedVariantsSelected"
                    :indeterminate="someDisplayedVariantsSelected && !allDisplayedVariantsSelected"
                    aria-label="Pilih semua varian"
                    @click.stop
                    @change="toggleDisplayedVariantsSelection($event.target.checked)"
                  />
                </th>
              </tr>
            </thead>
            <tbody v-if="!groupedItems.length">
              <tr>
                <td colspan="5" class="empty-row">Tidak ada produk untuk filter yang dipilih.</td>
              </tr>
            </tbody>
            <template v-for="group in groupedItems" :key="group.key">
              <tbody>
                <tr class="product-row">
                  <td colspan="4">
                    <div class="product-row-inner" @click="toggleGroup(group.key)">
                      <button type="button" class="expand group-toggle" @click.stop="toggleGroup(group.key)">
                        {{ isExpanded(group.key) ? '−' : '+' }}
                      </button>
                      <img v-if="group.image_url" :src="group.image_url" class="thumb small" :alt="group.name" />
                      <div v-else class="thumb small fallback">{{ initials(group.name) }}</div>
                      <div class="group-copy">
                        <strong>{{ group.name }}</strong>
                        <small>Shopee: {{ group.shopee.present }} varian, stok {{ group.shopee.total_stock }}</small>
                        <small>TikTok: {{ group.tiktok.present }} varian, stok {{ group.tiktok.total_stock }}</small>
                      </div>
                      <div class="group-meta">
                        <strong>{{ group.variants.length }} varian</strong>
                        <span :class="['badge', group.status]">{{ labelStatus(group.status) }}</span>
                      </div>
                    </div>
                  </td>
                  <td class="check-col group-check-col">
                    <input
                      type="checkbox"
                      :checked="isGroupVariantsSelected(group)"
                      :indeterminate="isGroupVariantsPartiallySelected(group) && !isGroupVariantsSelected(group)"
                      aria-label="Pilih semua varian pada produk ini"
                      @click.stop
                      @change="toggleGroupVariantSelection(group, $event.target.checked)"
                    />
                  </td>
                </tr>
                <tr
                  v-for="item in group.variants"
                  v-show="isExpanded(group.key)"
                  :key="item.id"
                  :class="['variant-row', { active: selectedItem?.id === item.id }]"
                  @click="selectItem(item)"
                >
                  <td>
                    <div class="variant-title">
                      <strong>{{ item.variant_name || 'Tanpa Varian' }}</strong>
                      <small>SKU internal: {{ item.internal_sku }} | Stock Master: {{ item.stock_qty }}</small>
                    </div>
                  </td>
                  <td>
                    <div class="channel-cell">
                      <img v-if="item.shopee?.image_url" :src="item.shopee.image_url" class="thumb" :alt="item.shopee.variant_name" />
                      <div v-else class="thumb fallback">SP</div>
                      <div>
                        <small>{{ shopeePresenceLabel(item) }}</small>
                        <small>Item/Model: {{ item.shopee?.item_id || '-' }} / {{ item.shopee?.model_id || '-' }}</small>
                        <small>Kode/Stok: {{ item.shopee?.seller_sku || item.seller_sku || '-' }} / {{ displayStock(item.shopee?.stock_qty) }}</small>
                        <small v-if="hasPrice(item.shopee?.price ?? item.shopee_variant_price)">Harga: {{ displayPrice(item.shopee?.price ?? item.shopee_variant_price) }}</small>
                      </div>
                    </div>
                  </td>
                  <td>
                    <div :class="['channel-cell', { muted: !hasTiktok(item) }]">
                      <img v-if="item.tiktok?.image_url" :src="item.tiktok.image_url" class="thumb" :alt="item.tiktok.variant_name" />
                      <div v-else class="thumb fallback">TT</div>
                      <div>
                        <small>{{ tiktokPresenceLabel(item) }}</small>
                        <small>Product/SKU: {{ hasTiktokActual(item) ? `${item.tiktok?.product_id || '-'} / ${item.tiktok?.sku_id || '-'}` : hasTiktokProductCandidate(item) ? `${item.tiktok?.product_id || '-'} / -` : '-' }} | Kode/Stok: {{ item.tiktok?.seller_sku || item.seller_sku || '-' }} / {{ hasTiktokActual(item) ? displayStock(item.tiktok?.stock_qty) : '-' }}</small>
                        <small v-if="hasPrice(item.tiktok?.price)">Harga: {{ displayPrice(item.tiktok?.price) }}</small>
                      </div>
                    </div>
                  </td>
                  <td>
                    <span :class="['badge', item.status]">{{ labelStatus(item.status) }}</span>
                  </td>
                  <td class="check-col">
                    <input
                      type="checkbox"
                      :checked="isVariantSelected(item)"
                      aria-label="Pilih varian ini"
                      @click.stop
                      @change="toggleVariantSelection(item, $event.target.checked)"
                    />
                  </td>
                </tr>
              </tbody>
            </template>
          </table>
        </div>

        <div class="pagination" v-if="pagination.last_page > 1">
          <button type="button" class="ghost" @click="changePage(pagination.page - 1)" :disabled="loading || pagination.page <= 1">Prev</button>
          <span>Halaman {{ pagination.page }} / {{ pagination.last_page }} | {{ pagination.total }} data</span>
          <button type="button" class="ghost" @click="changePage(pagination.page + 1)" :disabled="loading || pagination.page >= pagination.last_page">Next</button>
        </div>

        <div class="api-panel">
          <div class="api-panel-head">
            <div>
              <strong>API Testing Tool</strong>
              <small>Quickly obtain real request parameters and response parameters through this tool. <a href="#" @click.prevent>View guide.</a></small>
            </div>
          </div>
          <div class="api-testing-tool">
            <div class="api-left">
              <div class="api-apirow">
                <div class="api-label">API name</div>
                <div class="api-apiname">
                  <div>
                    <strong>{{ apiTestingTitle }}</strong>
                    <small>{{ apiTestingPath }}</small>
                  </div>
                  <span class="api-chip">GET</span>
                </div>
              </div>

              <div v-if="isShopeeFlow" class="api-version-row">
                <label class="api-inline">
                  <span>Endpoint</span>
                  <select v-model="shopeeProductTool.api_name">
                    <option value="get_item_base_info">get_item_base_info</option>
                    <option value="get_model_list">get_model_list</option>
                  </select>
                </label>
                <a href="#" class="api-doc-link" @click.prevent>View API doc</a>
              </div>
              <div v-else class="api-version-row">
                <label class="api-inline">
                  <span>Version</span>
                  <select v-model="getProductTool.version">
                    <option value="202309">202309</option>
                  </select>
                </label>
                <a href="#" class="api-doc-link" @click.prevent>View API doc</a>
              </div>

              <div class="api-section-title">Authorized shop info</div>
              <div v-if="isShopeeFlow" class="api-shop-auth">
                <a href="#" class="api-shop-link" @click.prevent="loadShopeeApiTestContext">Pakai token Shopee aktif</a>
                <label>
                  <span>account_key (string) (Optional)</span>
                  <input v-model.trim="shopeeProductTool.account_key" placeholder="shopee-agnishopbjm" />
                </label>
                <label>
                  <span>shop_id (int)</span>
                  <input v-model.trim="shopeeProductTool.shop_id" placeholder="7495811028690242494" />
                </label>
                <label>
                  <span>access_token (string)</span>
                  <input v-model.trim="shopeeProductTool.access_token" placeholder="Token Shopee aktif dari database" />
                </label>
              </div>
              <div v-else class="api-shop-auth">
                <a href="#" class="api-shop-link" @click.prevent="loadGetProductContext">Get shop authorization</a>
                <label>
                  <span>shop_id (string) (Optional)</span>
                  <input v-model.trim="getProductTool.shop_id" placeholder="7495811028690242494" />
                </label>
                <label>
                  <span>shop_cipher (string) (Optional)</span>
                  <input v-model.trim="getProductTool.shop_cipher" placeholder="ROW_AfAcCgAAAA..." />
                </label>
                <label>
                  <span>access_token (string)</span>
                  <input v-model.trim="getProductTool.access_token" placeholder="ROW_2ZAVYAAAA..." />
                </label>
                <label>
                  <span>Version (string)</span>
                  <input :value="getProductTool.version" disabled />
                </label>
              </div>

              <div class="api-section-title">Path parameters</div>
              <div class="api-path-params">
                <label v-if="isShopeeFlow">
                  <span>{{ shopeeProductTool.api_name === 'get_item_base_info' ? 'item_id_list (int64, comma separated)' : 'item_id (int64)' }}</span>
                  <input v-model.trim="shopeeProductTool.item_id" />
                </label>
                <label v-else>
                  <span>product_id (string)</span>
                  <input v-model.trim="getProductTool.product_id" />
                </label>
              </div>

              <div v-if="isShopeeFlow" class="api-section-title">Query request parameters</div>
              <div v-if="isShopeeFlow" class="api-query-params">
                <template v-if="shopeeProductTool.api_name === 'get_item_base_info'">
                  <div class="api-bool-row">
                    <span>need_tax_info (boolean) (Optional)</span>
                    <div class="api-radio-group">
                      <label><input type="radio" value="true" v-model="shopeeProductTool.need_tax_info" /> true</label>
                      <label><input type="radio" value="false" v-model="shopeeProductTool.need_tax_info" /> false</label>
                    </div>
                  </div>
                  <div class="api-bool-row">
                    <span>need_complaint_policy (boolean) (Optional)</span>
                    <div class="api-radio-group">
                      <label><input type="radio" value="true" v-model="shopeeProductTool.need_complaint_policy" /> true</label>
                      <label><input type="radio" value="false" v-model="shopeeProductTool.need_complaint_policy" /> false</label>
                    </div>
                  </div>
                </template>
                <p v-else class="field-hint">Endpoint ini hanya butuh item_id.</p>
              </div>

              <div v-if="!isShopeeFlow" class="api-section-title">Query request parameters</div>
              <div v-if="!isShopeeFlow" class="api-query-params">
                <div class="api-bool-row">
                  <span>return_under_review_version (boolean) (Optional)</span>
                  <div class="api-radio-group">
                    <label><input type="radio" value="true" v-model="getProductTool.return_under_review_version" /> true</label>
                    <label><input type="radio" value="false" v-model="getProductTool.return_under_review_version" /> false</label>
                  </div>
                </div>
                <div class="api-bool-row">
                  <span>return_draft_version (boolean) (Optional)</span>
                  <div class="api-radio-group">
                    <label><input type="radio" value="true" v-model="getProductTool.return_draft_version" /> true</label>
                    <label><input type="radio" value="false" v-model="getProductTool.return_draft_version" /> false</label>
                  </div>
                </div>
                <label>
                  <span>locale (string) (Optional)</span>
                  <input v-model.trim="getProductTool.locale" />
                </label>
              </div>

              <div class="api-submit-row">
                <button type="button" class="primary" @click="submitGetProductDemo" :disabled="getProductRequestBusy">
                  {{ getProductRequestBusy ? 'Submitting...' : 'Submit Request' }}
                </button>
              </div>
            </div>

            <div class="api-right">
              <div class="api-right-block">
                <div class="api-right-head">
                  <strong>Request demo (cURL)</strong>
                  <button class="ghost mini" type="button" @click="copyGetProductCurl">Copy</button>
                </div>
                <pre>{{ apiGetProductCurl }}</pre>
              </div>
              <div class="api-right-block">
                <div class="api-right-head">
                  <strong>Response</strong>
                  <button class="ghost mini" type="button" @click="copyGetProductResponse">Copy</button>
                </div>
                <div class="api-status-line">
                  <span>Status:</span>
                  <span class="status-dot">{{ getProductResponseStatus }}</span>
                </div>
                <p v-if="apiGetProductResponseHint" class="api-response-hint">{{ apiGetProductResponseHint }}</p>
                <div class="api-response-viewer">
                  <div class="api-response-lines">
                    <span v-for="line in apiGetProductResponseLines" :key="line.no">{{ line.no }}</span>
                  </div>
                  <pre>{{ getProductResponseText || ' ' }}</pre>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="api-panel variant-panel">
          <div class="api-panel-head">
            <div>
              <strong>{{ addVariantPanelTitle }}</strong>
              <small>{{ addVariantPanelSubtitle }}</small>
            </div>
          </div>
          <div class="variant-tool">
            <div class="api-left">
              <div class="api-section-title">Target product</div>
              <div class="api-path-params">
                <label>
                  <span>{{ isShopeeFlow ? 'item_id Shopee (int64)' : 'product_id (string)' }}</span>
                  <input v-model.trim="addVariantTool.product_id" />
                </label>
                <label v-if="!isShopeeFlow">
                  <span>shop_cipher (string)</span>
                  <input v-model.trim="addVariantTool.shop_cipher" placeholder="ROW_AfAcCgAAAA..." />
                </label>
              </div>

              <div class="api-section-title">SKU baru</div>
              <div class="api-query-params">
                <label>
                  <span>seller_sku</span>
                  <input v-model.trim="addVariantTool.seller_sku" placeholder="SKU-BARU-001" />
                </label>
                <label>
                  <span>color_name</span>
                  <input v-model.trim="addVariantTool.color_name" placeholder="Biru" />
                </label>
                <label>
                  <span>image_uri</span>
                  <input v-model.trim="addVariantTool.image_uri" placeholder="tos-maliva-.../..." />
                </label>
                <label>
                  <span>price</span>
                  <input v-model.trim="addVariantTool.price" placeholder="50000" />
                </label>
                <label v-if="!isShopeeFlow">
                  <span>discount (%)</span>
                  <input v-model.number="addVariantTool.discount" type="number" min="0" max="100" step="0.01" placeholder="10" />
                </label>
                <p v-if="!isShopeeFlow" class="field-hint">Diskon ini akan diterapkan ke semua SKU yang dipilih.</p>
                <p class="field-hint">{{ variantPriceHint }}</p>
                <label>
                  <span>quantity</span>
                  <input v-model.number="addVariantTool.quantity" type="number" min="0" />
                </label>
                <label class="dry-run-row">
                  <input type="checkbox" v-model="addVariantTool.dry_run" />
                  <span>{{ isShopeeFlow ? 'Dry run saja, jangan kirim ke Shopee' : 'Dry run saja, jangan PUT ke TikTok' }}</span>
                </label>
              </div>

              <div class="variant-selection-summary">
                <div class="variant-selection-head">
                  <div>
                    <strong>Varian terpilih</strong>
                    <small v-if="selectedVariantItems.length">{{ selectedVariantItems.length }} varian akan masuk ke payload</small>
                    <small v-else>Pilih satu atau banyak baris checkbox di tabel kiri.</small>
                  </div>
                  <button type="button" class="ghost mini" @click="clearSelectedVariants" :disabled="!selectedVariantItems.length">Clear</button>
                </div>
                <div v-if="selectedVariantItems.length" class="variant-selection-list">
                  <div v-for="variant in selectedVariantItems" :key="variant.id" class="variant-selection-item">
                    <div class="variant-selection-copy">
                      <strong>{{ variant.variant_name || 'Tanpa Varian' }}</strong>
                      <small>SKU: {{ variant.seller_sku || variant.internal_sku || '-' }}</small>
                      <small>Stok: {{ displayStock(isShopeeFlow ? (variant.tiktok?.stock_qty ?? variant.stock_qty) : (variant.shopee?.stock_qty ?? variant.stock_qty)) }}</small>
                      <small v-if="isShopeeFlow && hasPrice(variant.tiktok?.price)">Harga: {{ displayPrice(variant.tiktok.price) }}</small>
                    </div>
                    <button type="button" class="ghost mini" @click="toggleVariantSelection(variant, false)">Hapus</button>
                  </div>
                </div>
              </div>

              <div class="api-submit-row">
                <button type="button" class="ghost" @click="loadAddVariantContext" :disabled="addVariantBusy">Ambil Context</button>
                <button type="button" class="primary" @click="submitAddVariant" :disabled="addVariantBusy">
                  {{ addVariantBusy ? 'Processing...' : addVariantSubmitLabel }}
                </button>
                <button v-if="!isShopeeFlow" type="button" class="ghost" @click="openTiktokSubmitDialog" :disabled="addVariantBusy || tiktokSubmitBusy || !resolveAddVariantProductId()">
                  {{ tiktokSubmitBusy ? 'Mengirim...' : 'Submit ke TikTok' }}
                </button>
              </div>
            </div>

            <div class="api-right">
              <div class="api-right-block">
                <div class="api-right-head">
                  <strong>Request demo</strong>
                  <button class="ghost mini" type="button" @click="copyAddVariantRequest">Copy</button>
                </div>
                <pre>{{ addVariantRequestPreview }}</pre>
              </div>
              <div class="api-right-block">
                <div class="api-right-head">
                  <strong>Ringkasan Varian</strong>
                  <button class="ghost mini" type="button" @click="clearSelectedVariants" :disabled="!selectedVariantItems.length">Clear</button>
                </div>
                <p class="api-response-hint">
                  {{ selectedVariantItems.length ? `Payload akan berisi ${selectedVariantItems.length} SKU baru.` : 'Belum ada checkbox varian yang dipilih.' }}
                </p>
                <div v-if="selectedVariantItems.length" class="variant-selection-list compact">
                  <div v-for="variant in selectedVariantItems" :key="`summary-${variant.id}`" class="variant-selection-item">
                    <div class="variant-selection-copy">
                      <strong>{{ variant.variant_name || 'Tanpa Varian' }}</strong>
                      <small>{{ variant.seller_sku || variant.internal_sku || '-' }}</small>
                    </div>
                  </div>
                </div>
              </div>
              <div class="api-right-block">
                <div class="api-right-head">
                  <strong>{{ isShopeeFlow ? 'Response Shopee' : 'Response Generate Payload' }}</strong>
                  <button class="ghost mini" type="button" @click="copyAddVariantResponse">Copy</button>
                </div>
                <p v-if="selectedVariantPayloadLabels.length" class="api-response-hint payload-summary-hint">
                  SKU baru yang di-append: {{ selectedVariantPayloadLabels.join(', ') }}
                </p>
                <div class="api-status-line">
                  <span>Status:</span>
                  <span class="status-dot">{{ addVariantResponseStatus }}</span>
                </div>
                <div class="api-response-viewer">
                  <div class="api-response-lines">
                    <span v-for="line in addVariantResponseLines" :key="line.no">{{ line.no }}</span>
                  </div>
                  <pre>{{ addVariantResponseText || ' ' }}</pre>
                </div>
              </div>
              <div v-if="!isShopeeFlow" class="api-right-block tiktok-response-block">
                <div class="api-right-head">
                  <strong>Response TikTok</strong>
                  <button class="ghost mini" type="button" @click="copyTiktokSubmitResponse">Copy</button>
                </div>
                <div class="api-status-line">
                  <span>Status:</span>
                  <span class="status-dot">{{ tiktokSubmitResponseStatus || '-' }}</span>
                </div>
                <p v-if="tiktokSubmitResponseHint" class="api-response-hint">{{ tiktokSubmitResponseHint }}</p>
                <div class="api-response-viewer">
                  <div class="api-response-lines">
                    <span v-for="line in tiktokSubmitResponseLines" :key="line.no">{{ line.no }}</span>
                  </div>
                  <pre>{{ tiktokSubmitResponseText || ' ' }}</pre>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>

    <div v-if="tiktokSubmitDialogOpen" class="dialog-backdrop" @click.self="closeTiktokSubmitDialog">
      <div class="dialog-card" role="dialog" aria-modal="true" aria-labelledby="tiktok-submit-dialog-title">
        <div class="dialog-header">
          <div>
            <p>Konfirmasi TikTok</p>
            <h3 id="tiktok-submit-dialog-title">Yakin submit payload ini ke TikTok?</h3>
          </div>
          <button class="ghost mini" type="button" @click="closeTiktokSubmitDialog" :disabled="tiktokSubmitBusy">Tutup</button>
        </div>
        <p class="dialog-copy">
          Tombol ini akan menjalankan request PUT ke TikTok memakai payload yang sama persis seperti hasil Generate Payload.
        </p>
        <dl class="dialog-meta">
          <div>
            <dt>Product ID</dt>
            <dd>{{ pendingTiktokSubmitMeta.product_id || '-' }}</dd>
          </div>
          <div>
            <dt>Version</dt>
            <dd>{{ pendingTiktokSubmitMeta.version || '-' }}</dd>
          </div>
          <div>
            <dt>Shop Cipher</dt>
            <dd>{{ pendingTiktokSubmitMeta.shop_cipher || '-' }}</dd>
          </div>
        </dl>
        <div class="dialog-actions">
          <button class="ghost" type="button" @click="closeTiktokSubmitDialog" :disabled="tiktokSubmitBusy">Tidak</button>
          <button class="primary" type="button" @click="confirmSubmitTiktokPayload" :disabled="tiktokSubmitBusy || !pendingTiktokSubmitPayload">
            {{ tiktokSubmitBusy ? 'Mengirim ke TikTok...' : 'Ya, Submit' }}
          </button>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { omnichannelService } from '@/services'

const DEFAULT_PRODUCT_NAME = 'Azara Hijab Segi Empat Polos Paris Packing Pouch Metal Logo'
const DEFAULT_API_PRODUCT_ID = '1732272903733872574'
const TIKTOK_GET_PRODUCT_VERSION = '202309'
const TIKTOK_SUBMIT_VERSION = '202509'
const TIKTOK_VARIANT_ATTRIBUTE_ID = '100000'
const TIKTOK_VARIANT_ATTRIBUTE_NAME = 'Warna'
const TIKTOK_VARIANT_WAREHOUSE_ID = '7395901885692495617'

const loading = ref(false)
const saving = ref(false)
const preparing = ref(false)
const actionBusy = ref(false)
const tiktokSubmitBusy = ref(false)
const tiktokSubmitDialogOpen = ref(false)
const pendingTiktokSubmitPayload = ref('')
const pendingTiktokSubmitMeta = reactive({
  product_id: '',
  version: '',
  shop_cipher: ''
})
const loadError = ref('')
const actionLog = ref('')
const items = ref([])
const pagination = reactive({
  page: 1,
  per_page: 50,
  total: 0,
  last_page: 1
})
const selectedItem = ref(null)
const expandedGroups = ref({})
const selectedVariantIds = ref([])
const selectedVariantSnapshots = reactive({})
const getProductRequestBusy = ref(false)
const getProductResponseText = ref('')
const getProductResponseStatus = ref('0')
const addVariantBusy = ref(false)
const addVariantResponseText = ref('')
const addVariantResponseStatus = ref('0')
const tiktokSubmitResponseText = ref('')
const tiktokSubmitResponseStatus = ref('')
const tiktokSubmitResponseHint = ref('')
const route = useRoute()
const isShopeeFlow = computed(() => route.meta?.flow === 'tiktok-to-shopee')
const pageEyebrow = computed(() => isShopeeFlow.value ? 'Eksperimen Shopee' : 'Eksperimen TikTok')
const pageTitle = computed(() => isShopeeFlow.value ? 'Tambah Varian Shopee' : 'Tambah Varian TikTok')
const pageSubtitle = computed(() => isShopeeFlow.value
  ? 'Halaman uji TikTok ke Shopee: create / update / mapping varian.'
  : 'Halaman uji TikTok untuk satu etalase: create / update / mapping varian.')
const addVariantPanelTitle = computed(() => isShopeeFlow.value ? 'Tambah Variant/SKU Shopee' : 'Tambah Variant/SKU TikTok')
const addVariantPanelSubtitle = computed(() => isShopeeFlow.value
  ? 'Klik baris/checkbox varian TikTok yang belum ada di Shopee, lalu kirim sebagai model Shopee baru.'
  : 'Workflow aman: klik baris berstatus belum ada variant untuk autofill data Shopee, lalu GET product terbaru, normalize payload edit, append 1 SKU baru, lalu copy payload untuk PUT.')
const variantPriceHint = computed(() => isShopeeFlow.value
  ? `Harga Shopee diambil dari harga TikTok per SKU. Fallback manual: ${displayPrice(addVariantTool.price)}.`
  : `Harga final per SKU: ${displayPrice(applyDiscountToPrice(addVariantTool.price, addVariantTool.discount))}`)
const addVariantSubmitLabel = computed(() => {
  if (isShopeeFlow.value) {
    return addVariantTool.dry_run ? 'Cek Payload Shopee' : 'Tambah ke Shopee'
  }

  return 'Generate Payload'
})
const statusOptions = computed(() => {
  const primaryMissingStatus = isShopeeFlow.value
    ? { value: 'shopee_missing', label: 'Belum Ada Variant Shopee' }
    : { value: 'tiktok_missing', label: 'Belum Ada Variant TikTok' }
  const secondaryMissingStatus = isShopeeFlow.value
    ? { value: 'tiktok_missing', label: 'Belum Ada Variant TikTok' }
    : { value: 'shopee_missing', label: 'Belum Ada Variant Shopee' }

  return [
    { value: 'all', label: 'Semua' },
    { value: 'ready_to_sync', label: 'Siap Disinkronkan' },
    primaryMissingStatus,
    secondaryMissingStatus,
    { value: 'belum_ada_variant', label: 'Belum Ada Variant' }
  ]
})
const tableFlowOptions = computed(() => [
  isShopeeFlow.value
    ? { value: 'tiktok-to-shopee', label: 'TikTok ke Shopee' }
    : { value: 'shopee-to-tiktok', label: 'Shopee ke TikTok' }
])
const tableFlowHint = computed(() => `Mode tabel: ${tableFlowOptions.value[0].label}.`)
const filters = reactive({
  search: '',
  status: isShopeeFlow.value ? 'shopee_missing' : 'tiktok_missing',
  flow: isShopeeFlow.value ? 'tiktok-to-shopee' : 'shopee-to-tiktok'
})
const pageCache = new Map()
const flowKey = computed(() => filters.flow || route.meta?.flow || tableFlowOptions.value[0].value)
const form = reactive({
  stock_master_id: null,
  shopee_item_id: '',
  shopee_model_id: '',
  seller_sku: '',
  tiktok_product_id: '',
  tiktok_sku_id: '',
  tiktok_sku_name: '',
  warehouse_id: '',
  inventory_qty: 0,
  notes: ''
})

const addVariantTool = reactive({
  product_id: DEFAULT_API_PRODUCT_ID,
  shop_cipher: '',
  seller_sku: '',
  color_name: '',
  image_uri: '',
  price: '50000',
  discount: 0,
  quantity: 1,
  dry_run: true
})

const resetForm = () => {
  form.stock_master_id = null
  form.shopee_item_id = ''
  form.shopee_model_id = ''
  form.seller_sku = ''
  form.tiktok_product_id = ''
  form.tiktok_sku_id = ''
  form.tiktok_sku_name = ''
  form.warehouse_id = ''
  form.inventory_qty = 0
  form.notes = ''
}

const normalizeText = (value) => String(value || '').trim().toLowerCase().replace(/\s+/g, ' ')
const formatDate = (value) => value ? new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : '-'
const initials = (name) => String(name || 'SK').split(' ').slice(0, 2).map((word) => word[0]).join('').toUpperCase()
const labelStatus = (status) => {
  const labels = {
    ready_to_sync: 'Siap Disinkronkan',
    shopee_missing: 'Belum Ada Variant Shopee',
    tiktok_missing: 'Belum Ada Variant TikTok',
    belum_ada_variant: 'Belum Ada Variant'
  }

  return labels[status] || labels.belum_ada_variant
}
const channelStatusLabel = (status) => status === 'mapped' ? 'Tersimpan' : status === 'suggested' ? 'Kandidat kode variasi' : 'Belum'
const displayStock = (value) => value === null || value === undefined || value === '' ? '-' : Number(value)
const hasPrice = (value) => value !== null && value !== undefined && String(value).trim() !== ''
const displayPrice = (value) => {
  const text = String(value ?? '').trim()
  if (!text) return '-'

  const numeric = Number(text)
  if (!Number.isFinite(numeric)) return '-'

  return `Rp ${new Intl.NumberFormat('id-ID').format(numeric)}`
}
const resolveTiktokProductId = (item) => String(
  item?.mapped_tiktok_product_id ||
  item?.tiktok?.product_id ||
  item?.stock_tiktok_product_id ||
  ''
).trim()
const resolveTiktokSkuId = (item) => String(
  item?.tiktok_sku_id ||
  item?.mapped_tiktok_sku_id ||
  item?.tiktok?.sku_id ||
  item?.stock_tiktok_sku_id ||
  ''
).trim()
const resolveShopeeItemId = (item) => String(
  item?.shopee?.item_id ||
  item?.shopee_item_id ||
  item?.shopee_product_id ||
  item?.stock_shopee_product_id ||
  ''
).trim()
const tiktokMatchSource = (item) => String(item?.tiktok?.source || '').trim()
const hasTiktokActual = (item) => {
  const status = String(item?.tiktok?.status || '').trim()
  if (status === 'mapped') {
    return true
  }

  const source = tiktokMatchSource(item)
  if (source === 'suggested_product') {
    return false
  }

  const skuId = String(item?.tiktok?.sku_id || '').trim()
  const skuName = normalizeText(item?.tiktok?.sku_name || item?.tiktok?.variant_name || '')

  return Boolean(skuId && skuName)
}
const hasTiktokProductCandidate = (item) => tiktokMatchSource(item) === 'suggested_product'
const hasTiktokCandidate = (item) => Boolean(item?.tiktok?.seller_sku || item?.seller_sku)
const hasShopeeActual = (item) => Boolean(item?.shopee?.item_id || item?.shopee?.model_id || item?.shopee?.image_url || item?.shopee?.stock_qty !== null && item?.shopee?.stock_qty !== undefined)
const hasShopeeCandidate = (item) => Boolean(item?.shopee?.seller_sku || item?.seller_sku)
const hasTiktok = (item) => hasTiktokActual(item) || hasTiktokProductCandidate(item) || hasTiktokCandidate(item)
const hasShopee = (item) => hasShopeeActual(item) || hasShopeeCandidate(item)
const shopeePresenceLabel = (item) => hasShopeeActual(item) ? 'Ada di Shopee' : hasShopeeCandidate(item) ? 'Kode variasi cocok, varian Shopee belum ada' : 'Varian ini tidak ada di Shopee'
const tiktokPresenceLabel = (item) => {
  if (hasTiktokActual(item)) return 'Ada di TikTok'
  if (hasTiktokProductCandidate(item)) return 'Produk TikTok ada, varian belum cocok'
  if (hasTiktokCandidate(item)) return 'Kode variasi cocok, varian TikTok belum ada'
  return 'Produk tidak ada dan variant tidak ada'
}
const getGroupKey = (item) => item?.group_key || item?.shopee?.item_id || item?.tiktok?.product_id || item?.product_name || item?.internal_sku || ''
const normalizeSelectionId = (value) => String(value ?? '').trim()
const isVariantSelected = (item) => selectedVariantIds.value.includes(normalizeSelectionId(item?.id))
const resolveGroupTiktokProductId = (group) => {
  if (!group?.variants?.length) return ''

  for (const variant of group.variants) {
    const productId = resolveTiktokProductId(variant)
    if (productId) return productId
  }

  return ''
}
const resolveGroupTiktokSkuId = (group) => {
  if (!group?.variants?.length) return ''

  for (const variant of group.variants) {
    const skuId = resolveTiktokSkuId(variant)
    if (skuId) return skuId
  }

  return ''
}
const resolveGroupShopeeItemId = (group) => {
  if (!group?.variants?.length) return ''

  for (const variant of group.variants) {
    const itemId = resolveShopeeItemId(variant)
    if (itemId) return itemId
  }

  return ''
}
const resolveSelectedTiktokProductId = () => resolveTiktokProductId(selectedItem.value) || resolveGroupTiktokProductId(activeGroup.value) || ''
const resolveSelectedTiktokSkuId = () => resolveTiktokSkuId(selectedItem.value) || ''
const resolveSelectedShopeeItemId = () => resolveShopeeItemId(selectedItem.value) || resolveGroupShopeeItemId(activeGroup.value) || ''
const sortGroupVariants = (group) => ({
  ...group,
  variants: [...group.variants].sort((a, b) => {
    const rankDiff = variantSortRank(a) - variantSortRank(b)
    if (rankDiff !== 0) return rankDiff

    const aName = normalizeText(a.variant_name || a.tiktok?.variant_name || '')
    const bName = normalizeText(b.variant_name || b.tiktok?.variant_name || '')
    return aName.localeCompare(bName)
  })
})
const isExpanded = (key) => expandedGroups.value[key] !== false
const toggleGroup = (key) => {
  expandedGroups.value = {
    ...expandedGroups.value,
    [key]: !isExpanded(key)
  }
}
const expandAllGroups = () => {
  expandedGroups.value = Object.fromEntries(groupedItems.value.map((group) => [group.key, true]))
}
const resolveSelectedVariantSource = (value) => {
  const key = normalizeSelectionId(value)
  if (!key) return null

  return selectedVariantSnapshots[key]
    || displayVariants.value.find((item) => normalizeSelectionId(item?.id) === key)
    || items.value.find((item) => normalizeSelectionId(item?.id) === key)
    || (selectedItem.value && normalizeSelectionId(selectedItem.value?.id) === key ? selectedItem.value : null)
}
const selectedVariantItems = computed(() => {
  return selectedVariantIds.value
    .map((value) => resolveSelectedVariantSource(value))
    .filter(Boolean)
})
const selectedVariantPayloadLabels = computed(() => {
  return selectedVariantItems.value.map((variant) => {
    const variantName = String(variant?.variant_name || 'Tanpa Varian').trim() || 'Tanpa Varian'
    const sellerSku = String(variant?.seller_sku || variant?.internal_sku || '-').trim() || '-'
    return `${variantName} (${sellerSku})`
  })
})
const allDisplayedVariantsSelected = computed(() => displayVariants.value.length > 0 && displayVariants.value.every((item) => isVariantSelected(item)))
const someDisplayedVariantsSelected = computed(() => displayVariants.value.some((item) => isVariantSelected(item)))
const groupVariantIds = (group) => {
  return (group?.variants || [])
    .map((item) => normalizeSelectionId(item?.id))
    .filter(Boolean)
}
const isGroupVariantsSelected = (group) => {
  const ids = groupVariantIds(group)
  if (!ids.length) return false

  const selected = new Set(selectedVariantIds.value.map((value) => normalizeSelectionId(value)))
  return ids.every((id) => selected.has(id))
}
const isGroupVariantsPartiallySelected = (group) => {
  const ids = groupVariantIds(group)
  if (!ids.length) return false

  const selected = new Set(selectedVariantIds.value.map((value) => normalizeSelectionId(value)))
  const selectedCount = ids.filter((id) => selected.has(id)).length
  return selectedCount > 0 && selectedCount < ids.length
}
const missingTargetChannel = (item) => {
  if (hasShopeeActual(item) && !hasTiktokActual(item)) return 'tiktok'
  if (hasTiktokActual(item) && !hasShopeeActual(item)) return 'shopee'
  return null
}
const canPrepareMissingVariant = (item) => Boolean(missingTargetChannel(item))
const variantSortRank = (item) => {
  return item?.status === 'ready_to_sync' ? 0 : 1
}
const tiktokDetailHint = (item) => {
  if (hasTiktokActual(item)) {
    return 'Data TikTok aktif sudah tersedia.'
  }

  if (hasTiktokProductCandidate(item)) {
    return 'Produk TikTok sudah ada, tetapi varian ini belum cocok ke SKU aktif.'
  }

  if (hasTiktokCandidate(item)) {
    return 'Yang cocok baru kode variasinya, belum ada varian TikTok aktif.'
  }

  return 'Varian ini memang belum ada di TikTok.'
}
const resolveAddVariantProductId = () => {
  if (isShopeeFlow.value) {
    return String(
      addVariantTool.product_id ||
      shopeeProductTool.item_id ||
      resolveSelectedShopeeItemId() ||
      ''
    ).trim()
  }

  return String(
    addVariantTool.product_id ||
    getProductTool.product_id ||
    resolveSelectedTiktokProductId() ||
    ''
  ).trim()
}
const resolveAddVariantShopCipher = () => {
  return String(
    addVariantTool.shop_cipher ||
    getProductTool.shop_cipher ||
    ''
  ).trim()
}
const resolveAddVariantVersion = () => {
  return String(TIKTOK_SUBMIT_VERSION).trim() || TIKTOK_SUBMIT_VERSION
}
const toggleVariantSelection = (item, checked = null) => {
  const key = normalizeSelectionId(item?.id)
  if (!key) return

  const selected = new Set(selectedVariantIds.value.map((value) => normalizeSelectionId(value)))
  const nextChecked = checked === null ? !selected.has(key) : Boolean(checked)

  if (nextChecked) {
    selected.add(key)
    selectedVariantSnapshots[key] = cloneJson(item) || item
  } else {
    selected.delete(key)
    delete selectedVariantSnapshots[key]
  }

  selectedVariantIds.value = Array.from(selected)
}
const toggleDisplayedVariantsSelection = (checked) => {
  const visibleIds = displayVariants.value.map((item) => normalizeSelectionId(item.id)).filter(Boolean)
  const selected = new Set(selectedVariantIds.value.map((value) => normalizeSelectionId(value)))

  if (checked) {
    displayVariants.value.forEach((item) => {
      const key = normalizeSelectionId(item?.id)
      if (!key) return
      selected.add(key)
      selectedVariantSnapshots[key] = cloneJson(item) || item
    })
  } else {
    visibleIds.forEach((id) => {
      selected.delete(id)
      delete selectedVariantSnapshots[id]
    })
  }

  selectedVariantIds.value = Array.from(selected)
}
const toggleGroupVariantSelection = (group, checked) => {
  const variants = group?.variants || []
  const selected = new Set(selectedVariantIds.value.map((value) => normalizeSelectionId(value)))

  if (checked) {
    variants.forEach((item) => {
      const key = normalizeSelectionId(item?.id)
      if (!key) return
      selected.add(key)
      selectedVariantSnapshots[key] = cloneJson(item) || item
    })
  } else {
    variants.forEach((item) => {
      const key = normalizeSelectionId(item?.id)
      if (!key) return
      selected.delete(key)
      delete selectedVariantSnapshots[key]
    })
  }

  selectedVariantIds.value = Array.from(selected)
}
const clearSelectedVariants = () => {
  selectedVariantIds.value = []
  Object.keys(selectedVariantSnapshots).forEach((key) => {
    delete selectedVariantSnapshots[key]
  })
}
const syncSelectedVariantSnapshots = (currentItems = []) => {
  if (!selectedVariantIds.value.length || !currentItems.length) return

  const selected = new Set(selectedVariantIds.value.map((value) => normalizeSelectionId(value)))
  currentItems.forEach((item) => {
    const key = normalizeSelectionId(item?.id)
    if (!key || !selected.has(key)) return
    selectedVariantSnapshots[key] = cloneJson(item) || item
  })
}
const getSelectedVariantSources = () => selectedVariantItems.value.length
  ? selectedVariantItems.value
  : (selectedItem.value ? [selectedItem.value] : [])
const buildTiktokSubmitMeta = () => ({
  product_id: resolveAddVariantProductId(),
  version: resolveAddVariantVersion(),
  shop_cipher: resolveAddVariantShopCipher()
})
const buildShopeeAddVariantPayload = () => {
  const selectedSources = getSelectedVariantSources()
  const fallbackVariant = {
    id: 'manual',
    stock_master_id: form.stock_master_id,
    variant_name: String(addVariantTool.color_name || 'Variant Baru').trim() || 'Variant Baru',
    seller_sku: String(addVariantTool.seller_sku || '').trim(),
    image_url: String(addVariantTool.image_uri || '').trim(),
    tiktok: {
      product_id: resolveSelectedTiktokProductId(),
      sku_id: resolveSelectedTiktokSkuId(),
      price: normalizePriceInput(addVariantTool.price, '50000'),
      stock_qty: Number(addVariantTool.quantity ?? 0),
      image_url: String(addVariantTool.image_uri || '').trim()
    },
    stock_qty: Number(addVariantTool.quantity ?? 0)
  }
  const sources = selectedSources.length ? selectedSources : [fallbackVariant]
  const variants = []
  const seen = new Set()

  sources.forEach((source) => {
    const variantName = String(source?.tiktok?.variant_name || source?.tiktok?.sku_name || source?.variant_name || addVariantTool.color_name || '').trim()
    const variantKey = normalizeText(variantName)
    if (!variantName || seen.has(variantKey)) return
    seen.add(variantKey)

    const tiktokPrice = source?.tiktok?.price ?? source?.price ?? addVariantTool.price
    const tiktokStock = source?.tiktok?.stock_qty ?? source?.stock_qty ?? addVariantTool.quantity ?? 0
    const sellerSku = String(
      source?.tiktok?.seller_sku ||
      source?.seller_sku ||
      source?.internal_sku ||
      addVariantTool.seller_sku ||
      ''
    ).trim()
    const imageUrl = String(
      source?.tiktok?.image_url ||
      source?.image_url ||
      addVariantTool.image_uri ||
      ''
    ).trim()

    variants.push({
      stock_master_id: typeof source?.stock_master_id === 'number' ? source.stock_master_id : null,
      variant_name: variantName,
      seller_sku: sellerSku,
      price: normalizePriceInput(tiktokPrice, normalizePriceInput(addVariantTool.price, '50000') || '50000'),
      stock: Number(tiktokStock ?? 0),
      image_url: imageUrl,
      tiktok_product_id: String(source?.tiktok?.product_id || resolveSelectedTiktokProductId() || '').trim(),
      tiktok_sku_id: String(source?.tiktok?.sku_id || resolveSelectedTiktokSkuId() || '').trim()
    })
  })

  return {
    item_id: resolveAddVariantProductId(),
    account_key: shopeeProductTool.account_key,
    shop_id: shopeeProductTool.shop_id,
    access_token: shopeeProductTool.access_token,
    dry_run: Boolean(addVariantTool.dry_run),
    variants
  }
}
const formatResponseText = (value) => {
  if (typeof value === 'string') return value
  if (value === null || value === undefined) return ''
  return JSON.stringify(value, null, 2)
}
const parseResponseJson = (value) => {
  const text = formatResponseText(value).trim()
  if (!text) return null

  try {
    return JSON.parse(text)
  } catch {
    return null
  }
}
const responseLinesFromText = (value) => {
  return String(value || '')
    .split('\n')
    .map((line, index) => ({
      no: index + 1,
      text: line
    }))
}
const shopeeDetailHint = (item) => hasShopeeActual(item)
  ? 'Data Shopee aktif sudah tersedia.'
  : hasShopeeCandidate(item)
    ? 'Yang cocok baru kode variasinya, belum ada varian Shopee aktif.'
    : 'Varian ini memang belum ada di Shopee.'

const fillAddVariantToolFromItem = (item, contextProductId = '') => {
  if (!item) return

  addVariantTool.product_id = isShopeeFlow.value
    ? (contextProductId || resolveShopeeItemId(item) || '')
    : (contextProductId || resolveTiktokProductId(item))

  const sellerSku = String(
    (isShopeeFlow.value ? item.tiktok?.seller_sku : item.shopee?.seller_sku) ||
    item.seller_sku ||
    item.stock_shopee_seller_sku ||
    item.internal_sku ||
    item.tiktok?.seller_sku ||
    ''
  ).trim()
  const colorName = String(
    (isShopeeFlow.value ? item.tiktok?.variant_name : item.shopee?.variant_name) ||
    item.variant_name ||
    item.tiktok?.variant_name ||
    item.tiktok?.sku_name ||
    ''
  ).trim()
  const imageUri = String(
    (isShopeeFlow.value ? item.tiktok?.image_url : item.shopee?.image_url) ||
    item.image_url ||
    item.shopee_model_image_url ||
    item.shopee_product_image_url ||
    item.tiktok?.image_url ||
    ''
  ).trim()
  const priceValue = isShopeeFlow.value
    ? (item.tiktok?.price ?? item.price ?? addVariantTool.price)
    : (item.shopee_variant_price ?? item.shopee?.price ?? item.price ?? addVariantTool.price)
  const quantityValue = isShopeeFlow.value
    ? (item.tiktok?.stock_qty ?? item.stock_qty ?? 0)
    : (item.shopee_variant_stock ?? item.shopee?.stock_qty ?? item.stock_qty ?? 0)
  const normalizedPrice = normalizePriceInput(
    priceValue,
    normalizePriceInput(addVariantTool.price, '50000')
  )

  addVariantTool.seller_sku = sellerSku
  addVariantTool.color_name = colorName
  addVariantTool.image_uri = imageUri
  addVariantTool.price = normalizedPrice || '50000'
  addVariantTool.quantity = Number(quantityValue ?? 0)
}

const normalizeTextForSku = (value) => String(value || '').trim()

const pickFirstArray = (...candidates) => {
  for (const candidate of candidates) {
    if (Array.isArray(candidate) && candidate.length) {
      return candidate
    }
  }

  return []
}

const resolveTiktokProductPayload = (payload) => {
  if (!payload || typeof payload !== 'object') return null

  if (payload.data && typeof payload.data === 'object') {
    if (payload.data.product && typeof payload.data.product === 'object') {
      return payload.data.product
    }

    return payload.data
  }

  if (payload.product && typeof payload.product === 'object') {
    return payload.product
  }

  return payload
}

const resolveTiktokPayloadSkus = (payload) => {
  if (!payload || typeof payload !== 'object') return []

  return pickFirstArray(
    payload.skus,
    payload.sku_list,
    payload.sku_info_list,
    payload.sku_infos,
    payload.skus_info,
    payload.variants,
    payload.model_list,
    payload.product_skus
  )
}

const getFirstSkuFromProductPayload = (payload) => {
  const normalizedPayload = resolveTiktokProductPayload(payload)
  const skus = resolveTiktokPayloadSkus(normalizedPayload)
  return skus.length ? skus[0] : null
}

const mapProductPayloadToVariantDefaults = (payload) => {
  const normalizedPayload = resolveTiktokProductPayload(payload) || {}
  const firstSku = getFirstSkuFromProductPayload(normalizedPayload)
  const salesAttributes = Array.isArray(firstSku?.sales_attributes) ? firstSku.sales_attributes : []
  const firstAttribute = salesAttributes[0] || {}
  const inventory = Array.isArray(firstSku?.inventory) ? firstSku.inventory[0] : null
  const mainImage = Array.isArray(normalizedPayload?.main_images) ? normalizedPayload.main_images[0] : null
  const skuImage = firstAttribute?.sku_img?.uri || ''
  const salePrice = firstSku?.price?.sale_price || firstSku?.price?.amount || firstSku?.price?.tax_exclusive_price || '50000'
  const quantity = inventory?.quantity ?? 0

  return {
    product_id: normalizeTextForSku(normalizedPayload?.product_id || normalizedPayload?.id || getProductTool.product_id || resolveSelectedTiktokProductId() || ''),
    seller_sku: normalizeTextForSku(firstSku?.seller_sku || ''),
    color_name: normalizeTextForSku(firstAttribute?.value_name || firstAttribute?.name || ''),
    image_uri: normalizeTextForSku(skuImage || mainImage?.uri || ''),
    price: normalizeTextForSku(salePrice || '50000'),
    quantity: Number(quantity || 0)
  }
}

const groupedItems = computed(() => {
  const groups = new Map()

  items.value.forEach((item) => {
    const key = getGroupKey(item)
    if (!groups.has(key)) {
      groups.set(key, {
        key,
        name: item.product_name || item.shopee?.product_name || item.tiktok?.product_name || 'Produk',
        image_url: item.image_url || item.shopee?.image_url || item.tiktok?.image_url || '',
        variants: [],
        total_stock: 0,
        shopee: { present: 0, missing: 0, total_stock: 0, product_name: item.shopee?.product_name || '' },
        tiktok: { present: 0, missing: 0, total_stock: 0, suggested: 0, product_name: item.tiktok?.product_name || '' },
        status: 'unmapped'
      })
    }

    const group = groups.get(key)
    group.variants.push(item)
    group.total_stock += Number(item.stock_qty || 0)

    if (hasShopeeActual(item)) {
      group.shopee.present += 1
      group.shopee.total_stock += Number(item.shopee?.stock_qty || 0)
    } else {
      group.shopee.missing += 1
    }

    if (hasTiktokActual(item)) {
      group.tiktok.present += 1
      group.tiktok.total_stock += Number(item.tiktok?.stock_qty || 0)
    } else {
      group.tiktok.missing += 1
    }

    if (item.tiktok?.status === 'suggested') group.tiktok.suggested += 1
    if (!group.image_url) group.image_url = item.image_url || item.shopee?.image_url || item.tiktok?.image_url || ''
    if (!group.shopee.product_name && item.shopee?.product_name) group.shopee.product_name = item.shopee.product_name
    if (!group.tiktok.product_name && item.tiktok?.product_name) group.tiktok.product_name = item.tiktok.product_name
  })

  return Array.from(groups.values()).map((group) => {
    const allReadyToSync = group.variants.every((variant) => variant.status === 'ready_to_sync')
    const allShopeeMissing = group.variants.every((variant) => variant.status === 'shopee_missing')
    const allTiktokMissing = group.variants.every((variant) => variant.status === 'tiktok_missing')

    return {
      ...group,
      status: allReadyToSync
        ? 'ready_to_sync'
        : allShopeeMissing
          ? 'shopee_missing'
          : allTiktokMissing
            ? 'tiktok_missing'
            : 'belum_ada_variant'
    }
  })
})

const activeGroup = computed(() => {
  if (!groupedItems.value.length) return null
  const selectedKey = getGroupKey(selectedItem.value)
  const selectedGroup = selectedKey ? groupedItems.value.find((group) => group.key === selectedKey) : null
  const exact = normalizeText(filters.search)
    ? groupedItems.value.find((group) => normalizeText(group.name) === normalizeText(filters.search))
    : null
  const group = selectedGroup || exact || groupedItems.value[0]

  return sortGroupVariants(group)
})

const displayVariants = computed(() => items.value)
const summaryItem = computed(() => selectedItem.value || activeGroup.value?.variants?.[0] || null)

const getProductTool = reactive({
  version: TIKTOK_GET_PRODUCT_VERSION,
  shop_id: '',
  shop_cipher: '',
  access_token: '',
  product_id: DEFAULT_API_PRODUCT_ID,
  return_under_review_version: 'false',
  return_draft_version: 'false',
  locale: ''
})

const shopeeProductTool = reactive({
  api_name: 'get_item_base_info',
  account_key: '',
  shop_id: '',
  access_token: '',
  item_id: '',
  need_tax_info: 'false',
  need_complaint_policy: 'false'
})

const shopeeApiPath = computed(() => shopeeProductTool.api_name === 'get_model_list'
  ? '/api/v2/product/get_model_list'
  : '/api/v2/product/get_item_base_info')
const apiTestingTitle = computed(() => isShopeeFlow.value
  ? shopeeProductTool.api_name
  : 'Get Product')
const apiTestingPath = computed(() => isShopeeFlow.value
  ? shopeeApiPath.value
  : '/product/{version}/products/{product_id}')

const buildGetProductQuery = () => {
  const params = new URLSearchParams()
  const pushIfValue = (key, value) => {
    const text = String(value ?? '').trim()
    if (text || text === 'false') params.set(key, text)
  }

  pushIfValue('access_token', getProductTool.access_token)
  pushIfValue('app_key', '6i1cagd9f0p83')
  pushIfValue('shop_cipher', getProductTool.shop_cipher)
  pushIfValue('shop_id', getProductTool.shop_id)
  pushIfValue('sign', '4a76ac82d74afd02afd9cc3c41fe131a167592f0d374237ad301dba5bd3a6089')
  pushIfValue('timestamp', '1778278324')
  pushIfValue('version', getProductTool.version || TIKTOK_GET_PRODUCT_VERSION)
  pushIfValue('return_under_review_version', getProductTool.return_under_review_version)
  pushIfValue('return_draft_version', getProductTool.return_draft_version)
  pushIfValue('locale', getProductTool.locale)
  return params.toString()
}

const buildShopeeApiTestPayload = () => ({
  api_name: shopeeProductTool.api_name,
  account_key: shopeeProductTool.account_key,
  shop_id: shopeeProductTool.shop_id,
  access_token: shopeeProductTool.access_token,
  item_id: shopeeProductTool.item_id || resolveSelectedShopeeItemId(),
  need_tax_info: shopeeProductTool.need_tax_info,
  need_complaint_policy: shopeeProductTool.need_complaint_policy
})

const apiGetProductCurl = computed(() => {
  if (isShopeeFlow.value) {
    const url = `${window.location.origin}/api/shopee/api-test`
    const body = JSON.stringify(buildShopeeApiTestPayload())
      .replace(/'/g, "'\\''")

    return [
      "curl -X 'POST' \\",
      "  -H 'Content-Type: application/json' \\",
      `  --data-raw '${body}' \\`,
      `  '${url}'`
    ].join('\n')
  }

  const productId = String(getProductTool.product_id || resolveSelectedTiktokProductId() || '').trim()
  const url = `https://open-api.tiktokglobalshop.com/product/${getProductTool.version || TIKTOK_GET_PRODUCT_VERSION}/products/${productId}`
  const query = buildGetProductQuery()
  const querySuffix = query ? `?${query}` : ''
  const accessToken = String(getProductTool.access_token || '').trim()
  return [
    "curl -k -X 'GET' \\",
    `  -H 'x-tts-access-token: ${accessToken}' \\`,
    `  '${url}${querySuffix}'`
  ].join('\n')
})

const apiGetProductResponseLines = computed(() => {
  return String(getProductResponseText.value || '')
    .split('\n')
    .map((line, index) => ({
      no: index + 1,
      text: line
    }))
})

const apiGetProductResponsePayload = computed(() => {
  if (!getProductResponseText.value) return null

  try {
    return JSON.parse(getProductResponseText.value)
  } catch {
    return null
  }
})

const apiGetProductResponseProduct = computed(() => resolveTiktokProductPayload(apiGetProductResponsePayload.value))

const cloneJson = (value) => {
  if (value === null || value === undefined) return value
  return JSON.parse(JSON.stringify(value))
}

const normalizeNumericString = (value) => {
  const text = String(value ?? '').trim()
  if (!/^\d+$/.test(text)) return null
  return text.replace(/^0+/, '') || '0'
}

const normalizePriceInput = (value, fallback = null) => {
  const text = String(value ?? '').trim()
  if (!text) return fallback

  const digitsOnly = text.replace(/[^\d]/g, '')
  if (!digitsOnly) return fallback

  return digitsOnly.replace(/^0+/, '') || '0'
}

const compareNumericStrings = (left, right) => {
  const a = normalizeNumericString(left)
  const b = normalizeNumericString(right)
  if (a === null && b === null) return 0
  if (a === null) return -1
  if (b === null) return 1
  if (a.length !== b.length) return a.length - b.length
  return a.localeCompare(b)
}

const extractTiktokProductIdFromPayload = (payload) => {
  if (!payload || typeof payload !== 'object') return ''

  const data = payload?.data && typeof payload.data === 'object' ? payload.data : payload
  const product = data?.product && typeof data.product === 'object' ? data.product : data
  const candidate = product?.product_id ?? product?.id ?? product?.product?.id ?? data?.product_id ?? data?.id ?? ''
  return String(candidate ?? '').trim()
}

const addNumericStrings = (value, step) => {
  const left = normalizeNumericString(value) || '0'
  const right = normalizeNumericString(step) || '0'
  let carry = 0
  let result = ''
  let leftIndex = left.length - 1
  let rightIndex = right.length - 1

  while (leftIndex >= 0 || rightIndex >= 0 || carry > 0) {
    const leftDigit = leftIndex >= 0 ? Number(left[leftIndex]) : 0
    const rightDigit = rightIndex >= 0 ? Number(right[rightIndex]) : 0
    const sum = leftDigit + rightDigit + carry
    result = String(sum % 10) + result
    carry = Math.floor(sum / 10)
    leftIndex -= 1
    rightIndex -= 1
  }

  return result
}

const subtractNumericStrings = (left, right) => {
  let a = normalizeNumericString(left) || '0'
  let b = normalizeNumericString(right) || '0'
  if (compareNumericStrings(a, b) < 0) return null

  let borrow = 0
  let result = ''
  let leftIndex = a.length - 1
  let rightIndex = b.length - 1

  while (leftIndex >= 0) {
    let leftDigit = Number(a[leftIndex]) - borrow
    const rightDigit = rightIndex >= 0 ? Number(b[rightIndex]) : 0
    borrow = 0

    if (leftDigit < rightDigit) {
      leftDigit += 10
      borrow = 1
    }

    result = String(leftDigit - rightDigit) + result
    leftIndex -= 1
    rightIndex -= 1
  }

  return normalizeNumericString(result)
}

const buildNumericFallback = (seed, digits = 19) => {
  const modulus = 1000000000
  let firstHash = 0
  let secondHash = 7
  const text = String(seed || '').trim() || 'tiktok'

  for (const character of text) {
    const code = character.charCodeAt(0)
    firstHash = (firstHash * 131 + code) % modulus
    secondHash = (secondHash * 137 + code) % modulus
  }

  return `${secondHash}${String(firstHash).padStart(9, '0')}`.padStart(digits, '0').slice(-digits)
}

const buildNextNumericValue = (values, seed, preferredStep = null) => {
  const numericValues = values
    .map(normalizeNumericString)
    .filter((value) => value !== null)

  if (!numericValues.length) {
    return buildNumericFallback(seed)
  }

  const positiveDiffs = []
  for (let index = 1; index < numericValues.length; index += 1) {
    const diff = subtractNumericStrings(numericValues[index], numericValues[index - 1])
    if (diff !== null && diff !== '0') positiveDiffs.push(diff)
  }

  let step = normalizeNumericString(preferredStep)
  if (step === null) {
    if (positiveDiffs.length) {
      step = positiveDiffs[0]
      for (let index = 1; index < positiveDiffs.length; index += 1) {
        if (compareNumericStrings(positiveDiffs[index], step) < 0) {
          step = positiveDiffs[index]
        }
      }
    } else {
      step = '1'
    }
  }

  if (step === '0') {
    step = '1'
  }

  const maxValue = numericValues.reduce((max, value) => (compareNumericStrings(value, max) > 0 ? value : max), numericValues[0])
  return addNumericStrings(maxValue, step)
}

const normalizeDiscountPercent = (value) => {
  const text = String(value ?? '').trim().replace(',', '.')
  if (!text) return 0

  const numeric = Number(text)
  if (!Number.isFinite(numeric)) return 0

  return Math.min(100, Math.max(0, numeric))
}

const applyDiscountToPrice = (priceValue, discountValue) => {
  const normalizedPrice = normalizePriceInput(priceValue)
  const basePrice = Number(normalizedPrice ?? String(priceValue ?? '').trim().replace(',', '.'))
  if (!Number.isFinite(basePrice) || basePrice <= 0) {
    return 0
  }

  const discountPercent = normalizeDiscountPercent(discountValue)
  if (discountPercent <= 0) {
    return Math.max(0, Math.round(basePrice))
  }

  const discounted = Math.round(basePrice * (1 - (discountPercent / 100)))
  return Math.max(1, discounted)
}

const extractTiktokProductBody = (payload) => {
  if (!payload || typeof payload !== 'object') return null

  const normalizedPayload = resolveTiktokProductPayload(payload)
  if (!normalizedPayload || typeof normalizedPayload !== 'object') {
    return null
  }

  const skus = resolveTiktokPayloadSkus(normalizedPayload)
  if (!Array.isArray(normalizedPayload.skus) && skus.length) {
    normalizedPayload.skus = skus
  }

  if (Array.isArray(normalizedPayload.skus) || Array.isArray(normalizedPayload.main_images) || String(normalizedPayload.title || '').trim() !== '') {
    return normalizedPayload
  }

  return null
}

const normalizeTiktokUploadedImageUri = (value) => {
  const text = String(value || '').trim()
  if (!text) return ''
  if (/^(https?:|data:|blob:)/i.test(text)) return ''
  if (text.startsWith('/') || text.includes('/cached-images/')) return ''
  if (text.startsWith('cached-images/')) return ''
  return text.includes('/') ? text : ''
}

const firstTiktokUploadedImageUri = (...values) => {
  for (const value of values) {
    const uri = normalizeTiktokUploadedImageUri(value)
    if (uri) return uri
  }

  return ''
}

const findExistingTiktokImageUri = (productBody, existingSkus, preferredValueName = '') => {
  const targetValueName = normalizeText(preferredValueName)
  const matchingSku = existingSkus.find((sku) => {
    return normalizeText(sku?.sales_attributes?.[0]?.value_name) === targetValueName
  })
  const skuWithImage = existingSkus.find((sku) => normalizeTiktokUploadedImageUri(sku?.sales_attributes?.[0]?.sku_img?.uri))
  const mainImages = Array.isArray(productBody.main_images) ? productBody.main_images : []

  return firstTiktokUploadedImageUri(
    matchingSku?.sales_attributes?.[0]?.sku_img?.uri,
    skuWithImage?.sales_attributes?.[0]?.sku_img?.uri,
    ...mainImages.map((image) => typeof image === 'string' ? image : image?.uri)
  )
}

const normalizeTiktokSkuPreSaleType = (value) => String(value || '').trim()

const resolveDefaultTiktokSkuPreSale = (skus) => {
  const existingPreSale = skus
    .map((sku) => sku?.pre_sale)
    .find((preSale) => {
      return preSale &&
        typeof preSale === 'object' &&
        !Array.isArray(preSale) &&
        normalizeTiktokSkuPreSaleType(preSale.type)
    })

  return cloneJson(existingPreSale) || { type: 'NONE' }
}

const applyDefaultTiktokSkuPreSale = (sku, defaultPreSale) => {
  if (!sku || typeof sku !== 'object') return sku

  const currentPreSale = sku.pre_sale && typeof sku.pre_sale === 'object' && !Array.isArray(sku.pre_sale)
    ? sku.pre_sale
    : null

  if (!currentPreSale || !normalizeTiktokSkuPreSaleType(currentPreSale.type)) {
    sku.pre_sale = cloneJson(defaultPreSale) || { type: 'NONE' }
  }

  if (normalizeTiktokSkuPreSaleType(sku.pre_sale?.type) === 'NONE') {
    delete sku.pre_sale.fulfillment_type
  }

  return sku
}

const buildAddVariantRequestPreview = () => {
  if (isShopeeFlow.value) {
    return JSON.stringify(buildShopeeAddVariantPayload(), null, 2)
  }

  const productBody = cloneJson(extractTiktokProductBody(apiGetProductResponsePayload.value)) || {}
  const existingSkus = Array.isArray(productBody.skus)
    ? productBody.skus.map((sku) => cloneJson(sku) || {})
    : []
  const defaultSkuPreSale = resolveDefaultTiktokSkuPreSale(existingSkus)
  existingSkus.forEach((sku) => applyDefaultTiktokSkuPreSale(sku, defaultSkuPreSale))
  const selectedSources = getSelectedVariantSources()
  const titleSource = selectedSources[0] || selectedItem.value || {}

  const productTitle = String(
    productBody.title ||
    titleSource?.tiktok?.product_name ||
    titleSource?.product_name ||
    DEFAULT_PRODUCT_NAME
  ).trim() || DEFAULT_PRODUCT_NAME

  const existingAttribute = existingSkus.find((sku) => sku?.sales_attributes?.[0]?.id || sku?.sales_attributes?.[0]?.name)?.sales_attributes?.[0] || {}

  const baseAttribute = {
    id: String(existingAttribute.id || TIKTOK_VARIANT_ATTRIBUTE_ID),
    name: String(existingAttribute.name || TIKTOK_VARIANT_ATTRIBUTE_NAME),
    value_name: 'Variant Baru'
  }

  const skuDrafts = selectedSources.length ? selectedSources : [{
    id: 'manual',
    product_name: productTitle,
    variant_name: String(addVariantTool.color_name || 'Variant Baru').trim() || 'Variant Baru',
    seller_sku: String(addVariantTool.seller_sku || '').trim(),
    image_url: String(addVariantTool.image_uri || '').trim(),
    shopee: {
      stock_qty: Number(addVariantTool.quantity ?? 0)
    },
    stock_qty: Number(addVariantTool.quantity ?? 0)
  }]

  const discountPercent = normalizeDiscountPercent(addVariantTool.discount)
  const generatedSkus = []
  const existingVariantIndexByKey = new Map()
  existingSkus.forEach((sku, index) => {
    const key = normalizeText(sku?.sales_attributes?.[0]?.value_name || sku?.sales_attributes?.[0]?.name || '')
    if (!key || existingVariantIndexByKey.has(key)) return
    existingVariantIndexByKey.set(key, index)
  })
  const processedVariantValueKeys = new Set()
  const existingSellerSkuKeys = new Set(
    existingSkus
      .map((sku) => normalizeTextForSku(sku?.seller_sku || '').toLowerCase())
      .filter(Boolean)
  )
  const generatedSellerSkuKeys = new Set()
  const manualPriceInput = normalizePriceInput(addVariantTool.price)

  skuDrafts.forEach((source, sourceIndex) => {
    const colorName = String(source?.variant_name || source?.tiktok?.variant_name || source?.tiktok?.sku_name || 'Variant Baru').trim() || 'Variant Baru'
    const colorKey = normalizeText(colorName)
    if (!colorKey) {
      return
    }

    if (processedVariantValueKeys.has(colorKey)) {
      return
    }
    processedVariantValueKeys.add(colorKey)

    const existingVariantIndex = existingVariantIndexByKey.get(colorKey)

    let sellerSku = String(source?.seller_sku || source?.internal_sku || source?.tiktok?.seller_sku || `SKU-${buildNextNumericValue(
      existingSkus.map((sku) => sku.id || sku.sku_id),
      `${productTitle}|${colorName}|${sourceIndex}`,
      '65536'
    ).slice(-6)}`).trim()
    const imageUri = String(
      source?.image_url ||
      source?.shopee?.image_url ||
      source?.tiktok?.image_url ||
      findExistingTiktokImageUri(productBody, existingSkus, colorName) ||
      ''
    ).trim()
    const quantityValue = Number(
      source?.shopee?.stock_qty ??
      source?.shopee_variant_stock ??
      source?.stock_qty ??
      addVariantTool.quantity ??
      0
    )
    const sourcePriceValue =
      source?.price?.sale_price ??
      source?.price?.amount ??
      source?.price?.tax_exclusive_price ??
      source?.price ??
      source?.shopee?.price ??
      source?.shopee_variant_price ??
      '50000'
    const basePriceValue = manualPriceInput || normalizePriceInput(sourcePriceValue, '50000') || '50000'
    const discountedPriceValue = String(applyDiscountToPrice(basePriceValue, discountPercent))

    if (existingVariantIndex !== undefined) {
      const existingSku = cloneJson(existingSkus[existingVariantIndex]) || {}
      const existingSellerSkuKey = normalizeTextForSku(existingSku?.seller_sku || '').toLowerCase()
      const requestedSellerSkuKey = normalizeTextForSku(sellerSku).toLowerCase()
      if (requestedSellerSkuKey && requestedSellerSkuKey !== existingSellerSkuKey) {
        if (existingSellerSkuKeys.has(requestedSellerSkuKey) || generatedSellerSkuKeys.has(requestedSellerSkuKey)) {
          sellerSku = existingSku?.seller_sku || sellerSku
        }
      }

      if (sellerSku) {
        existingSku.seller_sku = sellerSku
      }

      const currentAttribute = Array.isArray(existingSku.sales_attributes) ? existingSku.sales_attributes[0] || {} : {}
      existingSku.sales_attributes = [{
        ...baseAttribute,
        ...currentAttribute,
        value_name: colorName,
        ...(imageUri ? { sku_img: { uri: imageUri } } : {})
      }]

      const currentPrice = existingSku.price && typeof existingSku.price === 'object' && !Array.isArray(existingSku.price)
        ? existingSku.price
        : {}
      existingSku.price = {
        ...currentPrice,
        currency: 'IDR',
        sale_price: discountedPriceValue,
        tax_exclusive_price: discountedPriceValue,
        amount: discountedPriceValue
      }

      const currentInventory = Array.isArray(existingSku.inventory) ? existingSku.inventory[0] || {} : {}
      existingSku.inventory = [{
        ...currentInventory,
        quantity: quantityValue,
        warehouse_id: String(currentInventory.warehouse_id || TIKTOK_VARIANT_WAREHOUSE_ID)
      }]
      applyDefaultTiktokSkuPreSale(existingSku, defaultSkuPreSale)

      existingSkus[existingVariantIndex] = existingSku

      const updatedSellerSkuKey = normalizeTextForSku(existingSku?.seller_sku || '').toLowerCase()
      if (updatedSellerSkuKey) {
        generatedSellerSkuKeys.add(updatedSellerSkuKey)
      }
      return
    }

    const sellerSkuKey = normalizeTextForSku(sellerSku).toLowerCase()
    if (sellerSkuKey && (existingSellerSkuKeys.has(sellerSkuKey) || generatedSellerSkuKeys.has(sellerSkuKey))) {
      sellerSku = ''
    }

    const generatedSku = {
      seller_sku: sellerSku,
      sales_attributes: [{
        ...baseAttribute,
        value_name: colorName,
        ...(imageUri ? { sku_img: { uri: imageUri } } : {})
      }],
      price: {
        currency: 'IDR',
        sale_price: discountedPriceValue,
        tax_exclusive_price: discountedPriceValue,
        amount: discountedPriceValue
      },
      inventory: [{
        quantity: quantityValue,
        warehouse_id: TIKTOK_VARIANT_WAREHOUSE_ID
      }],
      pre_sale: cloneJson(defaultSkuPreSale) || { type: 'NONE' }
    }
    applyDefaultTiktokSkuPreSale(generatedSku, defaultSkuPreSale)
    generatedSkus.push(generatedSku)
    const generatedSellerSkuKey = normalizeTextForSku(sellerSku).toLowerCase()
    if (generatedSellerSkuKey) {
      generatedSellerSkuKeys.add(generatedSellerSkuKey)
    }
  })

  productBody.save_mode = 'LISTING'
  productBody.category_id = '601307'
  productBody.category_version = 'v2'
  productBody.title = productTitle
  productBody.skus = [...existingSkus, ...generatedSkus]

  if (!Array.isArray(productBody.category_chains) || productBody.category_chains.length === 0) {
    productBody.category_chains = [
      {
        id: '601303',
        is_leaf: false,
        local_name: 'Fashion Muslim',
        parent_id: '0'
      },
      {
        id: '601304',
        is_leaf: false,
        local_name: 'Hijab',
        parent_id: '601303'
      },
      {
        id: '601307',
        is_leaf: true,
        local_name: 'Hijab Persegi',
        parent_id: '601304'
      }
    ]
  }

  if (!Array.isArray(productBody.main_images) || productBody.main_images.length === 0) {
    productBody.main_images = tiktokImageUri ? [{ uri: tiktokImageUri }] : []
  }

  const ensureArrayField = (key, fallback = []) => {
    if (!Array.isArray(productBody[key])) {
      productBody[key] = cloneJson(fallback)
    }
  }

  const ensureObjectField = (key, fallback = {}) => {
    if (!productBody[key] || typeof productBody[key] !== 'object' || Array.isArray(productBody[key])) {
      productBody[key] = cloneJson(fallback)
    }
  }

  ensureObjectField('audit', {
    pre_approved_reasons: [],
    status: 'NONE'
  })
  ensureArrayField('manufacturer_ids', [])
  ensureArrayField('recommended_categories', [])
  ensureArrayField('responsible_person_ids', [])
  ensureArrayField('delivery_option_ids', [])
  ensureArrayField('external_urls', [])
  ensureArrayField('extra_identifier_codes', [])
  ensureArrayField('certifications', [])
  ensureArrayField('listing_platforms', ['TIKTOK_SHOP'])
  ensureArrayField('search_terms', [])
  ensureArrayField('key_product_features', [])
  ensureArrayField('product_attributes', [])

  if (!productBody.package_dimensions || typeof productBody.package_dimensions !== 'object') {
    productBody.package_dimensions = {
      height: '0',
      length: '0',
      unit: 'CENTIMETER',
      width: '0'
    }
  }

  if (!productBody.package_weight || typeof productBody.package_weight !== 'object') {
    productBody.package_weight = {
      unit: 'KILOGRAM',
      value: '0'
    }
  }

  if (!productBody.shipping_insurance_requirement) {
    productBody.shipping_insurance_requirement = 'REQUIRED'
  }

  if (!productBody.create_time) {
    productBody.create_time = Math.floor(Date.now() / 1000)
  }

  if (!productBody.update_time) {
    productBody.update_time = Math.floor(Date.now() / 1000)
  }

  if (!productBody.subscribe_info || typeof productBody.subscribe_info !== 'object') {
    productBody.subscribe_info = {
      subscribe_promotion_config: [
        { discount_level: 'REGULAR' },
        { discount_level: 'FIRST_ORDER', max_discount: 99, min_discount: 1 }
      ],
      subscribe_status: 'NOT_ENABLED',
      support_subscribe: false
    }
  }

  if (productBody.has_draft === undefined) productBody.has_draft = false
  if (productBody.is_cod_allowed === undefined) productBody.is_cod_allowed = false
  if (productBody.is_not_for_sale === undefined) productBody.is_not_for_sale = false
  if (productBody.is_pre_owned === undefined) productBody.is_pre_owned = false
  if (productBody.is_replicated === undefined) productBody.is_replicated = false
  if (!productBody.category_version) productBody.category_version = 'v2'

  return JSON.stringify(productBody, null, 2)
}

const apiGetProductResponseHint = computed(() => {
  const payload = apiGetProductResponsePayload.value
  if (!payload) return ''

  if (isShopeeFlow.value) {
    if (String(payload.error || '') === '') {
      return 'Response berhasil diambil langsung dari Shopee Open Platform.'
    }

    return String(payload.message || payload.error || '')
  }

  const code = String(payload.code ?? '')
  const message = String(payload.message ?? '').toLowerCase()

  if (code === '105001' || message.includes('access token is invalid')) {
    return 'Token TikTok aktif di database tidak valid. Jalankan GET TOKEN / REFRESH TOKEN dulu, lalu coba Submit Request lagi.'
  }

  if (code === '0') {
    return 'Response berhasil diambil langsung dari TikTok Shop.'
  }

  return ''
})

const addVariantRequestPreview = computed(() => {
  try {
    return buildAddVariantRequestPreview()
  } catch (error) {
    return JSON.stringify({
      product_id: addVariantTool.product_id || resolveSelectedTiktokProductId() || '',
      shop_cipher: addVariantTool.shop_cipher,
      seller_sku: addVariantTool.seller_sku,
      color_name: addVariantTool.color_name,
      image_uri: addVariantTool.image_uri,
      price: addVariantTool.price,
      discount: addVariantTool.discount,
      quantity: addVariantTool.quantity,
      dry_run: addVariantTool.dry_run,
      preview_error: error?.message || 'Request demo gagal digenerate.'
    }, null, 2)
  }
})

const addVariantResponseLines = computed(() => {
  return responseLinesFromText(addVariantResponseText.value)
})

const tiktokSubmitResponseLines = computed(() => {
  return responseLinesFromText(tiktokSubmitResponseText.value)
})

const copyTextToClipboard = async (value) => {
  const text = String(value ?? '')

  if (navigator.clipboard?.writeText && window.isSecureContext) {
    try {
      await navigator.clipboard.writeText(text)
      return
    } catch {
      // Domain lokal kadang menolak Clipboard API; lanjut pakai fallback.
    }
  }

  const textarea = document.createElement('textarea')
  textarea.value = text
  textarea.setAttribute('readonly', '')
  textarea.style.position = 'fixed'
  textarea.style.left = '-9999px'
  textarea.style.top = '0'

  document.body.appendChild(textarea)
  textarea.focus()
  textarea.select()
  textarea.setSelectionRange(0, text.length)

  const copied = document.execCommand('copy')
  document.body.removeChild(textarea)

  if (!copied) {
    throw new Error('Browser menolak akses clipboard.')
  }
}

const copyAddVariantRequest = async () => {
  try {
    await copyTextToClipboard(addVariantRequestPreview.value)
    loadError.value = ''
  } catch (error) {
    loadError.value = error?.message || 'Copy request gagal.'
  }
}

const copyAddVariantResponse = async () => {
  try {
    if (!String(addVariantResponseText.value || '').trim()) {
      addVariantResponseText.value = addVariantRequestPreview.value
      addVariantResponseStatus.value = 'READY'
    }

    await copyTextToClipboard(addVariantResponseText.value)
    loadError.value = ''
  } catch (error) {
    loadError.value = error?.message || 'Copy response gagal.'
  }
}

const copyTiktokSubmitResponse = async () => {
  try {
    const text = String(tiktokSubmitResponseText.value || '').trim()
    if (!text) {
      throw new Error('Belum ada response TikTok yang bisa disalin.')
    }

    await copyTextToClipboard(text)
    loadError.value = ''
  } catch (error) {
    loadError.value = error?.message || 'Copy response TikTok gagal.'
  }
}

const loadAddVariantContext = async () => {
  if (isShopeeFlow.value) {
    await loadShopeeApiTestContext()
    const itemId = resolveSelectedShopeeItemId()
    if (itemId && !String(addVariantTool.product_id || '').trim()) {
      addVariantTool.product_id = itemId
    }
    return
  }

  try {
    const response = await omnichannelService.tiktokGetProductContext()
    const data = response.data?.data || {}

    if (data.shop_cipher) addVariantTool.shop_cipher = data.shop_cipher
    if (!String(addVariantTool.product_id || '').trim() && data.product_id) {
      addVariantTool.product_id = data.product_id
    }
  } catch {
    // Tetap biarkan user isi manual bila context belum tersedia.
  }
}

const openTiktokSubmitDialog = () => {
  loadError.value = ''

  try {
    const payloadText = buildAddVariantRequestPreview()
    const meta = buildTiktokSubmitMeta()

    if (!meta.product_id) {
      throw new Error('Product ID TikTok belum diisi.')
    }

    pendingTiktokSubmitPayload.value = payloadText
    pendingTiktokSubmitMeta.product_id = meta.product_id
    pendingTiktokSubmitMeta.version = meta.version
    pendingTiktokSubmitMeta.shop_cipher = meta.shop_cipher
    addVariantResponseText.value = payloadText
    addVariantResponseStatus.value = 'READY'
    tiktokSubmitResponseText.value = ''
    tiktokSubmitResponseStatus.value = ''
    tiktokSubmitResponseHint.value = ''
    tiktokSubmitDialogOpen.value = true
  } catch (error) {
    pendingTiktokSubmitPayload.value = ''
    pendingTiktokSubmitMeta.product_id = ''
    pendingTiktokSubmitMeta.version = ''
    pendingTiktokSubmitMeta.shop_cipher = ''
    loadError.value = error?.message || 'Payload TikTok gagal disiapkan.'
  }
}

const closeTiktokSubmitDialog = () => {
  if (tiktokSubmitBusy.value) return
  tiktokSubmitDialogOpen.value = false
}

const applyTiktokSubmitResponse = (responseText, statusCode, hint = '') => {
  tiktokSubmitResponseText.value = responseText
  tiktokSubmitResponseStatus.value = statusCode === null || statusCode === undefined || statusCode === ''
    ? ''
    : String(statusCode)
  tiktokSubmitResponseHint.value = hint
}

const confirmSubmitTiktokPayload = async () => {
  if (!pendingTiktokSubmitPayload.value.trim()) {
    loadError.value = 'Payload TikTok belum siap.'
    return
  }

  tiktokSubmitBusy.value = true
  loadError.value = ''
  tiktokSubmitResponseText.value = ''
  tiktokSubmitResponseStatus.value = ''
  tiktokSubmitResponseHint.value = ''

  try {
    const response = await omnichannelService.tiktokSubmitGeneratedPayload({
      product_id: pendingTiktokSubmitMeta.product_id,
      version: pendingTiktokSubmitMeta.version,
      shop_id: getProductTool.shop_id || '',
      shop_cipher: pendingTiktokSubmitMeta.shop_cipher,
      access_token: getProductTool.access_token || '',
      payload_json: pendingTiktokSubmitPayload.value
    })

    const responseText = formatResponseText(response.data)
    const parsed = parseResponseJson(responseText)
    const statusCode = parsed?.code ?? response.status ?? ''
    const responseHint = parsed?.message
      ? String(parsed.message)
      : Number(statusCode) === 0
        ? 'Response berhasil diterima langsung dari TikTok Shop.'
        : `Response TikTok diterima dengan status ${statusCode || response.status || '-'}.`

    applyTiktokSubmitResponse(
      responseText,
      statusCode,
      responseHint
    )
  } catch (error) {
    const responseData = error.response?.data
    const responseText = formatResponseText(responseData || {
      message: error.message || 'Submit TikTok gagal diproses.'
    })
    const parsed = parseResponseJson(responseText)

    applyTiktokSubmitResponse(
      responseText,
      error.response?.status || parsed?.code || 'ERROR',
      parsed?.message
        ? String(parsed.message)
        : (typeof responseData === 'object' && responseData && 'message' in responseData
          ? String(responseData.message)
          : error.message || 'Submit TikTok gagal diproses.')
    )
    loadError.value = (typeof responseData === 'object' && responseData && 'message' in responseData
      ? String(responseData.message)
      : error.message || 'Submit TikTok gagal diproses.')
  } finally {
    tiktokSubmitBusy.value = false
    tiktokSubmitDialogOpen.value = false
  }
}

const submitAddVariant = async () => {
  addVariantBusy.value = true
  loadError.value = ''
  addVariantResponseText.value = ''
  addVariantResponseStatus.value = '0'

  try {
    if (isShopeeFlow.value) {
      const payload = buildShopeeAddVariantPayload()
      if (!String(payload.item_id || '').trim()) {
        throw new Error('Item ID Shopee target belum diisi.')
      }
      if (!payload.variants.length) {
        throw new Error('Belum ada varian TikTok yang valid untuk dikirim ke Shopee.')
      }

      const response = await omnichannelService.shopeeAddVariant(payload)
      const responseText = formatResponseText(response.data)
      const parsed = parseResponseJson(responseText)
      addVariantResponseText.value = responseText
      addVariantResponseStatus.value = String(parsed?.status || parsed?.error || response.status || 'OK')

      if (response.status >= 200 && response.status < 300 && parsed?.status !== 'error') {
        if (!payload.dry_run) {
          await loadData(false, {
            bypassCache: true,
            preserveSelection: true
          })
        }
        return
      }

      loadError.value = parsed?.message || 'Request Shopee gagal diproses.'
      return
    }

    addVariantResponseText.value = buildAddVariantRequestPreview()
    addVariantResponseStatus.value = 'READY'
  } catch (error) {
    addVariantResponseText.value = JSON.stringify({
      message: isShopeeFlow.value
        ? 'Request tambah varian Shopee gagal diproses.'
        : 'Payload tambah variant gagal digenerate dari response GET Product.',
      error: error?.message || 'Request demo gagal digenerate.'
    }, null, 2)
    addVariantResponseStatus.value = 'ERROR'
    loadError.value = error?.message || (isShopeeFlow.value ? 'Request Shopee gagal diproses.' : 'Payload tambah variant gagal digenerate.')
  } finally {
    addVariantBusy.value = false
  }
}

const copyGetProductCurl = async () => {
  try {
    await copyTextToClipboard(apiGetProductCurl.value)
    loadError.value = ''
  } catch (error) {
    loadError.value = error?.message || 'Copy cURL gagal.'
  }
}

const copyGetProductResponse = async () => {
  try {
    await copyTextToClipboard(getProductResponseText.value)
    loadError.value = ''
  } catch (error) {
    loadError.value = error?.message || 'Copy response gagal.'
  }
}

const loadShopeeApiTestContext = async () => {
  try {
    const response = await omnichannelService.shopeeApiTestContext()
    const data = response.data?.data || {}

    if (data.account_key) shopeeProductTool.account_key = data.account_key
    if (data.shop_id) shopeeProductTool.shop_id = data.shop_id
    if (data.access_token) shopeeProductTool.access_token = data.access_token
  } catch {
    // Kalau context Shopee belum tersedia, user tetap bisa isi manual.
  }
}

const loadGetProductContext = async () => {
  if (isShopeeFlow.value) {
    await loadShopeeApiTestContext()
    return
  }

  try {
    const response = await omnichannelService.tiktokGetProductContext()
    const data = response.data?.data || {}

    if (data.shop_id) getProductTool.shop_id = data.shop_id
    if (data.shop_cipher) getProductTool.shop_cipher = data.shop_cipher
    if (data.access_token) getProductTool.access_token = data.access_token
    if (data.version) getProductTool.version = data.version
  } catch {
    // Kalau context belum tersedia, user tetap bisa lanjut pakai nilai yang ada.
  }
}

const extractResponseText = async (response) => {
  const contentType = response.headers.get('content-type') || ''
  if (contentType.includes('application/json')) {
    try {
      const data = await response.json()
      return JSON.stringify(data, null, 2)
    } catch {
      return await response.text()
    }
  }

  return await response.text()
}

const submitShopeeApiTestDemo = async () => {
  if (resolveSelectedShopeeItemId() && !String(shopeeProductTool.item_id || '').trim()) {
    shopeeProductTool.item_id = resolveSelectedShopeeItemId()
  }

  const response = await omnichannelService.shopeeApiTest(buildShopeeApiTestPayload())
  const responseText = formatResponseText(response.data)
  const parsed = parseResponseJson(responseText)

  getProductResponseText.value = responseText
  getProductResponseStatus.value = String(
    parsed?.error
      ? parsed.error
      : (response.status || 'OK')
  )
}

const submitGetProductDemo = async () => {
  getProductRequestBusy.value = true
  loadError.value = ''
  getProductResponseText.value = ''
  getProductResponseStatus.value = '0'

  try {
    if (isShopeeFlow.value) {
      await submitShopeeApiTestDemo()
      return
    }

    if (resolveSelectedTiktokProductId() && !String(getProductTool.product_id || '').trim()) {
      getProductTool.product_id = resolveSelectedTiktokProductId()
    }

    const response = await fetch('/api/tiktok/get-product', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json'
      },
      body: JSON.stringify({
      product_id: getProductTool.product_id || resolveSelectedTiktokProductId() || '',
      version: getProductTool.version,
      shop_id: getProductTool.shop_id,
      shop_cipher: getProductTool.shop_cipher,
      access_token: getProductTool.access_token,
      return_under_review_version: getProductTool.return_under_review_version,
      return_draft_version: getProductTool.return_draft_version,
      locale: getProductTool.locale
      })
    })

    const responseText = await extractResponseText(response)
    getProductResponseText.value = responseText
    const parsed = parseResponseJson(responseText)
    getProductResponseStatus.value = String(parsed?.code ?? response.status ?? 0)

    if (Number(parsed?.code ?? -1) === 0) {
      const returnedProductId = extractTiktokProductIdFromPayload(parsed)
      if (returnedProductId) {
        getProductTool.product_id = returnedProductId
      }

      try {
        await refreshMappingTableAfterGetProduct(returnedProductId)
      } catch (refreshError) {
        loadError.value = refreshError?.message || 'Data tabel gagal di-refresh setelah Get Product.'
      }
    }
  } catch (error) {
    getProductResponseText.value = error?.message ? String(error.message) : ''
    getProductResponseStatus.value = '0'
    loadError.value = error.message || 'Request TikTok gagal diproses.'
  } finally {
    getProductRequestBusy.value = false
  }
}

const loadData = async (resetPage = false, options = {}) => {
  const {
    bypassCache = false,
    preserveSelection = false
  } = options

  loading.value = true
  loadError.value = ''
  if (resetPage) pagination.page = 1
  const effectiveBypassCache = bypassCache || resetPage
  const cacheKey = JSON.stringify({
    flow: flowKey.value,
    search: filters.search,
    status: filters.status,
    sort: 'updated_desc',
    page: pagination.page,
    per_page: pagination.per_page
  })
  const selectedIdBeforeRefresh = preserveSelection ? selectedItem.value?.id || null : null
  try {
    if (!effectiveBypassCache && pageCache.has(cacheKey)) {
      const cached = pageCache.get(cacheKey)
      items.value = cached.items
      Object.assign(pagination, cached.pagination)
      expandAllGroups()
      syncSelectedVariantSnapshots(items.value)
      if (preserveSelection) {
        selectedItem.value = selectedIdBeforeRefresh
          ? items.value.find((item) => item.id === selectedIdBeforeRefresh) || null
          : null
      } else {
        const firstCached = activeGroup.value?.variants?.[0] || null
        if (firstCached) {
          selectItem(firstCached)
        } else {
          selectedItem.value = null
          resetForm()
        }
      }
      return
    }

    const response = await omnichannelService.skuMapping({
      flow: flowKey.value,
      search: filters.search,
      status: filters.status,
      sort: 'updated_desc',
      page: pagination.page,
      per_page: pagination.per_page
    })
    items.value = response.data.items || []
    Object.assign(pagination, response.data.pagination || {})
    pageCache.set(cacheKey, {
      items: items.value,
      pagination: { ...pagination }
    })
    expandAllGroups()
    syncSelectedVariantSnapshots(items.value)
    if (preserveSelection) {
      selectedItem.value = selectedIdBeforeRefresh
        ? items.value.find((item) => item.id === selectedIdBeforeRefresh) || null
        : null
    } else {
      const first = activeGroup.value?.variants?.[0] || null
      if (first) {
        selectItem(first)
      } else {
        selectedItem.value = null
        resetForm()
      }
    }
  } catch (error) {
    loadError.value = error.response?.data?.message || 'Data tambah varian gagal dimuat.'
    items.value = []
    selectedItem.value = null
    expandedGroups.value = {}
    resetForm()
  } finally {
    loading.value = false
  }
}

const refreshMappingTableAfterGetProduct = async (focusProductId = '') => {
  await loadData(false, {
    bypassCache: true,
    preserveSelection: true
  })

  const targetProductId = String(focusProductId || getProductTool.product_id || '').trim()
  if (!targetProductId) return

  const refreshed = items.value.find((item) => resolveTiktokProductId(item) === targetProductId)
    || groupedItems.value.find((group) => group.variants.some((item) => resolveTiktokProductId(item) === targetProductId))
      ?.variants?.[0]
    || null

  if (refreshed) {
    selectItem(refreshed, { resetGetProductResponse: false })
  }
}

const changePage = async (nextPage) => {
  const targetPage = Math.max(1, Number(nextPage) || 1)
  if (targetPage === pagination.page) return
  pagination.page = targetPage
  await loadData()
}

const selectItem = (item, options = {}) => {
  const { resetGetProductResponse = true } = options
  const group = groupedItems.value.find((candidate) => candidate.key === getGroupKey(item)) || null
  const resolvedProductId = resolveTiktokProductId(item) || resolveGroupTiktokProductId(group)
  const resolvedSkuId = resolveTiktokSkuId(item)
  const resolvedShopeeItemId = resolveShopeeItemId(item) || resolveGroupShopeeItemId(group)

  selectedItem.value = item
  form.stock_master_id = item.stock_master_id || (typeof item.id === 'number' ? item.id : null)
  form.shopee_item_id = item.shopee?.item_id || ''
  form.shopee_model_id = item.shopee?.model_id || ''
  form.seller_sku = item.seller_sku || item.shopee?.seller_sku || item.tiktok?.seller_sku || ''
  form.tiktok_product_id = resolvedProductId
  form.tiktok_sku_id = resolvedSkuId
  form.tiktok_sku_name = item.tiktok?.variant_name || item.tiktok?.sku_name || item.variant_name || ''
  form.warehouse_id = item.tiktok?.warehouse_id || form.warehouse_id || ''
  form.inventory_qty = Number(item.tiktok?.stock_qty ?? item.stock_qty ?? 0)
  form.notes = item.notes || ''
  getProductTool.product_id = resolvedProductId
  shopeeProductTool.item_id = resolvedShopeeItemId
  if (resetGetProductResponse) {
    getProductResponseText.value = ''
    getProductResponseStatus.value = '0'
  }
  fillAddVariantToolFromItem(item, isShopeeFlow.value ? resolvedShopeeItemId : resolvedProductId)
}

const fillFromSelected = () => {
  if (selectedItem.value) selectItem(selectedItem.value)
}

const save = async () => {
  if (!form.stock_master_id) return
  saving.value = true
  loadError.value = ''
  try {
    await omnichannelService.saveSkuMapping({ ...form })
    await loadData(false, {
      bypassCache: true,
      preserveSelection: true
    })
  } catch (error) {
    loadError.value = error.response?.data?.message || 'Mapping gagal disimpan.'
  } finally {
    saving.value = false
  }
}

const prepareMissingVariant = async (item) => {
  const targetChannel = missingTargetChannel(item)
  if (!targetChannel) return

  preparing.value = true
  loadError.value = ''
  try {
    await omnichannelService.prepareMissingVariant({
      stock_master_id: item.stock_master_id || item.id,
      target_channel: targetChannel
    })
    await loadData(false, {
      bypassCache: true,
      preserveSelection: true
    })
    const refreshed = activeGroup.value?.variants?.find((candidate) => candidate.stock_master_id === item.stock_master_id) || activeGroup.value?.variants?.[0]
    if (refreshed) selectItem(refreshed)
  } catch (error) {
    loadError.value = error.response?.data?.message || 'Draft varian gagal disiapkan.'
  } finally {
    preparing.value = false
  }
}

const runTiktokAction = async (action) => {
  if (!form.stock_master_id && selectedItem.value) {
    form.stock_master_id = selectedItem.value.stock_master_id || selectedItem.value.id
  }

  if (!form.stock_master_id) return
  if ((action === 'update_inventory' || action === 'full_sync') && !String(form.warehouse_id || '').trim()) {
    loadError.value = 'Warehouse ID TikTok wajib diisi untuk update stok.'
    return
  }

  actionBusy.value = true
  loadError.value = ''
  actionLog.value = ''
  try {
    const response = await omnichannelService.tiktokVariantAction({
      stock_master_id: form.stock_master_id,
      action,
      warehouse_id: form.warehouse_id,
      quantity: form.inventory_qty
    })

    actionLog.value = response.data?.message || 'Aksi TikTok berhasil diproses.'
    await loadData(false, {
      bypassCache: true,
      preserveSelection: true
    })
    const refreshed = activeGroup.value?.variants?.find((candidate) => candidate.stock_master_id === form.stock_master_id) || activeGroup.value?.variants?.[0]
    if (refreshed) selectItem(refreshed)
  } catch (error) {
    loadError.value = error.response?.data?.message || 'Aksi TikTok gagal diproses.'
  } finally {
    actionBusy.value = false
  }
}

onMounted(async () => {
  if (isShopeeFlow.value) {
    addVariantTool.product_id = ''
  }

  await Promise.all([
    loadData(true),
    loadGetProductContext(),
    loadAddVariantContext()
  ])
})
</script>

<style scoped>
.page-shell { margin-left: 240px; padding: 24px; }
.page-header { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:16px; }
.page-header p { color:#64748b; margin-bottom:4px; }
.subtitle { display:block; margin-top:4px; color:#64748b; }
.header-actions { display:flex; gap:10px; }
.primary,.ghost,.mini { border:0; border-radius:6px; cursor:pointer; }
.primary,.ghost { padding:10px 14px; }
.primary { background:#0f5fc7; color:#fff; }
.ghost { background:#fff; border:1px solid #d9e2ec; color:#334155; }
.primary:disabled,.ghost:disabled { cursor:wait; opacity:.72; }
.notice { color:#334155; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px 12px; margin-bottom:12px; font-size:13px; }
.notice.error { color:#991b1b; background:#fef2f2; border-color:#fecaca; }
.control-band { display:flex; gap:12px; align-items:end; margin-bottom:12px; padding:12px; background:#fff; border:1px solid #d9e2ec; border-radius:8px; }
.control-band label { flex:1; }
.control-band span { display:block; color:#64748b; font-size:12px; margin-bottom:6px; }
.control-band input,
.control-band select { width:100%; border:1px solid #d7dde8; border-radius:6px; padding:10px; font-size:13px; background:#fff; }
.summary-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; margin-bottom:12px; }
.metric { background:#fff; border:1px solid #d9e2ec; border-radius:8px; padding:14px; }
.metric span { display:block; color:#64748b; font-size:12px; margin-bottom:6px; }
.metric strong { display:block; font-size:18px; color:#111827; line-height:1.2; }
.metric small { color:#64748b; display:block; margin-top:6px; }
.layout { display:grid; grid-template-columns:minmax(0,1fr) 340px; gap:12px; }
.layout.list-only { grid-template-columns:minmax(0,1fr); }
.panel { background:#fff; border:1px solid #d9e2ec; border-radius:8px; padding:14px; }
.group-head { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:12px; }
.group-title { display:flex; gap:12px; align-items:flex-start; min-width:0; }
.group-title strong { display:block; color:#111827; font-size:18px; line-height:1.25; margin-bottom:4px; }
.group-title small { color:#64748b; display:block; margin-top:3px; }
.group-empty { margin-bottom:12px; padding:12px; border:1px dashed #cbd5e1; border-radius:8px; color:#64748b; background:#f8fafc; }
.empty-row { text-align:center; color:#64748b; padding:20px !important; }
.table-filters { display:flex; align-items:end; gap:12px; margin: 0 0 12px; padding: 12px; background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; }
.table-filters label { min-width: 260px; }
.table-filters span { display:block; color:#64748b; font-size:12px; margin-bottom:6px; }
.table-filters select { width:100%; border:1px solid #d7dde8; border-radius:6px; padding:10px; font-size:13px; background:#fff; }
.filter-hint { color:#64748b; font-size:12px; }
.table-wrap {
  height: 860px;
  overflow-y: auto;
  overflow-x: auto;
  border: 1px solid #e5e7eb;
  border-radius: 4px;
  background: #fff;
  scrollbar-gutter: stable;
}
.api-panel { margin-top:14px; padding:0; border:0; background:transparent; }
.api-panel-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; margin-bottom:12px; }
.api-panel-head strong { display:block; color:#111827; font-size:18px; line-height:1.2; }
.api-panel-head small { color:#64748b; display:block; margin-top:4px; }
.api-panel-head a { color:#0f766e; text-decoration:none; font-weight:600; }
.api-testing-tool { display:grid; grid-template-columns:minmax(0,1fr) minmax(360px, 0.95fr); gap:0; border:1px solid #e5e7eb; border-radius:4px; overflow:hidden; background:#fff; }
.api-left, .api-right { min-width:0; padding:18px; }
.api-left { border-right:1px solid #e5e7eb; }
.api-apirow { display:grid; grid-template-columns:72px minmax(0,1fr); gap:12px; padding:12px; border:1px solid #e5e7eb; background:#fff; align-items:center; }
.api-label { color:#6b7280; font-size:12px; line-height:1.2; }
.api-apiname { display:flex; justify-content:space-between; align-items:center; gap:12px; min-width:0; }
.api-apiname strong { display:block; color:#111827; font-size:13px; line-height:1.25; }
.api-apiname small { display:block; color:#6b7280; font-size:12px; margin-top:2px; }
.api-chip { flex:0 0 auto; border-radius:4px; background:#e5e7eb; color:#444; font-size:12px; font-weight:700; padding:5px 9px; }
.api-version-row { display:flex; justify-content:space-between; align-items:flex-end; gap:12px; margin:14px 0 10px; }
.api-inline { display:grid; gap:6px; width:170px; }
.api-inline span { color:#6b7280; font-size:12px; }
.api-inline select, .api-shop-auth input, .api-path-params input, .api-query-params input { width:100%; border:1px solid #d1d5db; border-radius:4px; padding:8px 10px; font-size:13px; background:#fff; }
.api-inline select:disabled, .api-shop-auth input:disabled { background:#f3f4f6; color:#9ca3af; }
.api-doc-link, .api-shop-link { color:#0ea5a1; font-size:12px; font-weight:700; text-decoration:none; }
.api-section-title { font-size:14px; font-weight:600; color:#111827; margin:18px 0 10px; }
.api-shop-auth, .api-path-params, .api-query-params { position:relative; }
.api-shop-auth { padding-top:18px; border-top:1px solid #f1f5f9; }
.api-shop-link { position:absolute; right:0; top:-8px; }
.api-shop-auth label, .api-path-params label, .api-query-params label { display:block; margin-bottom:12px; }
.api-shop-auth span, .api-path-params span, .api-query-params span { display:block; color:#6b7280; font-size:12px; margin-bottom:6px; }
.api-shop-auth em, .api-path-params em, .api-query-params em { font-style:normal; color:#9ca3af; }
.field-hint { margin:-6px 0 12px; color:#64748b; font-size:12px; line-height:1.45; }
.api-bool-row { margin-bottom:12px; }
.api-bool-row > span { display:block; color:#6b7280; font-size:12px; margin-bottom:6px; }
.api-radio-group { display:flex; align-items:center; gap:18px; color:#111827; font-size:13px; }
.api-radio-group label { display:flex; align-items:center; gap:6px; margin:0; }
.api-submit-row { display:flex; justify-content:flex-end; margin-top:14px; }
.api-submit-row .primary { padding:9px 16px; }
.api-right { background:#fff; }
.api-right-block { margin-bottom:16px; }
.api-right-head { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:10px; }
.api-right-head strong { color:#111827; font-size:13px; }
.api-status-line { display:flex; align-items:center; gap:8px; color:#6b7280; font-size:12px; margin-bottom:8px; }
.status-dot { display:inline-flex; align-items:center; justify-content:center; gap:6px; color:#111827; font-weight:700; }
.status-dot::before { content:''; width:10px; height:10px; border-radius:999px; background:#16a34a; box-shadow:0 0 0 2px rgba(22,163,74,.15); display:inline-block; }
.api-right-block pre { margin:0; padding:12px; border:1px solid #e5e7eb; background:#fff; font-size:12px; line-height:1.55; color:#111827; overflow:auto; white-space:pre-wrap; word-break:break-word; max-height:460px; }
.api-right-block:first-of-type pre { max-height:150px; }
.variant-tool .api-right-block:first-of-type pre { max-height:280px; min-height:280px; }
.variant-tool .tiktok-response-block .api-response-viewer { max-height:280px; min-height:280px; }
.variant-tool .tiktok-response-block pre { max-height:none; min-height:280px; }
.api-response-hint { margin:8px 0 10px; color:#b45309; font-size:12px; line-height:1.45; background:#fffbeb; border:1px solid #fcd34d; border-radius:4px; padding:8px 10px; }
.payload-summary-hint { margin-top:10px; }
.api-response-viewer { display:grid; grid-template-columns:44px minmax(0,1fr); border:1px solid #e5e7eb; background:#fff; max-height:460px; overflow:auto; }
.variant-tool .api-response-viewer { max-height:280px; min-height:280px; }
.api-response-lines { background:#f8fafc; border-right:1px solid #e5e7eb; color:#94a3b8; font-size:12px; line-height:1.55; text-align:right; padding:12px 8px 12px 0; user-select:none; }
.api-response-lines span { display:block; min-height:1.55em; }
.api-response-viewer pre { margin:0; padding:12px; border:0; max-height:none; }
.variant-panel { margin-top:14px; }
.variant-tool { display:grid; grid-template-columns:minmax(0,1fr) minmax(360px, 0.95fr); gap:0; border:1px solid #e5e7eb; border-radius:4px; overflow:hidden; background:#fff; }
.variant-panel .api-right { padding-top:12px; }
.mapping-table { width:100%; border-collapse:collapse; font-size:13px; min-width:1240px; table-layout:fixed; }
.col-product { width:28%; }
.col-channel { width:31%; }
.col-status { width:10%; }
.col-check { width:56px; }
th,td { border-bottom:1px solid #e5e7eb; padding:8px 10px; vertical-align:top; text-align:left; }
thead th { position:sticky; top:0; background:#1f2937; color:#fff; z-index:1; }
tbody:last-child tr:last-child td { border-bottom:0; }
td small { color:#64748b; display:block; margin-top:2px; line-height:1.15; }
.product-row { background:#f8fafc; }
.product-row td { padding:10px 12px; }
.product-row-inner { display:flex; align-items:center; gap:10px; min-width:0; cursor:pointer; }
.group-toggle { width:28px; height:28px; padding:0; display:grid; place-items:center; background:#e2e8f0; color:#0f172a; font-weight:800; flex:0 0 auto; }
.thumb.small { width:42px; height:42px; flex:0 0 auto; }
.group-copy { min-width:0; flex:1; }
.group-copy strong { display:block; color:#111827; font-size:14px; line-height:1.25; margin-bottom:2px; }
.group-copy small { color:#64748b; display:block; margin-top:2px; line-height:1.15; }
.group-meta { display:flex; flex-direction:column; align-items:flex-end; gap:6px; text-align:right; flex:0 0 auto; }
.group-meta strong { display:block; color:#111827; font-size:12px; line-height:1.2; }
.variant-row { cursor:pointer; }
.variant-row:hover,.variant-row.active { background:#eff6ff; }
.check-col { text-align:center; vertical-align:middle; }
.check-col input { width:18px; height:18px; accent-color:#0f5fc7; cursor:pointer; }
.variant-title strong { display:block; margin-bottom:2px; font-size:12px; line-height:1.2; }
.variant-title small { font-size:10px; }
.channel-cell { display:grid; grid-template-columns:44px minmax(0,1fr); gap:8px; align-items:start; min-width:0; }
.channel-cell strong { display:block; line-height:1.2; margin-bottom:2px; font-size:12px; }
.channel-cell small,.variant-title small { overflow-wrap:anywhere; }
.channel-cell.muted { color:#64748b; }
.channel-title-row { display:flex; align-items:flex-start; justify-content:space-between; gap:8px; min-width:0; }
.channel-title-row strong { min-width:0; overflow-wrap:anywhere; }
.channel-badge { flex:0 0 auto; border-radius:999px; padding:3px 7px; font-size:11px; font-weight:800; line-height:1.2; }
.channel-badge.mapped { color:#047857; background:#d1fae5; }
.channel-badge.suggested { color:#b45309; background:#fef3c7; }
.thumb { width:44px; height:44px; border-radius:6px; object-fit:cover; background:#eef2f7; border:1px solid #e2e8f0; }
.thumb.large { width:82px; height:82px; }
.fallback { display:grid; place-items:center; color:#64748b; font-weight:800; font-size:12px; }
.mini { display:block; margin-top:8px; padding:6px 9px; background:#e2e8f0; color:#334155; font-size:12px; }
.badge { display:inline-block; border-radius:999px; padding:4px 8px; font-size:12px; font-weight:700; }
.badge.ready_to_sync { background:#d1fae5; color:#047857; }
.badge.shopee_missing { background:#fff7ed; color:#c2410c; }
.badge.tiktok_missing { background:#eff6ff; color:#1d4ed8; }
.badge.belum_ada_variant { background:#eef2f7; color:#475569; }
.dialog-backdrop { position:fixed; inset:0; z-index:50; background:rgba(15,23,42,.58); display:grid; place-items:center; padding:20px; }
.dialog-card { width:min(560px, 100%); background:#fff; border:1px solid #e2e8f0; border-radius:16px; box-shadow:0 30px 80px rgba(15,23,42,.28); padding:20px; }
.dialog-header { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:12px; }
.dialog-header p { color:#64748b; font-size:12px; margin-bottom:4px; text-transform:uppercase; letter-spacing:.08em; font-weight:700; }
.dialog-header h3 { font-size:20px; color:#0f172a; line-height:1.2; }
.dialog-copy { color:#475569; margin-bottom:14px; line-height:1.55; }
.dialog-meta { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:10px; margin:0 0 16px; }
.dialog-meta div { border:1px solid #e2e8f0; border-radius:10px; padding:10px 12px; background:#f8fafc; }
.dialog-meta dt { color:#64748b; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; margin-bottom:4px; }
.dialog-meta dd { margin:0; color:#0f172a; font-size:13px; overflow-wrap:anywhere; }
.dialog-actions { display:flex; justify-content:flex-end; gap:10px; }
@media (max-width: 1180px) { .layout { grid-template-columns:1fr; } .api-testing-tool, .variant-tool { grid-template-columns:1fr; } .api-left { border-right:0; border-bottom:1px solid #e5e7eb; } }
@media (max-width: 820px) { .page-shell { margin-left:0; padding:16px; } .summary-grid,.control-band { grid-template-columns:1fr; flex-direction:column; align-items:stretch; } .page-header { align-items:flex-start; flex-direction:column; } .api-version-row { flex-direction:column; align-items:stretch; } .api-shop-link { position:static; display:block; margin-bottom:10px; } .api-left, .api-right { padding:14px; } .dialog-meta { grid-template-columns:1fr; } .dialog-actions { flex-direction:column; } .dialog-actions .ghost, .dialog-actions .primary { width:100%; } .table-wrap { height: auto; max-height: none; overflow: visible; } }
</style>

