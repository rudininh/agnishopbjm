# Mobile Stock Master Transition Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Provide a safe, auditable Stock Master adjustment workflow in `Kelola Produk Mobile` without activating any marketplace stock push.

**Architecture:** A focused backend service owns transactionally locked Stock Master adjustments and writes an immutable ledger. The controller exposes search, adjustment, and history endpoints; the Vue page provides a Stock Master panel distinct from current marketplace price and cost editing. `marketplace_listings` is read only to report mapping readiness, so external marketplaces are never changed in this phase.

**Tech Stack:** Laravel, PostgreSQL and SQLite test database, Vue 3 Composition API, Vite, Node test runner.

## Global Constraints

- Use `stock_master.stock_qty` as the only mutable daily stock balance.
- Write every balance change to immutable `stock_adjustments` records.
- Do not call Shopee or TikTok APIs, update marketplace cache stock, enable `live_push`, or enable `stb_worker`.
- Only use active `marketplace_listings` for mapping readiness; missing mappings must report `mapping_required`.
- Never expose marketplace keys, tokens, authorization codes, or other credentials.

---

### Task 1: Persist Stock Master Adjustments

**Files:**
- Create: `backend/database/migrations/2026_09_09_000004_create_stock_adjustments_table.php`
- Create: `backend/app/Services/MobileStockAdjustmentService.php`
- Test: `backend/tests/Unit/Services/MobileStockAdjustmentServiceTest.php`

**Interfaces:**
- Consumes: `stock_master`, `marketplace_listings`, authenticated operator identity.
- Produces: search, adjustment, and history methods on `MobileStockAdjustmentService`.

- [ ] Write failing service tests for a locked addition, a deduction, negative-stock rejection, delivery states for mapped and unmapped accounts, and immutable history fields.
- [ ] Run the focused service test and confirm it fails because the service and table do not exist.
- [ ] Add the migration with an indexed `stock_master_id`, signed `delta`, before and after quantity, nullable note/operator, and timestamps.
- [ ] Implement the smallest service using a transaction, `lockForUpdate`, non-negative balance validation, ledger insert, and read-only mapping-state calculation for all registered accounts.
- [ ] Re-run the focused service test and confirm all cases pass.

### Task 2: Expose Safe Mobile APIs

**Files:**
- Modify: `backend/app/Http/Controllers/MobileProductController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/MobileStockMasterApiTest.php`

**Interfaces:**
- Consumes: `MobileStockAdjustmentService` and authenticated request identity.
- Produces: `GET /api/mobile/stock-master/search`, `POST /api/mobile/stock-master/adjustments`, and `GET /api/mobile/stock-master/adjustments`.

- [ ] Write failing feature tests for search, successful adjustment, validation errors, insufficient stock, and history return order.
- [ ] Run the focused feature test and confirm it fails because routes are unavailable.
- [ ] Add controller methods and routes that validate input, delegate to the service, map domain failures to 404 or 422, and return no marketplace credentials.
- [ ] Re-run the focused feature test and confirm all cases pass.

### Task 3: Add the Mobile Stock Master Panel

**Files:**
- Modify: `frontend/src/pages/MobileProductManagement.vue`
- Create: `frontend/src/pages/mobileStockMasterState.js`
- Test: `frontend/tests/mobileStockMasterState.test.js`

**Interfaces:**
- Consumes: mobile Stock Master endpoints.
- Produces: `createAdjustmentPayload`, `formatDeliveryState`, and UI state for search, adjustment submission, delivery statuses, and recent history.

- [ ] Write failing frontend state tests for valid signed adjustment payloads, rejected zero adjustments, and delivery-state labels.
- [ ] Run the focused frontend test and confirm the missing module failure.
- [ ] Add a pure state helper without credentials or marketplace side effects.
- [ ] Add the Stock Master panel with search, selected balance, plus/minus quantity, mandatory reason, optional note, delivery state list, and latest history.
- [ ] Keep price/cost controls separate and remove direct stock editing from the marketplace variant form to avoid two stock sources.
- [ ] Re-run the focused frontend test and confirm all cases pass.

### Task 4: Verify Phase-One Safety

**Files:**
- Modify: `MOBILE_PRODUCT_MANAGEMENT.md`
- Test: `backend/tests/Feature/MobileStockMasterApiTest.php`
- Test: `backend/tests/Unit/Services/MobileStockAdjustmentServiceTest.php`
- Test: `frontend/tests/mobileStockMasterState.test.js`

- [ ] Add concise operator documentation describing reasons, history, mapping-required state, and the transition rule.
- [ ] Run backend focused tests for the new service and API.
- [ ] Run all frontend tests and the frontend production build.
- [ ] Review `git diff --check` and `git diff --stat` to confirm only phase-one files changed and no generated assets or credentials are included.
