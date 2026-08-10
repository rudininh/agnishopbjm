# Gita Order Detail SKU Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Collect exact Gita order SKUs from order detail pages for `Perlu Dikirim` and `Dikirim` only, while preventing stale PowerShell tokens from overriding local backend authentication.

**Architecture:** The list parser produces order-line candidates and one detail URL per order card. The worker opens each detail URL in a second authenticated Playwright page and pairs the exact `Kode Variasi` SKU list with the list candidates only when counts match. The worker token has one local source: `backend/.env`.

**Tech Stack:** Node.js ESM, Playwright persistent contexts, linkedom, Node test runner, existing Laravel ingestion API.

## Global Constraints

- Process only `to_ship` and `shipped`; do not visit `completed`.
- Do not store or log buyer, address, payment, courier, cookie, credential, CAPTCHA, MFA, raw HTML, or screenshot data.
- Fail closed when order detail data is ambiguous or line counts differ.
- Keep Gita, Stock Master, Shopee AgniShop BJM, TikTok AgniShop BJM, mappings, caches, and marketplace sync logs read-only.
- Keep the Laravel ingestion endpoint bearer-token protected.

---

### Task 1: Parse Detail URLs And Detail Variation Codes

**Files:**
- Modify: `tools/gita-order-scraper/src/orders.js`
- Modify: `tools/gita-order-scraper/tests/orders.test.js`
- Create: `tools/gita-order-scraper/tests/fixtures/order-detail.html`

**Interfaces:**
- Produces `extractOrderCandidates(document, tabStatus)` with list line data and a card detail URL.
- Produces `extractDetailSellerSkus(document)` with ordered exact `INT-...` codes labelled `Kode Variasi`.

- [ ] Write failing sanitized fixture tests for a multi-item detail order, a missing detail link, and mismatched/missing detail codes.
- [ ] Run `node --test tools/gita-order-scraper/tests/orders.test.js` and confirm red.
- [ ] Implement minimal strict parsers and run the focused test green.

### Task 2: Visit Detail Pages From Two Active Tabs

**Files:**
- Modify: `tools/gita-order-scraper/src/cli.js`
- Modify: `tools/gita-order-scraper/tests/cli.test.js`

**Interfaces:**
- Consumes order candidates and detail SKU arrays from Task 1.
- Produces one complete success payload only after both tabs, all list pages, and all detail pages are validated.

- [ ] Write failing worker tests proving the completed tab is excluded, each order detail is read, multi-item rows are paired in order, and a detail login page yields `needs_login`.
- [ ] Run `node --test tools/gita-order-scraper/tests/cli.test.js` and confirm red.
- [ ] Add a dedicated detail page in the persistent context, strict count pairing, safe detail URL navigation, and focused green verification.

### Task 3: Remove Worker Token Environment Override

**Files:**
- Modify: `tools/gita-order-scraper/src/config.js`
- Modify: `tools/gita-order-scraper/tests/config.test.js`
- Modify: `tools/gita-order-scraper/README.md`

**Interfaces:**
- `loadOrderWorkerConfig()` reads the local backend token only.

- [ ] Write a failing test proving an explicit worker token does not override the backend token.
- [ ] Run `node --test tools/gita-order-scraper/tests/config.test.js` and confirm red.
- [ ] Implement the single source and document the zero-token PowerShell run.
- [ ] Run the focused test green.

### Task 4: Verify

**Files:**
- Verify: changed worker sources, tests, and documentation.

- [ ] Run `npm run test:gita-order-scraper`.
- [ ] Run `backend/vendor/bin/phpunit`.
- [ ] Run `npm --prefix frontend test`.
- [ ] Run `git diff --check` for changed paths and verify no secret or API authorization bypass was introduced.
