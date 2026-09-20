# PC to STB Marketplace Token Sync Design

## 1. Objective
Allow the primary PC deployment to securely push active marketplace tokens (specifically `shopee-gitacollectionbjm`, `shopee-agnishopbjm`, `tiktok-agnishopbjm`) to an STB worker running `STB_SYNC_WORKER=true`, so the STB order polling and stock syncing work without manual SQL/token intervention or token leaks.

## 2. Security & Boundaries
- Never output, return, or log access tokens, refresh tokens, or authorization codes.
- Import endpoint requires `STB_SYNC_WORKER=true`, `STB_TOKEN_SYNC_ENABLED=true`, and Bearer token matching `STB_TOKEN_SYNC_TOKEN`.
- Endpoint accepts only payload format with `source === "pc"`.
- Reject/skip any unknown account key outside the allowlisted accounts (`shopee-agnishopbjm`, `shopee-gitacollectionbjm`, `tiktok-agnishopbjm`).
- Use database transactions and upsert logic preserving valid expiration and non-token identity fields.

## 3. Configuration & API Contracts
- `backend/config/stb.php`:
  - `token_sync_push_url`: URL of the STB target endpoint, default `STB_TOKEN_SYNC_PUSH_URL`
  - `token_sync_url`: unchanged for existing pull direction
- `POST /api/runtime/marketplace-token-import`:
  - Request Headers: `Authorization: Bearer <STB_TOKEN_SYNC_TOKEN>`, `Accept: application/json`
  - Request Body: JSON payload containing `source: "pc"`, `shopee: [...]`, `tiktok: [...]`
  - Response: `{ status: "success", source: "pc", shopee: { updated: N, unchanged: N, skipped_stale: N }, tiktok: { updated: N, unchanged: N, skipped_stale: N }, message: "..." }`
- Console Command on PC:
  - `php artisan agnishop:push-marketplace-tokens-to-stb`
  - Exit code 0 on success/unchanged, 1 on failure.

## 4. Verification
- Feature unit and integration tests with mocked HTTP.
- Sanitized dashboard / output regression tests.
