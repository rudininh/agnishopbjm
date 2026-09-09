# Marketplace Stock Hub and Account Management Design

## Goal

Add a new marketplace stock hub without deleting the existing Shopee and TikTok stock pages. The hub lets operators choose a registered shop, load its products, inspect variants and stock, and use platform-specific actions. The dashboard also gains a secure account-management surface for adding future shops and storing their credentials.

## Account Management

Marketplace accounts are persisted in Laravel. Credentials are encrypted at rest using Laravel's encrypted cast and are never returned to the browser. The API returns only safe metadata, credential-presence flags, token/readiness state, and masked identifiers where needed.

Each account has a unique `account_key`, display `name`, `channel`, `enabled` flag, non-secret `settings`, and encrypted `credentials`. Supported credential fields are channel-specific but stored in an extensible JSON structure so new platforms can be added without a new table per platform.

Existing config-defined accounts remain available as fallback records. Database-managed records override matching config entries, so the current AgniShop and Gitashop flows remain compatible while new accounts can be added from the dashboard.

## Stock Hub

Add route `/sinkronisasi-stok` and a Marketplace submenu link. The page loads safe account metadata, displays a shop selector, and renders the existing channel stock experience through account-aware service calls. The old `/stok-shopee` and `/stok-tiktok` routes remain unchanged.

The first implementation supports the existing Shopee and TikTok account adapters and preserves their actions: load products, search/filter, expand variants, single-product refresh, and applicable SKU/variant actions. Unsupported actions are hidden rather than sent to the wrong platform.

## API

Protected Laravel endpoints provide:

- `GET /api/marketplace/accounts`: safe account list for the hub and dashboard.
- `POST /api/marketplace/accounts`: create an account and encrypt credentials.
- `PUT /api/marketplace/accounts/{accountKey}`: update metadata and only replace credentials when supplied.
- `POST /api/marketplace/accounts/{accountKey}/test`: validate the configured context without exposing secrets.
- Existing product endpoints accept an explicit account key where supported; legacy calls retain their current defaults.

All mutation endpoints require the existing authenticated API guard. Validation rejects unsupported channels, malformed keys, duplicate keys, and missing required credential fields for the selected channel.

## Security

Credential values are never logged, serialized into public API responses, stored in frontend state, or committed to source control. The dashboard displays only whether each credential is configured. Account test responses contain safe status and error copy.

## Out of Scope

- Removing or rewriting the existing stock pages.
- Automatically enabling a newly added account for synchronization before its credentials and token are ready.
- Storing partner keys in Vercel frontend environment variables.