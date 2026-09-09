# Unified Marketplace Stock Design

## Goal

Replace the simplified stock hub with a full operational stock screen that matches the existing Shopee stock workflow while selecting the marketplace account first.

## Experience

`/sinkronisasi-stok` remains one route. The user selects Shopee AgniShopBJM, Shopee GitaCollectionBJM, or TikTok AgniShopBJM, then sees the channel-appropriate complete stock screen: summary cards, filters, status tabs, pagination, product detail, and available synchronization actions. Existing `/stok-shopee` and `/stok-tiktok` pages remain unchanged for legacy navigation.

## Data Isolation

Every product request and synchronization operation receives the selected `account_key`. The UI must reset filters, selection, pagination, messages, and product rows whenever the selected account changes. Read-only account metadata comes from the sanitized marketplace catalog; secrets are never emitted to the frontend.

## Architecture

The hub hosts the existing full Shopee or TikTok stock screen as a channel-aware child rather than maintaining a second reduced table. The full pages accept an optional marketplace context for unified mode, use its `account_key` in catalog actions, and preserve their legacy behavior without that context.

## Validation

Automated tests cover account-aware request parameters and safe account switching. Browser verification confirms the selected Gitashop account stays on `/sinkronisasi-stok`, exposes the same full-screen stock controls, and does not redirect to dashboard.
