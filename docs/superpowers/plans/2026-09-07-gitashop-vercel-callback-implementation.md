# Gitashop Vercel Callback Bridge Implementation Plan

> **For agentic workers:** Implement this plan task-by-task with tests and verification checkpoints.

**Goal:** Make `https://gitashopbjm.vercel.app` deployable with safe Shopee/TikTok callback bridges and a separate configurable Gitashop backend target.

**Architecture:** Keep Vercel as the public website and callback bridge. Extract callback URL construction into a small ESM helper so duplicate query parameters and environment validation are testable. Use `GITASHOP_BACKEND_URL` plus optional per-channel callback overrides; never commit marketplace credentials.

**Tech Stack:** Vercel Node functions, JavaScript ESM, Vue 3/Vite frontend, Node built-in test runner.

## Global Constraints

- Public hostname: `https://gitashopbjm.vercel.app`.
- Shopee callback path: `/api/callback`.
- TikTok callback path: `/api/tiktok-callback`.
- Backend URL comes from `GITASHOP_BACKEND_URL`; no hard-coded local or production backend URL.
- Missing OAuth `code` returns HTTP `400`.
- Missing backend configuration returns HTTP `503`.
- Invalid callback configuration returns HTTP `500` without exposing environment values.
- Partner ID, partner key, access token, refresh token, and callback state remain outside source control.

---

### Task 1: Callback URL helper and tests

**Files:**
- Create: `lib/callback-utils.cjs`
- Test: `tests/api/callback-utils.test.mjs`

**Interfaces:**
- `buildCallbackTarget({ channel, query, env })` returns `{ ok: true, url }` or `{ ok: false, status, error }`.
- `channel` is `shopee` or `tiktok`.
- `query` supports scalar and array values, preserving repeated query parameters.

- [ ] Write tests for backend URL resolution, query preservation, missing backend configuration, invalid URL, and channel-specific override.
- [ ] Run `node --test api/tests/callback-utils.test.mjs` and confirm the new tests fail because the helper is missing.
- [ ] Implement the smallest helper that passes those tests and normalizes a trailing slash without leaking env values.
- [ ] Run the focused test again and confirm it passes.

### Task 2: Shopee and TikTok Vercel handlers

**Files:**
- Modify: `api/callback.js`
- Modify: `api/tiktok-callback.js`
- Test: `tests/api/callback-handlers.test.mjs`

**Interfaces:**
- Each default export remains a Vercel `(req, res)` handler.
- Each handler returns JSON for errors and a `302` redirect for a valid callback.

- [ ] Write handler tests for missing `code`, missing backend configuration, and successful redirects using a fake `req`/`res`.
- [ ] Run the focused handler tests and confirm they fail against the current hard-coded fallback behavior.
- [ ] Refactor both handlers to use the shared helper, `GITASHOP_BACKEND_URL`, and the optional channel overrides.
- [ ] Run the focused handler tests and confirm they pass without exposing configuration values.

### Task 3: Root Vercel deployment configuration

**Files:**
- Modify: `vercel.json`
- Modify: `frontend/index.html`
- Create: `frontend/public/vercel-configuration.txt`

**Interfaces:**
- Root Vercel deployment builds `frontend` and serves `frontend/dist`.
- `/api/*` remains handled by Vercel functions; other paths fall back to the Vue SPA entry point.

- [ ] Update the root Vercel configuration to build `frontend`, retain the scheduler route, and route non-API paths to the generated SPA.
- [ ] Update the page title/metadata from Agni Shop to Gitashop Collection BJM.
- [ ] Document required Vercel variables and exact callback URLs in the frontend deployment notes without adding secrets.
- [ ] Run `npm --prefix frontend run build` and inspect the generated output.

### Task 4: Full verification and handoff

**Files:**
- Modify: `docs/superpowers/specs/2026-09-07-gitashop-vercel-callback-design.md` only if verified behavior changes.

- [ ] Run `node --test tests/api/*.test.mjs`.
- [ ] Run `npm --prefix frontend test`.
- [ ] Run `npm --prefix frontend run build`.
- [ ] Review `git diff --check` and `git status --short` for accidental secrets or generated artifacts.
- [ ] Record only verified durable deployment facts in project memory.
- [ ] Report that source/configuration is ready; report deployment separately if Vercel CLI credentials or project linkage are unavailable.