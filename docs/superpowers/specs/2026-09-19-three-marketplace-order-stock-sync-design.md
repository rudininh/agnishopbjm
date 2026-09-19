# Three-Marketplace Order and Stock Synchronization Design

## Goal

Extend the existing Marketplace Auto Sync workflow so Shopee AgniShopBJM, Shopee GitaCollectionBJM, and TikTok AgniShopBJM keep marketplace stock aligned after orders, with a lightweight worker deployment suitable for STB Armbian.

This phase intentionally does **not** use Stock Master as the stock authority. The existing marketplace order and catalog synchronization behavior remains the base, and GitaCollectionBJM is added to the same operational flow.

## Scope

### In scope

- Poll orders from all three marketplace accounts through their APIs.
- Preserve the existing order-status rules for Shopee and TikTok.
- Process Shopee GitaCollectionBJM with the same Shopee order-status policy as Shopee AgniShopBJM.
- After an order event is accepted, read the current stock from the source marketplace and push that value to the other two marketplace accounts.
- Run a full three-account stock/catalog reconciliation approximately every hour on STB.
- Keep per-target delivery results and retryable failure logs in the existing synchronization observability flow.
- Prevent duplicate stock changes when polling returns the same order/event more than once.
- Keep the implementation fail-closed when credentials, shop identity, mapping, SKU, or source stock is unsafe or unavailable.
- Update the STB worker configuration, scheduler, tests, and deployment documentation.

### Out of scope for this phase

- Making Stock Master the source of truth for this workflow.
- Browser/Seller Centre scraping on STB for Shopee Gita order ingestion.
- Product creation, SKU creation, price synchronization, or image synchronization.
- Automatic creation or repair of marketplace mappings.
- Enabling Gita live synchronization while its credentials or token are expired.

## Functional behavior

### Shopee AgniShopBJM order

1. Poll the existing Shopee order API.
2. Fetch order detail and resolve each item to a unique marketplace mapping.
3. Preserve the current Shopee event policy (`PROCESSED`, `READY_TO_SHIP`, and `CANCELLED`).
4. Read the current Shopee Agni stock for the mapped SKU.
5. Push the current stock to Shopee GitaCollectionBJM and TikTok AgniShopBJM.
6. Record an independent result for each target.

### Shopee GitaCollectionBJM order

1. Poll the Shopee order API using the Gita account context and Gita shop identity.
2. Fetch order detail and resolve each item to a unique mapping.
3. Apply the same Shopee event policy as the primary Shopee account.
4. Read the current Gita stock for the mapped SKU.
5. Push the current stock to Shopee AgniShopBJM and TikTok AgniShopBJM.
6. Record an independent result for each target.

Gita must use the Gita token and shop identity. A primary Shopee token or shop identity must never be used as a substitute.

### TikTok AgniShopBJM order

1. Preserve the existing TikTok order polling and status-event resolution.
2. Fetch order detail and resolve each item to a unique mapping.
3. Read the current TikTok stock for the mapped SKU.
4. Push the current stock to Shopee AgniShopBJM and Shopee GitaCollectionBJM.
5. Record an independent result for each target.

### Hourly reconciliation

The STB marketplace-lite job runs approximately every 60 minutes:

1. Load active, unambiguous mappings for all three accounts.
2. Read available current stock from the marketplace APIs.
3. Select a source only when the source response is valid and its account context is verified.
4. Push the selected stock to other mapped accounts whose stock differs.
5. Never replace an API failure or ambiguous response with zero.
6. Mark missing mappings, expired credentials, unavailable source stock, and conflicting identities as skipped/blocked with an actionable log.

The reconciliation must not use Stock Master or silently infer stock from stale local values.

## Idempotency and event handling

Each stock event is idempotent by:

```text
source account + source order ID + stock event + canonical SKU
```

A repeated poll of the same event must not decrement or otherwise change stock twice. Existing synchronization logs and order-processing records should be extended or reused rather than introducing a second unrelated ledger unless the current schema cannot represent the three-account event identity.

For cancellation events, the worker reads the current source marketplace stock and mirrors that value. It does not locally add quantity based on an assumed previous decrement.

A retry after a target failure may retry the failed target delivery without repeating the source order mutation.

## Failure isolation and safety

- A failed Gita push must not prevent successful Shopee Agni or TikTok pushes.
- A failed target is retryable and remains visible in Auto Sync logs.
- An expired Gita token disables Gita delivery but does not disable the other two accounts.
- A source account with invalid token, wrong shop identity, malformed detail, missing SKU, or ambiguous mapping is fail-closed.
- All live pushes must remain behind the existing account-aware gateway and account context resolution.
- Credentials, access tokens, refresh tokens, and raw authorization payloads must never be returned to the frontend or written to logs.
- Existing marketplace leases must prevent overlapping STB order and marketplace synchronization runs.

## Scheduling and STB deployment

STB remains the execution environment for live automation:

- Order polling remains frequent using `ORDER_SYNC_INTERVAL_MINUTES` (default target: 1–5 minutes, configurable).
- Full marketplace reconciliation remains hourly using `FULL_MARKETPLACE_SYNC_INTERVAL_MINUTES=60`.
- Token refresh runs before marketplace operations.
- Existing retry, lease, heartbeat, Supervisor, and cron mechanisms are retained.
- The STB worker must be able to run without Node.js, frontend assets, or browser automation.
- Deployment documentation must include Gita account credentials, callback/authorization, token verification, mapping verification, migration, config cache clearing, scheduler, and runtime verification.

Live Gita synchronization is not considered ready until the correct Gita shop has been re-authorized and readiness reports a usable token and mappings.

## `/marketplace/auto-sync` behavior

The existing Auto Sync page remains the operational dashboard. This phase should add or expose:

- readiness for each of the three account contexts;
- last order polling result per source account;
- per-target push success/failure counts;
- hourly reconciliation status;
- retryable failures for Gita and other targets;
- clear indication that Gita is blocked when its token is expired or its mapping is incomplete.

Existing manual controls and legacy routes must remain functional unless they conflict with the new three-account flow.

## Testing requirements

### Unit/service tests

- source-account context selection for each marketplace;
- Gita Shopee order polling uses Gita credentials and shop identity;
- canonical SKU mapping and ambiguous mapping rejection;
- order event idempotency across repeated polls;
- source stock is mirrored to both other accounts;
- cancellation uses current source stock rather than local arithmetic;
- one target failure does not suppress the other target;
- invalid source data fails closed;
- hourly reconciliation skips unsafe rows and does not write zero on API failure;
- STB retry and marketplace lease behavior remains intact.

### Feature/API tests

- Auto Sync readiness reports all three accounts independently;
- Gita token expiration blocks only Gita delivery;
- sync logs expose safe statuses and messages without secrets;
- manual retry can retry a failed target without duplicating the order mutation;
- STB commands return correct success/warning/skipped exit behavior.

### Live verification gate

Before deployment, verify with controlled test orders or approved sandbox/live test SKUs:

1. Shopee Agni order updates Gita and TikTok.
2. Shopee Gita order updates Shopee Agni and TikTok.
3. TikTok order updates both Shopee accounts.
4. Re-running the same poll does not change stock again.
5. Expiring/disabling one target records failure while other targets continue.
6. STB scheduler, heartbeat, logs, and runtime status remain healthy after reboot/restart.

No live verification may use or expose credentials in repository files, logs, test fixtures, or documentation.
