# Gita Order Local Configuration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Run the local Gita order collector without manually setting an ingestion token in PowerShell while preserving bearer-token protection on the Laravel API.

**Architecture:** Keep the Laravel endpoint unchanged. Extend the Node worker configuration loader so explicit environment values override project-local defaults and the ingestion token falls back only to the ignored `backend/.env` file. Use dependency injection in unit tests so tests never read a real local secret.

**Tech Stack:** Node.js ESM, Node built-in `fs` and `path`, Node test runner, Laravel existing bearer-token endpoint.

## Global Constraints

- Never print, commit, test with, or record a real token or `.env` value.
- Keep `POST /api/gita-order-scrapes/runs` bearer-token protected.
- Preserve explicit environment variables as higher precedence than fallbacks.
- Calibration must remain independent of API URL and ingestion token.
- Do not modify Gita, Stock Master, Shopee AgniShop BJM, TikTok AgniShop BJM, mappings, caches, or marketplace sync logs.

---

### Task 1: Test Worker Configuration Fallbacks

**Files:**
- Modify: `tools/gita-order-scraper/tests/config.test.js`
- Modify: `tools/gita-order-scraper/src/config.js`

**Interfaces:**
- Consumes: `loadOrderWorkerConfig(env, dependencies)`.
- Produces: a complete worker configuration using explicit environment values first and `dependencies.readBackendEnvToken()` as the token fallback.

- [ ] **Step 1: Write failing tests**

Add tests proving an empty environment uses local operational defaults plus an injected backend token, and that an explicit environment token wins over the injected backend value.

- [ ] **Step 2: Run the focused test**

Run: `node --test tools/gita-order-scraper/tests/config.test.js`

Expected: FAIL because the current loader requires all values from the environment.

- [ ] **Step 3: Implement the minimal fallback loader**

Use a project-root-relative reader for `backend/.env`, parse only the `GITA_ORDER_SCRAPER_INGEST_TOKEN` assignment, and keep explicit environment values as overrides. Throw a sanitized missing-token error when both sources are blank.

- [ ] **Step 4: Run the focused test again**

Run: `node --test tools/gita-order-scraper/tests/config.test.js`

Expected: PASS.

### Task 2: Document Zero-Entry Local Operation

**Files:**
- Modify: `tools/gita-order-scraper/README.md`

**Interfaces:**
- Consumes: the fallback behavior from Task 1.
- Produces: clear operator instructions that only `backend/.env` needs the local token and normal use runs the root npm command.

- [ ] **Step 1: Update local configuration examples**

Remove the token from the PowerShell worker example, describe the optional environment override, and retain the warning that secrets must not be committed or logged.

- [ ] **Step 2: Run the full worker suite**

Run: `npm run test:gita-order-scraper`

Expected: PASS.

### Task 3: Verify the Complete Change

**Files:**
- Verify: `tools/gita-order-scraper/src/config.js`
- Verify: `tools/gita-order-scraper/tests/config.test.js`
- Verify: `tools/gita-order-scraper/README.md`

- [ ] **Step 1: Inspect the diff for leaked values or unsafe bypasses**

Run: `git diff --check -- tools/gita-order-scraper/src/config.js tools/gita-order-scraper/tests/config.test.js tools/gita-order-scraper/README.md`

Expected: no output and no changes to the Laravel authorization controller.

- [ ] **Step 2: Confirm the worker test suite**

Run: `npm run test:gita-order-scraper`

Expected: PASS with all tests passing.
