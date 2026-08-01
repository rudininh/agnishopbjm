# Shopee Missing SKU Bulk Update Design

## Goal

Make Shopee variants whose seller SKU is empty easy to find and repair from the Stok Shopee page. A user can update all eligible empty Shopee SKUs in one confirmed operation without changing existing Shopee SKUs or any TikTok SKU.

## Scope

- Move products that contain at least one Shopee variant with an empty `model_sku` to the beginning of the filtered result set, before client-side pagination.
- Replace the existing pale-yellow missing-SKU treatment with a high-contrast blue treatment and a readable missing-SKU badge.
- Add a single bulk action on Stok Shopee that fills every eligible empty Shopee variant SKU from its established internal SKU template.
- Show confirmation and a result summary for updated, skipped, and failed variants.

## Out Of Scope

- Do not create arbitrary SKU values.
- Do not overwrite non-empty Shopee SKUs.
- Do not update TikTok SKUs.
- Do not change unrelated Marketplace Auto Sync bulk-SKU behavior.

## Existing Behavior

`ShopeeStock.vue` detects empty Shopee SKUs by testing `model.model_sku`. Its existing per-variant update action sends `channel: shopee`, `item_id`, `model_id`, and a target SKU to `updateMarketplaceVariantSku`.

The existing SKU template is the current source of truth for a missing SKU: use a non-placeholder model SKU when present; otherwise use `kode_variasi`; otherwise use the existing generated `INT-<item-id>-<variant>` form. The bulk action must use this same resolution logic.

## User Experience

### Ordering

Within the items remaining after the current tab and filter conditions, products containing missing variant SKUs are sorted first. The active `Sort By` choice orders products within the missing-SKU group and within the normal group. Client-side pagination then slices this ordered result, guaranteeing that affected products are on page 1.

### Visual State

Affected product and variant rows use a bright light-blue background, a stronger blue border or left accent, and a `SKU Shopee Kosong` badge. The normal table palette remains unchanged. No pale warm-yellow missing-SKU treatment remains on the Shopee page.

### Bulk Action

The header provides an `Isi Semua SKU Kosong` action beside existing product loading controls. It is disabled while the initial table load or a bulk operation is in progress and when no variant SKU is missing.

Clicking the action opens a confirmation dialog containing the number of eligible missing-SKU variants. Confirmation starts the request. The action processes all currently stored Shopee variants that have an empty `model_sku` and a non-empty resolved template SKU. Existing values are never replaced.

After completion, the page reloads Shopee items and shows a concise summary containing:

- updated: Shopee accepted the SKU update;
- skipped: no usable template SKU or the SKU was already present when processed;
- failed: the API rejected the update or the request failed.

## Backend Contract

Create a Shopee-specific endpoint under the SKU mapping API group. The endpoint will:

1. Load Shopee products and models from the local Shopee cache.
2. Select only models where `model_sku` is blank.
3. Resolve the SKU using the same template rules as the existing individual Shopee update path.
4. Re-check that the SKU is still blank immediately before each update.
5. Call the established Shopee variant-SKU update mechanism with `channel: shopee`, item ID, model ID, and the resolved template SKU.
6. Return status, total candidates, updated count, skipped count, failed count, and per-variant result details.

The endpoint must not invoke the Marketplace Auto Sync bulk endpoint because that endpoint can apply both Shopee and TikTok updates and is limited to mapping candidates.

## Frontend Components And Data Flow

`ShopeeStock.vue` owns the confirmation state, bulk request busy state, and result message. `frontend/src/services/index.js` exposes the dedicated API call.

The page computes the missing-SKU count from its loaded items for the button and confirmation text. The backend remains authoritative for actual candidates because data can change between page load and confirmation.

## Error Handling

- A failed whole request leaves the table intact and displays the backend error message.
- Individual failures do not prevent subsequent candidates from being processed.
- The response reports partial completion with warning tone when one or more variants fail or are skipped.
- The button remains disabled until the request completes, preventing duplicate bulk submissions.

## Verification

- Add focused backend coverage for candidate selection, SKU template resolution, no-overwrite behavior, Shopee-only behavior, and result counts.
- Add or update frontend coverage for missing-SKU-first ordering and the bulk-action request state where the existing test setup supports it.
- Run relevant frontend build and backend tests.
- Manually verify at `/stok-shopee` that affected products appear on page 1, use the blue treatment, and that the bulk operation refreshes the displayed data with a correct summary.
