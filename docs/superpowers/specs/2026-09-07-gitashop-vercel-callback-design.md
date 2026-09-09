# Gitashop Vercel Callback Bridge Design

## Goal

Prepare Gitashop Collection BJM for Shopee and TikTok OAuth callbacks while live partner credentials are still under review. The public callback URLs use the requested Vercel hostname:

- `https://gitashopbjm.vercel.app/api/callback`
- `https://gitashopbjm.vercel.app/api/tiktok-callback`

## Architecture

`gitashopbjm.vercel.app` is the public Vercel project. It serves the website and callback bridge endpoints. The bridge validates that an OAuth code is present, preserves all callback query parameters, and redirects the browser to a separate Gitashop Laravel backend.

The backend base URL is configured only through `GITASHOP_BACKEND_URL`. The implementation must not hard-code a development or production backend hostname. A production backend hostname can be configured after it is deployed, for example `https://gitashopbjm-api.vercel.app` if that Vercel project name is available.

## Callback Flow

1. Shopee or TikTok redirects the merchant browser to the relevant `gitashopbjm.vercel.app` callback URL.
2. The Vercel function requires the `code` query parameter.
3. The function appends every callback query parameter, including `code`, `shop_id`, and `state`, to the configured Gitashop backend callback URL.
4. The function sends a temporary `302` redirect to the backend.
5. The Laravel backend validates the OAuth state, exchanges the code, and securely stores tokens. It must fail closed when partner credentials are absent.

## Configuration

Vercel environment variables:

- `GITASHOP_BACKEND_URL`: required production Laravel backend base URL.
- `GITASHOP_SHOPEE_CALLBACK_URL`: optional full override for the Shopee backend callback route.
- `GITASHOP_TIKTOK_CALLBACK_URL`: optional full override for the TikTok backend callback route.

No partner ID, partner key, OAuth access token, refresh token, or callback state is committed to the repository.

## Error Handling

- Missing `code`: return `400` JSON response.
- Invalid configured callback URL: return `500` JSON response without exposing environment values.
- Unconfigured backend URL: return a clear `503` JSON response.
- Non-callback public paths: serve the Gitashop Vercel website instead of returning the current `404`.

## Out of Scope

- Receiving or storing live partner credentials before the review is complete.
- Enabling Gitashop stock, order, or listing synchronization.
- Deploying a Laravel API runtime onto Vercel as part of the callback bridge.