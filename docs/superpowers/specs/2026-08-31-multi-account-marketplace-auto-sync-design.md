# Multi-Account Marketplace Auto Sync Design

**Date:** 2026-08-31

## Goal

Make Stock Master the single inventory source for Shopee AgniShopBJM, TikTok AgniShopBJM, and Shopee GitaCollectionBJM. Prepare the complete GitaCollectionBJM integration now so that it remains safely disabled until its Shopee Partner credentials and shop authorization are available.

## Scope

- Register all three marketplace accounts in one server-side account registry.
- Support Shopee credentials and tokens per account, including an optional Gita-specific Partner ID and Partner Key with an explicit fallback to the primary Shopee application.
- Poll orders independently for each ready account.
- Apply each marketplace order or cancellation to Stock Master exactly once.
- Fan out the resulting absolute stock to every ready, mapped marketplace listing.
- Persist per-account mapping, delivery, retry, and readiness state.
- Redesign the Automatic Synchronization page around per-account readiness and activity.
- Expose matching STB scheduler and token-synchronization readiness without revealing secrets.

Out of scope:

- Adding a TikTok GitaCollectionBJM account.
- Automatically enabling GitaCollectionBJM before its credentials, token, shop identity, and SKU mappings pass readiness checks.
- Synchronizing prices, titles, descriptions, images, or promotions through the stock fan-out workflow.
- Replacing the existing guarded Gitashop mass-upload and reconciliation workflows.

## Accounts and Ownership

The initial registry contains exactly these accounts:

| Account key | Display name | Channel | Role |
| --- | --- | --- | --- |
| `shopee-agnishopbjm` | Shopee AgniShopBJM | Shopee | Existing primary listing and order source |
| `tiktok-agnishopbjm` | TikTok AgniShopBJM | TikTok Shop | Existing primary listing and order source |
| `shopee-gitacollectionbjm` | Shopee GitaCollectionBJM | Shopee | New listing and order source, disabled until ready |

Stock Master owns the inventory quantity. Marketplace caches are observations of remote listings and must never become an independent inventory source.

## Configuration

The account registry is server-side and contains only non-secret identity and capability metadata. Secret values remain in `.env` and Laravel configuration.

The existing primary Shopee settings remain supported:

```dotenv
SHOPEE_PARTNER_ID=
SHOPEE_PARTNER_KEY=
SHOPEE_HOST=https://partner.shopeemobile.com
SHOPEE_REDIRECT_URL=
```

GitaCollectionBJM receives optional account-specific settings:

```dotenv
SHOPEE_GITA_PARTNER_ID=
SHOPEE_GITA_PARTNER_KEY=
SHOPEE_GITA_HOST=
SHOPEE_GITA_REDIRECT_URL=
SHOPEE_GITA_USE_PRIMARY_APP=false
```

When `SHOPEE_GITA_USE_PRIMARY_APP=true`, the Gita account deliberately uses the primary Shopee Partner ID, Partner Key, host, and callback configuration. When it is false, every required Gita-specific value must be present. There is no silent partial fallback.

No API response, browser payload, status message, application log, or synchronization log may contain Partner Keys, access tokens, or refresh tokens.

## Marketplace Listing Mapping

The current `sku_mappings` shape can represent only one Shopee listing and one TikTok listing per Stock Master row. Multi-account support therefore uses a normalized marketplace-listing relation with these logical fields:

- `stock_master_id`
- `account_key`
- `channel`
- `product_id`
- `variant_id`
- `seller_sku`
- `warehouse_id` when the channel requires it
- `is_active`
- timestamps

One Stock Master SKU may have at most one active listing per account. Remote product and variant identities must be unique inside an account. Identical product or order identifiers from different accounts remain distinct because `account_key` is part of every identity.

Existing Shopee and TikTok fields are migrated or mirrored into listing rows owned by `shopee-agnishopbjm` and `tiktok-agnishopbjm`. Compatibility reads remain available during rollout so current production workflows are not cut over before their normalized mappings are verified.

## Readiness Model

Each account has a computed readiness response containing sanitized checks:

- account is enabled by configuration;
- Partner application configuration is complete for Shopee;
- an active token exists;
- token expiry is usable;
- shop identity is known;
- required warehouse identity is known for TikTok stock updates;
- at least one active SKU listing is mapped;
- scheduler and STB token synchronization are healthy where applicable.

The final state is one of:

- `ready`
- `waiting_credentials`
- `authorization_required`
- `token_expired`
- `mapping_required`
- `runtime_unavailable`
- `disabled`

Polling and stock delivery fail closed unless the account is `ready`. A non-ready account is skipped with a sanitized reason; it does not block ready accounts.

## Order and Inventory Flow

1. The scheduler enumerates accounts that support order polling.
2. It resolves credentials and tokens for one account at a time.
3. It fetches new and changed orders using that account's API context.
4. The system creates an idempotency identity from marketplace, account key, remote order ID, event type, and order-line identity.
5. In one database transaction, it locks the Stock Master row, records the inventory event, and applies the decrement or restoration exactly once.
6. The committed inventory event creates one stock-delivery record for every active mapped target account.
7. Workers send the latest absolute Stock Master quantity to each ready target listing.
8. A successful target is marked delivered. A retryable failure receives a bounded retry schedule. A permanent mapping or configuration failure remains blocked until corrected.

