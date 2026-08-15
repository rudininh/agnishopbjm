# Gitashop Full Reconciliation Design

**Date:** 2026-08-15
**Status:** Proposed — approved for specification review

## Goal

Make each Gitashopcollection Mass Update job trustworthy end-to-end. A job can be marked `selesai` only after every intended target product and variant matches a fresh Shopee Agni Shop Banjarmasin source snapshot for seller SKU, price, stock, product images, and variant images.

## Current Findings

- Job `#5` on 2026-08-15 processed Basic Info and Sales Info only. Media Info stopped in `menunggu_verifikasi`; Shipping, DTS, and Republish had not run.
- The current success check proves Seller Centre showed `Selesai` with 60 expected parent products. Sales, Shipping, and DTS contain 1,730 variant rows, so this does not prove every variant field.
- The generator reads a locally synchronized Shopee Agni catalogue and does not require a fresh source refresh for every job.
- The current Sales writer does not explicitly write the source variant price to the validated Shopee template price field.
- Mass Update changes target rows already present in the Gitashop template; it does not create missing Gitashop products or target variants. A missing row must be a blocking mismatch.

## Scope

### Included

- Require a read-only source-catalogue refresh for Shopee Agni Shop Banjarmasin before manifest and workbook generation.
- Persist an immutable job-scoped manifest for every target product and target variant in the generated files.
- Validate every generated workbook row against the manifest before the PC worker uploads anything.
- Explicitly write and validate the source variant price in the confirmed Sales workbook price column.
- After all six Seller Centre documents are completed, read a separate Gitashopcollection target snapshot and compare every intended product and variant.
- Block final success on any missing, extra, duplicate, or mismatching SKU, price, stock, product image, or variant image.
- Display safe mismatch counts and a downloadable report in `/marketplace/import`.

### Excluded

- Creating new Gitashop products or variants.
- Blind automatic re-upload after a mismatch.
- Changes to unrelated marketplace accounts or STB stock synchronization.

## Data Model

### Source Snapshot

Before file creation, the backend obtains a completed source refresh while holding the existing marketplace-operation lease. The job records the source shop, refresh timestamp, and source-snapshot identity. A failed, stale, or incomplete source refresh terminates the job before workbook generation.

### Expected Manifest

Persist expected product and variant entries for each job. Each entry records:

- Job ID plus source and target item/model identifiers.
- Parent SKU, seller SKU, normalized product name, and variation name.
- Expected IDR price and stock.
- Canonical product image identities and canonical variant image identity.
- File types expected to change the entry, source snapshot time, workbook hash, and immutable row fingerprint.

The manifest is the exact upload intent. Later reconciliation reads the manifest rather than mutable source tables.

### Reconciliation Results

Persist one result per expected product/variant. A result is `matched`, `missing_target`, `unexpected_target`, `duplicate`, or `mismatched`; a mismatch lists only safe field-level expected/actual values and never credentials, browser content, or buyer data.

## Preflight

1. Acquire the existing marketplace-operation lease; incompatible marketplace operations block the job.
2. Refresh the Shopee Agni Shop Banjarmasin source catalogue read-only.
3. Build the expected manifest from source values and the Gitashop target item/model IDs in the templates.
4. Reject duplicate, missing, unrecognized, or invalid mappings, including blank SKU, invalid price, missing required image identity, or incomplete coverage.
5. Generate all six workbooks from the immutable manifest.
6. Reopen the generated workbooks and verify every data row and relevant field against the manifest before upload.

## PC Worker and Final Verification

1. Preserve the current persistent browser, active-shop check, PC profile lock, STB guard, and marketplace-operation lease.
2. Upload the six files serially and keep document-row processed-count checks as an upload guard.
3. Processed counts alone never mark the job complete. After the sixth document is `Selesai`, set job status `memverifikasi`.
4. Fetch a dedicated, read-only Gitashopcollection target snapshot without overwriting the Agni source snapshot.
5. Retry bounded eventual-consistency reads while Seller Centre applies Mass Update changes. Incomplete target pagination, rate limits, API failure, or unknown image identity are verification failures.
6. Compare every scoped target product and variant with the immutable manifest.
7. Mark `selesai` only at zero mismatches; otherwise mark `perlu_perbaikan`, release leases, and never re-upload automatically.

## Comparison Rules

- **SKU:** exact normalized seller-SKU equality; blank, duplicate, missing, and extra values mismatch.
- **Price:** integer IDR equality per variant after source and template-column validation.
- **Stock:** non-negative integer equality per variant.
- **Product images:** ordered canonical image identity comparison for images supported by the template.
- **Variant image:** canonical image identity comparison per variant.
- **Image canonicalization:** use stable Shopee image IDs or canonical path tokens, not expiring CDN signatures.
- **Coverage:** every expected target ID/SKU has exactly one result.

## Status, Recovery, and UI

- New states: `preflight`, `memverifikasi`, and terminal `perlu_perbaikan`.
- `menunggu_verifikasi` remains for login, OTP, CAPTCHA, or unsafe Seller Centre pages. Resuming requires the same final reconciliation.
- Any mismatch prevents success, shows counts by SKU/price/stock/product image/variant image, and enables CSV/XLSX report download.
- The Import page shows source refresh time, expected product/variant counts, document counts, matched/mismatched counts, and current stage.
- Historical completed jobs remain visible as legacy count-verified, not fully reconciled, unless a separate read-only run proves zero mismatch.

## Security and Concurrency

- Keep dedicated worker bearer tokens; marketplace access and refresh tokens never go to the browser worker.
- Use service-layer source and target clients with safe audit messages.
- Preserve the existing marketplace-operation lease and STB guard.
- Treat an unknown source/target field as a failed verification, never as an implicit match.

## Acceptance Criteria

- Every new job refreshes the source catalogue before manifest creation.
- Generated workbook data and manifest have exact, one-to-one scoped product and variant coverage.
- SKU, price, stock, product images, and variant images are explicitly validated where the template supports them.
- Seller Centre document counts alone cannot mark a job complete.
- A final read-only Gitashop snapshot with zero mismatches is required for `selesai`.
- One mismatch produces `perlu_perbaikan` and a safe downloadable report.
- Tests cover stale source data, incomplete coverage, all mismatch categories, target pagination failure, and successful zero-mismatch flow.

## Validation and Rollout

- Add Laravel feature and unit tests for manifests, state transitions, field comparisons, report authorization, price normalization, image canonicalization, and safe failure paths.
- Add Node worker tests for `memverifikasi` handoff and target-read retries; add frontend state tests for the new statuses.
- New jobs use full reconciliation by default. Do not retroactively call old jobs fully verified without a separate read-only reconciliation result.
