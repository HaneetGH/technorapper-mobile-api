# TechnoRapper Mobile API

First-party WordPress plugin providing the stable, read-only API contract for the TechnoRapper Android/iOS app.

**Version:** 0.2.0

## Auth (required)

The API is **fail-closed**. Set an App Key under **Settings → TR Mobile API** (or option `tr_mobile_app_key`). Until then every route returns `503`.

```text
1. POST /wp-json/technorapper/v1/auth/token
   Header: X-TR-App-Key: <app key from your official build>

2. Response:
   { "access_token": "...", "token_type": "Bearer", "expires_in": 3600, "expires_at": "..." }

3. All content routes:
   Header: Authorization: Bearer <access_token>
```

Do not put WordPress admin passwords in the app. The App Key is bootstrap friction only—treat a leak as a rotate-and-ship-update event. Cloudflare must still stop DDoS.

## API

Base path: `/wp-json/technorapper/v1/`

| Method | Path | Auth |
|--------|------|------|
| `POST` | `/auth/token` | `X-TR-App-Key` |
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

1. Copy into `wp-content/plugins/technorapper-mobile-api` and activate.
2. **Settings → TR Mobile API** → set App Key (+ optional token TTL / min app version).
3. Embed the same App Key in the mobile app build.
4. Verify: `POST .../auth/token` then `GET .../config` with the Bearer token.

## Errors

| Code | Status | Meaning |
|------|--------|---------|
| `tr_not_configured` | 503 | App key not set |
| `tr_unauthorized` | 401 | Bad app key or Bearer token |
| `tr_token_expired` | 401 | Refresh via `/auth/token` |
| `tr_rate_limited` | 429 | Back off (`Retry-After: 60`) |
| `tr_invalid_query` | 400 | Bad search `q` |
| `tr_invalid_page` | 400 | Page above max |
| `tr_not_found` | 404 | Missing / unpublished / password post |

## Important

WordPress remains the content origin. Put WAF, bot management, and hard rate limits on Cloudflare. Origin rate limiting is best-effort only.
