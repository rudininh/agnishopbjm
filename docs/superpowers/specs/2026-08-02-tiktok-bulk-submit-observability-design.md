# TikTok Bulk Submit Observability Design

## Problem

On August 2, 2026, the bulk TikTok page sent a successful HTTP request for a
selected product, but the expected Shopee seller SKU remained absent from the
TikTok cache. The page clears its submit notification while it refreshes the
preview, and the backend does not persist per-SKU bulk request results. The
operator therefore cannot see whether Shopee refresh, TikTok image upload, or
TikTok product mutation stopped the run.

## Goal

Make every bulk TikTok variant attempt observable and keep its final result
visible after the candidate preview refreshes. A user can retry an explicitly
selected SKU after seeing the recorded result; previewing never mutates TikTok.

## Backend Design

- Reuse `sku_variant_actions` for each bulk target SKU with
  `target_channel = tiktok` and `action_type = bulk_create_variant`.
- Locate the Stock Master row using the Shopee item ID and model ID so each
  action record has a durable, SKU-specific key.
- Record every terminal outcome: skipped during preflight, failed Shopee
  synchronization, failed TikTok detail retrieval, rejected image upload,
  rejected TikTok mutation, and completed mutation.
- Store the safe request context and returned payload in the action `payload`.
  Redact access tokens, signatures, app credentials, and authorization headers
  before writing the payload.
- Return a result row for every selected SKU. A product-level preflight failure
  becomes individual failed rows with the same specific reason, so the frontend
  has no invisible failures.
- Preserve the existing sequential product processing, live duplicate recheck,
  price choice, image refresh, and no-overwrite behavior for existing TikTok
  variants.

## Frontend Design

- Keep the current result list when refreshing the candidate preview after a
  submit.
- Do not clear the final submit message during that refresh.
- Replace the generic completion copy with a persistent summary containing the
  exact counts: `Berhasil`, `Gagal`, and `Dilewati`.
- Render per-SKU failure reasons in the existing result table. Successful and
  skipped items remain visible in that same table for the latest run.
- Do not automatically submit or retry a TikTok mutation after deployment.
  Retrying stays an explicit action through the confirmation modal.

## Verification

- Add a controller regression test proving a failed bulk SKU produces a failed
  result row and an audit record with no secret query values.
- Add a frontend regression check for preserving the submit result message and
  result rows through the preview refresh.
- Run targeted backend tests, the complete backend test suite, and the
  production frontend build.
- Publish the built Vite assets and verify the local bulk page and API preview.
- After the interface presents the result, run only the user-approved SKU
  `INT-55307930257-ROSE-GOLD` through the normal bulk endpoint, then verify its
  recorded action and refreshed TikTok catalogue result.
