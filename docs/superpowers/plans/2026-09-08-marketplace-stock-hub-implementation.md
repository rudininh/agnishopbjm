# Marketplace Stock Hub and Account Management Implementation Plan

> **For agentic workers:** Use `superpowers:executing-plans` to implement this plan task-by-task with review checkpoints.

**Goal:** Add a dynamic marketplace account manager and a unified stock hub while preserving the existing Shopee and TikTok pages.

**Architecture:** Store account metadata and encrypted credential JSON in a new Laravel table. Extend `MarketplaceAccountRegistry` to merge database accounts over config fallbacks and expose sanitized metadata. Add protected account CRUD/test endpoints and a Vue dashboard form; build the stock hub as a selector-driven shell around existing channel experiences and account-aware service calls.

**Tech Stack:** Laravel, Eloquent, SQLite/MySQL, Sanctum, Vue 3, Vue Router, Axios, Node test runner, PHPUnit.

## Global Constraints

- Keep `/stok-shopee` and `/stok-tiktok` unchanged and available.
- Add `/sinkronisasi-stok` as the unified stock route.
- Encrypt marketplace credential JSON at rest with Laravel encrypted casting.
- Never return or log partner keys, secrets, access tokens, or refresh tokens.
- New accounts remain fail-closed until credentials, authorization, identity, and mappings are ready.
- Dashboard mutations require the existing authenticated API guard.

---

### Task 1: Persist marketplace accounts securely

**Files:**
- Create: `backend/database/migrations/2026_09_08_000003_create_marketplace_accounts_table.php`
- Create: `backend/app/Models/MarketplaceAccount.php`
- Test: `backend/tests/Feature/MarketplaceAccountManagementTest.php`

**Interfaces:**
- `MarketplaceAccount` exposes `account_key`, `name`, `channel`, `enabled`, `settings`, and encrypted `credentials`.
- Required unique key: `account_key`; supported channels initially `shopee` and `tiktok`.

- [ ] Write failing tests for encrypted credential persistence, unique account keys, and safe serialization.
- [ ] Run the focused PHPUnit test and verify it fails because the migration/model do not exist.
- [ ] Implement the migration and model using an encrypted credentials cast and JSON settings cast.
- [ ] Run the focused test and verify it passes.

### Task 2: Make the account registry database-aware

**Files:**
- Modify: `backend/app/Services/MarketplaceAccountRegistry.php`
- Test: `backend/tests/Feature/MarketplaceAccountManagementTest.php`

**Interfaces:**
- `publicAccounts(): array` returns merged config/database metadata without credentials.
- `account(string $accountKey): array` returns the merged account context.
- Database rows override matching config accounts; config-only accounts remain available.

- [ ] Add failing tests for a database account appearing in public metadata, a database account overriding config metadata, and credentials remaining absent from public output.
- [ ] Run the focused tests and verify the new assertions fail.
- [ ] Implement the merge and credential decryption only inside server-side context resolution.
- [ ] Run the focused tests and verify all registry assertions pass.

### Task 3: Add protected account CRUD and connection test endpoints

**Files:**
- Create: `backend/app/Http/Controllers/MarketplaceAccountController.php`
- Create: `backend/app/Http/Requests/StoreMarketplaceAccountRequest.php`
- Create: `backend/app/Http/Requests/UpdateMarketplaceAccountRequest.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/MarketplaceAccountManagementTest.php`

**Interfaces:**
- `GET /api/marketplace/accounts` returns sanitized account metadata.
- `POST /api/marketplace/accounts` accepts metadata plus channel-specific credentials.
- `PUT /api/marketplace/accounts/{accountKey}` updates metadata and optionally replaces credentials.
- `POST /api/marketplace/accounts/{accountKey}/test` returns safe readiness/validation status.

- [ ] Write failing HTTP tests for unauthenticated rejection, sanitized create response, encrypted storage, update-without-credential-replacement, duplicate-key validation, and safe test response.
- [ ] Run the focused PHPUnit tests and verify they fail because routes/controller are missing.
- [ ] Implement validation, controller methods, and authenticated routes; reuse registry context validation for connection tests.
- [ ] Run the focused tests and verify all endpoint assertions pass.

### Task 4: Add account-aware stock hub API contract

**Files:**
- Modify: `backend/app/Http/Controllers/MarketplaceAutoSyncController.php` or the smallest existing stock controller boundary
- Modify: `backend/app/Services/MarketplaceApiService.php` only where account selection is currently hard-coded
- Test: `backend/tests/Feature/MarketplaceAccountManagementTest.php`

**Interfaces:**
- Existing stock endpoints accept an optional validated `account_key` without changing legacy defaults.
- Unsupported channel/account combinations fail before external HTTP.

- [ ] Add failing endpoint tests for selecting Shopee AgniShop, TikTok AgniShop, and Gitashop by account key.
- [ ] Run the focused tests and verify account selection is ignored or rejected by the current implementation.
- [ ] Thread the validated account key into existing channel adapters without duplicating marketplace API logic.
- [ ] Run the focused tests and verify the selected account is used.

### Task 5: Build dashboard account-management UI

**Files:**
- Create: `frontend/src/pages/MarketplaceAccounts.vue`
- Create: `frontend/src/pages/marketplaceAccountsState.js`
- Modify: `frontend/src/services/index.js`
- Modify: `frontend/src/router/index.js`
- Modify: `frontend/src/components/Navbar.vue`
- Modify: `frontend/src/pages/Dashboard.vue`
- Test: `frontend/tests/marketplaceAccountsState.test.js`

**Interfaces:**
- `marketplaceAccountsState.js` exposes pure normalizers for safe account metadata and form payloads.
- UI supports add/edit/enable/disable/test actions and masks credential fields after save.

- [ ] Write failing tests for safe metadata normalization, no credential retention in client state, and channel-specific field selection.
- [ ] Run the focused frontend tests and verify they fail because the state module is missing.
- [ ] Implement the state helpers, API service methods, route, navbar link, dashboard entry point, and form.
- [ ] Run the focused tests and verify they pass.

### Task 6: Build unified stock hub page

**Files:**
- Create: `frontend/src/pages/MarketplaceStockHub.vue`
- Create: `frontend/src/pages/marketplaceStockHubState.js`
- Modify: `frontend/src/router/index.js`
- Modify: `frontend/src/components/Navbar.vue`
- Modify: `frontend/src/services/index.js`
- Test: `frontend/tests/marketplaceStockHubState.test.js`

**Interfaces:**
- State normalizer exposes account options, selected account, channel label, and supported actions.
- Selector changes the active account without removing or altering legacy stock routes.

- [ ] Write failing tests for account option ordering, selected-account fallback, and hiding unsupported actions.
- [ ] Run the focused frontend tests and verify they fail because the hub state module is missing.
- [ ] Implement the hub page using account metadata and existing stock service boundaries.
- [ ] Run the focused tests and verify they pass.
- [ ] Manually build the frontend and verify the new route compiles.

### Task 7: Full verification and handoff

**Files:**
- Modify: `docs/superpowers/specs/2026-09-08-marketplace-stock-hub-design.md` only if verified behavior changes.

- [ ] Run the focused and full backend PHPUnit suites.
- [ ] Run the full frontend Node test suite and Vite production build.
- [ ] Run `git diff --check` and scan changed files for credential values.
- [ ] Verify legacy routes still exist and the new route is registered.
- [ ] Record only verified durable architecture facts in project memory.