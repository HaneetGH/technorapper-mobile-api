# TechnoRapper Mobile API

First-party WordPress plugin providing the stable, read-only API contract for the TechnoRapper Android/iOS app.

**Version:** 0.3.1

## Auth (simple — recommended)

Like GitHub: **the plugin creates the token**. You copy it into the mobile app.

1. Activate the plugin.
2. **Settings → TR Mobile API → Generate Mobile API Token**.
3. Copy the token into the app.
4. Call content routes with:

```http
Authorization: Bearer tr_mob_…
```

Until a token is generated, the API returns `503`.

Regenerating the token invalidates the previous one (update the app).

## Optional advanced (per-device)

For revoke-one-phone without rotating the shared token:

1. `POST /auth/register` with `X-TR-App-Key: <same Mobile API Token>`
2. Store `install_id` + `install_secret`
3. `POST /auth/token` → short-lived Bearer
4. Revoke installs in **Settings → TR Mobile API**

## API

Base path: `/wp-json/technorapper/v1/`

| Method | Path | Auth |
|--------|------|------|
| `GET` | `/config` | Bearer (mobile token or install JWT) |
| `GET` | `/desks` | Bearer |
| `GET` | `/home?limit=20` | Bearer |
| `GET` | `/posts?page=1&limit=20&desk=ai` | Bearer |
| `GET` | `/posts/{id}` | Bearer |
| `GET` | `/search?q=android&page=1&limit=20` | Bearer |
| `POST` | `/auth/register` | `X-TR-App-Key` (advanced) |
| `POST` | `/auth/token` | install credentials (advanced) |

Limits: `limit` ≤ 20; `/posts` page ≤ 50; `/search` page ≤ 10; `q` 2–100 chars.

Prefer `api.technorapper.com/v1/` behind Cloudflare in production.

## Install

1. Upload/activate the plugin.
2. Generate Mobile API Token in settings.
3. Put the token in the mobile app.
4. Verify: `GET .../config` with `Authorization: Bearer <token>`.

## Important

WordPress remains the content origin. Cloudflare handles DDoS. The generated token is powerful—treat a leak as regenerate + update the app.
