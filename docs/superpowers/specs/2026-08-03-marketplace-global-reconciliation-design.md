# Global Marketplace Variant Reconciliation Design

## Goal

Replace the selected-product reconciliation screen with an operational list of all
safe-to-identify Shopee-TikTok variant anomalies. Shopee is the source for SKU,
variant name, image, and stock. TikTok price remains unchanged.

## User Experience

`/sinkronisasi-varian-marketplace` loads all detected anomalies in one table
grouped by product, without a product dropdown or pagination. The page shows
product and variant rows, current Shopee and TikTok values, the template SKU,
anomaly badges, and the last sync result.

The page provides one `Sinkronkan Semua Anomali` action. It processes product
batches sequentially and reports completed, skipped, review-required, and failed
rows. A product can also be retried independently after a failure.

## Classification

Each row can have more than one classification:

1. `tiktok_sku_mismatch`: Shopee matches its template SKU but TikTok has a
   different seller SKU. Update TikTok to the Shopee SKU.
2. `shopee_sku_template_mismatch`: Shopee SKU differs from the canonical
   `INT-{shopee_item_id}-{variant_name}` template. Update Shopee first, then
   update TikTok to that same canonical SKU.
3. `tiktok_image_mismatch`: TikTok variant image differs from the freshly
   downloaded Shopee variant image. Refresh the Shopee cache and update TikTok
   with the verified image asset.
4. `tiktok_stock_mismatch`: TikTok stock differs from Shopee stock. Update
   TikTok stock to the current Shopee value.
5. `tiktok_orphan`: An owned TikTok variant no longer exists in Shopee. Delete
   it only when exact ownership is proved; otherwise classify it as
   `manual_review` and perform no mutation.

Rows with ambiguous matching, duplicate seller SKUs, missing source images, or
unreadable product details are `manual_review`. They are visible but never
included in automatic mutations.

## Data Flow

The list endpoint reads local catalog caches to render all records efficiently.
It returns a stable row identifier, product IDs, variant identity, current
Shopee/TikTok values, canonical template SKU, classifications, and a summary.

The submit endpoint accepts only server-issued row identifiers and a revision
from the list response. Before changing each product it refreshes the selected
Shopee item and fetches current TikTok detail. It reclassifies from the fresh
data, applies only still-safe actions, and skips rows whose revision or identity
changed.

Updates happen in this order:

1. Correct a Shopee template-SKU mismatch when required.
2. Refresh the source image cache when an image update is still required.
3. Apply TikTok SKU, image, and stock changes in the established product
   partial-edit format while preserving TikTok price and required product data.
4. Refresh the TikTok catalog and verify every requested field before reporting
   the row as successful.

The implementation reuses established TikTok image upload, partial-edit, audit,
and forced catalog verification helpers. It does not infer success from an HTTP
2xx response alone.

## Safety and Failure Handling

- Do not mutate from stale cache data.
- Do not change TikTok prices.
- Do not delete a TikTok SKU without exact ownership proof.
- Fail closed when current Shopee or TikTok data cannot be read.
- Record per-row request and verification outcomes using the existing redacted
  audit conventions.
- Continue with later products when one product fails, and return explicit
  completed, skipped, manual-review, and failed counts.

## Testing

Backend tests cover classification, safe submission guards, fresh-data
reclassification, mutation payload preservation, and post-sync verification.
Frontend tests cover summary and row-state formatting. Local verification uses
the production frontend build, published assets, and a browser snapshot. No
marketplace mutation is run during implementation verification.
