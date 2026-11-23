# TechnoRapper Mobile API

First-party WordPress plugin providing the stable, read-only API contract for the TechnoRapper Android/iOS app.

**Version:** 0.3.0

## Auth (required)

The API is **fail-closed**. Set an App Key under **Settings → TR Mobile API** (option `tr_mobile_app_key`). Until then every route returns `503`.

```text
1. First launch — register this device/install
   POST /wp-json/technorapper/v1/auth/register
   Header: X-TR-App-Key: <app key from official build>
   Body (optional JSON): { "platform": "android", "app_version": "1.0.0", "device_label": "pixel" }

   Response (secret shown once):
   { "install_id": "inst_…", "install_secret": "…" }

2. Mint short-lived access token
   POST /wp-json/technorapper/v1/auth/token
   Body: { "install_id": "inst_…", "install_secret": "…" }

   Response:
   { "access_token": "…", "token_type": "Bearer", "expires_in": 3600, "install_id": "inst_…" }

3. All content routes
   Header: Authorization: Bearer <access_token>
```

Store `install_id` + `install_secret` in secure device storage. Revoke any install from **Settings → TR Mobile API**. Do not put WordPress admin passwords in the app.

Cloudflare must still stop DDoS. Per-install keys give investigation and kill-switch control; they do not make reverse‑engineered apps impossible.

## API

Base path: `/wp-json/technorapper/v1/`

| Method | Path | Auth |
|--------|------|------|
| `POST` | `/auth/register` | `X-TR-App-Key` |
| `POST` | `/auth/token` | `install_id` + `install_secret` |
| `GET` | `/config` | Bearer |
| `GET` | `/desks` | Bearer |
| `GET` | `/home?limit=20` | Bearer |
| `GET` | `/posts?page=1&limit=20&desk=ai` | Bearer |
| `GET` | `/posts/{id}` | Bearer |
| `GET` | `/search?q=android&page=1&limit=20` | Bearer |

Limits:

- `limit` max **20**
- `/posts` `page` max **50**
- `/search` `page` max **10**, `q` length **2–100**

Pagination meta: `meta.page`, `meta.limit`, `meta.total`, `meta.pages`, plus headers `X-TR-Page`, `X-TR-Pages`, `X-TR-Total`.

Prefer `api.technorapper.com/v1/` behind Cloudflare rather than raw WordPress URLs in production.

## Install

1. Copy into `wp-content/plugins/technorapper-mobile-api` and activate (creates installs table).
2. **Settings → TR Mobile API** → set App Key (+ optional token TTL / min app version).
3. Embed the same App Key in the mobile app build (register only).
4. Verify: register → token → `GET /config` with Bearer.

## Errors

| Code | Status | Meaning |
|------|--------|---------|
| `tr_not_configured` | 503 | App key not set |
| `tr_unauthorized` | 401 | Bad app key, install credentials, or Bearer |
| `tr_install_revoked` | 401 | Install revoked in admin |
| `tr_token_expired` | 401 | Refresh via `/auth/token` |
| `tr_rate_limited` | 429 | Back off (`Retry-After: 60`) |
| `tr_invalid_query` | 400 | Bad search `q` |
| `tr_invalid_page` | 400 | Page above max |
| `tr_not_found` | 404 | Missing / unpublished / password post |

## Important

WordPress remains the content origin. Put WAF, bot management, and hard rate limits on Cloudflare. Origin rate limiting is best-effort only.
