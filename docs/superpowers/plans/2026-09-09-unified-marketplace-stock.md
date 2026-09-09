# Unified Marketplace Stock Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver a full marketplace stock hub with the existing Shopee/TikTok stock controls scoped to a selected marketplace account.

**Architecture:** `MarketplaceStockHub.vue` owns the sanitized account selector and supplies a selected context to full channel stock screens. `ShopeeStock.vue` and `TiktokStock.vue` remain legacy routes by default but accept optional unified-mode context, forward `account_key` to all catalog requests, and reset local view state when the account changes.

**Tech Stack:** Vue 3 Composition API, Vite, Laravel marketplace APIs, Node test runner, PHPUnit, Playwright CLI.

## Global Constraints

- Preserve `/stok-shopee` and `/stok-tiktok` behavior when no unified context is supplied.
- Include the selected `account_key` on marketplace reads and synchronization requests.
- Do not expose access tokens, refresh tokens, partner keys, or credential payloads.
- Keep account create/update/test endpoints protected by Sanctum.
- Do not commit or push unless the user explicitly requests it.

---

### Task 1: Add account-aware catalog request coverage

**Files:**
- Modify: `frontend/tests/marketplaceStockHub.test.js`
- Modify: `frontend/src/pages/marketplaceStockHubState.js`

**Interfaces:**
- Consumes: selected account records with `key`, `channel`, and `name`.
- Produces: request parameter helpers that return `{ account_key: selectedKey }` for the active account.

- [ ] **Step 1: Write failing tests**

Add assertions that Shopee and TikTok catalog request helpers include only the selected account key and that switching accounts resets the hub selection state.

- [ ] **Step 2: Run focused frontend test**

Run: `node --test tests/marketplaceStockHub.test.js`

Expected: fail because the shared state has no account-aware helper/reset contract.

- [ ] **Step 3: Implement minimal state helpers**

Add pure helpers for account-scoped request parameters and reset state without credentials.

- [ ] **Step 4: Run focused test again**

Expected: pass.

### Task 2: Convert the hub to a complete account-selected stock screen

**Files:**
- Modify: `frontend/src/pages/MarketplaceStockHub.vue`
- Modify: `frontend/src/pages/ShopeeStock.vue`
- Modify: `frontend/src/pages/TiktokStock.vue`

**Interfaces:**
- Consumes: selected account context `{ key, channel, name }`.
- Produces: complete stock UI with summary cards, filters, tabs, product table, pagination, and safe per-channel actions.

- [ ] **Step 1: Capture the current reduced hub behavior in browser**

Open `/sinkronisasi-stok` and record the absence of the Shopee full-screen filter and table controls.

- [ ] **Step 2: Add unified-mode props to the existing stock screens**

Use `accountKey`, `accountName`, and `unified` props. Default values keep legacy routes unchanged. Forward `accountKey` as `account_key` for product loading and full synchronization, reset local filters/page/messages when the prop changes, and identify the selected account in the header.

- [ ] **Step 3: Render the selected full screen from the hub**

Keep the account selector above the screen, select `ShopeeStock` for Shopee accounts and `TiktokStock` for TikTok accounts, and remount the child by account key so rows and local state never leak between stores.

- [ ] **Step 4: Preserve action capability boundaries**

Show only actions supported by the selected channel; do not route a Shopee mutation through a TikTok account or vice versa.

- [ ] **Step 5: Build and browser-verify**

Run `npm run build`, select Shopee GitaCollectionBJM, and verify the URL remains `/sinkronisasi-stok`, the full stock controls appear, and Gitashop products load under the selected account.

### Task 3: Regression verification

**Files:**
- Test: `frontend/tests/marketplaceStockHub.test.js`
- Test: `backend/tests/Feature/MarketplaceAccountManagementTest.php`

**Interfaces:**
- Consumes: public sanitized catalog and account-aware item endpoints.
- Produces: verified legacy and unified routes without credential exposure.

- [ ] **Step 1: Run focused frontend and backend tests**

Run: `npm test -- --run` and `vendor\\bin\\phpunit tests\\Feature\\MarketplaceAccountManagementTest.php`.

- [ ] **Step 2: Run production build**

Run: `npm run build`.

- [ ] **Step 3: Run browser smoke test**

Open `/stok-shopee`, `/stok-tiktok`, and `/sinkronisasi-stok`; ensure legacy pages still render and hub selection displays the correct complete screen.
