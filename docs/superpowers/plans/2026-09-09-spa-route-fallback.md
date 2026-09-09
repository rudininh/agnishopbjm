# SPA Route Fallback Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow direct local access and refresh for Vue dashboard routes, including `/sinkronisasi-stok`.

**Architecture:** The local Apache virtual host serves `frontend/dist` and permits `.htaccess` overrides. A Vue Router fallback stored in `frontend/public/.htaccess` is copied to the build output by Vite, serving `index.html` only when the requested path is not an existing file or directory.

**Tech Stack:** Vue 3, Vite, Apache HTTP Server, PowerShell HTTP checks.

## Global Constraints

- Preserve the existing `/api` virtual-host alias and static asset delivery.
- Do not expose marketplace credentials or tokens.
- Do not commit changes unless the user explicitly requests it.

---

### Task 1: Ship and validate Vue Router fallback

**Files:**
- Create: `frontend/public/.htaccess`
- Build output: `frontend/dist/.htaccess`

**Interfaces:**
- Consumes: Apache `AllowOverride All` for `frontend/dist` in `auto.agnishopbjm.test.conf`.
- Produces: HTTP 200 HTML responses for valid client-side routes.

- [ ] **Step 1: Verify the failing route before the fix**

Run: `Invoke-WebRequest http://agnishopbjm.test/sinkronisasi-stok -UseBasicParsing`

Expected: HTTP 404 before a fallback exists in `frontend/dist`.

- [ ] **Step 2: Add the Apache SPA fallback source file**

Create `frontend/public/.htaccess` with a `mod_rewrite` fallback that leaves existing files and directories untouched, then rewrites every other request to `index.html`.

- [ ] **Step 3: Build the frontend**

Run: `npm run build` in `frontend`.

Expected: Vite succeeds and copies `.htaccess` into `frontend/dist`.

- [ ] **Step 4: Verify direct routes and API behavior**

Run direct requests to `/dashboard`, `/sinkronisasi-stok`, and `/api/health`.

Expected: all requests return HTTP 200; dashboard pages return Vue HTML and health returns JSON.