An order cancellation restores only a previously applied sale quantity and may do so only once. Replayed webhooks, overlapping polling windows, scheduler restarts, and identical order numbers belonging to different shops must not double-apply inventory.

## Stock Delivery and Reconciliation

Stock updates are account-aware. Every Shopee signature and API call resolves the Partner application, token, Shop ID, item ID, and model ID from the same account context. TikTok calls resolve their token, shop, warehouse, product, and SKU from the TikTok account context.

Delivery uses absolute stock rather than increments or decrements. If several inventory events occur before a target is updated, the worker may coalesce pending deliveries for the same listing and send only the latest Stock Master quantity.

A scheduled reconciliation compares remote quantities with Stock Master for ready mapped listings. Safe quantity mismatches create normal stock-delivery work. Missing, duplicate, or ambiguous mappings are reported for manual correction and are never guessed.

## Failure Handling and Concurrency

- Account polling has a per-account lock so Shopee Agni and Shopee Gita can run independently without duplicate runs inside either account.
- Inventory mutations use database transactions and row locks.
- Inventory event uniqueness is enforced by the full account-aware idempotency identity.
- Delivery retries never repeat the inventory mutation.
- One failed target does not roll back successful delivery to other targets.
- Existing usable local tokens are preserved when STB is unavailable or returns stale or malformed data.
- Unknown API responses, missing identities, ambiguous SKU matches, incomplete credentials, and expired authorization fail closed.
- Error messages store operational context but exclude credentials and raw sensitive payloads.

## Automatic Synchronization Page

The page gains a compact account overview for all three channels. Each account card shows:

- readiness state and blocking reason;
- credential configuration status without values;
- token and shop authorization status;
- mapped SKU count and mapping exceptions;
- last order poll and its result;
- last stock delivery and pending/failed retry counts;
- safe actions for authorize, test connection, poll now, synchronize now, and retry failed deliveries.

Risky actions are disabled until the relevant readiness checks pass. Shopee Gita initially displays `waiting_credentials` and clear `.env` field names, but never their values.

A shared flow summary shows Stock Master as the source and the three account targets. Existing runtime, STB worker, alerts, safety, reconciliation, and logs remain available but are grouped beneath the account overview so operators see readiness before mutation controls.

## STB Behavior

PC and STB use the same account keys. STB token export/import remains server-to-server and transfers tokens with their account identity. The scheduler reports per-account polling status and sanitized skip reasons. Adding Gita credentials later requires configuration, cache clearing, authorization, token synchronization, mapping verification, and a manual readiness test before enabling scheduled polling or delivery.

## API Boundaries

Account-aware services consume an immutable marketplace account context rather than reading one global Shopee account inside low-level methods. The context includes only identifiers and resolved server-side configuration; it is never serialized with secrets to the frontend.

New or revised endpoints provide:

- sanitized readiness for every registered account;
- per-account connection test;
- per-account manual order poll;
- per-account manual stock synchronization;
- per-account retry of failed deliveries;
- aggregate synchronization activity for the page.

Mutation endpoints require authentication and reject unknown account keys. Existing endpoints remain compatible during migration but delegate to the primary account context.

## Rollout

1. Add account registry, optional Gita environment keys, and sanitized readiness checks while all current behavior remains on the primary accounts.
2. Add normalized listing mappings and backfill verified existing primary-account mappings.
3. Add account-aware API operations and delivery persistence behind feature flags.
4. Update the Automatic Synchronization page and STB status contract.
5. Verify existing Shopee Agni and TikTok Agni behavior before enabling multi-account scheduling.
6. When the Shopee application is approved, configure the Gita credentials or explicit primary-app fallback, clear configuration caches, and authorize the Gita shop.
7. Import and verify Gita mappings, run a read-only connection/readiness test, then perform a supervised single-SKU stock push.
8. Enable Gita order polling and stock delivery only after the supervised test succeeds.

## Verification

- Configuration tests prove Gita-specific credentials and explicit primary-app fallback resolve correctly without exposing secrets.
- Readiness tests cover every blocking state and prove non-ready accounts cannot poll or push.
- Migration tests prove existing mappings become primary-account listing rows without duplicates.
- Order tests prove identical order IDs from different accounts remain separate and replayed events do not double-mutate stock.
- Cancellation tests prove stock is restored once and only after a recorded sale.
- Delivery tests prove one inventory event fans out to all ready mapped accounts and partial failure retries only the failed target.
- API-signing tests prove every Shopee request uses credentials, token, Shop ID, item ID, and model ID from one consistent account context.
- Reconciliation tests prove safe mismatches create absolute-stock deliveries while ambiguous mappings remain blocked.
- Frontend tests prove the three account cards, readiness reasons, locked controls, and retry counts render correctly.
- Full backend and frontend suites pass, the frontend production build succeeds, and published Laravel assets are verified before deployment.
