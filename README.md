# TechnoRapper Mobile API

First-party WordPress plugin providing the stable, read-only API contract for the TechnoRapper Android/iOS app.

**Version:** 0.4.0

**API reference:** [docs/API.md](docs/API.md) (also under **Settings → TR Mobile API Docs** in WordPress)  
**Security:** [docs/SECURITY.md](docs/SECURITY.md)

## Auth (production — app-only)

1. Activate the plugin and **Generate Mobile API Token** (fail-closed server secret).
2. Configure package allowlist, Play Integrity / App Attest credentials.
3. Turn **ON** Require attestation; turn **OFF** Accept shared Bearer.
4. Mobile app flow:

```http
GET  /auth/challenge
POST /auth/register   # nonce + Play Integrity / App Attest
POST /auth/token      # install_id + install_secret → JWT
GET  /posts           # Authorization: Bearer <install JWT>
```

Until a Mobile API Token is generated, the API returns `503`.

## Staging

- Leave `accept_shared_bearer` on to use `Authorization: Bearer tr_mob_…`, **or**
- Set staging IP allowlist so emulators can register with `X-TR-App-Key` + nonce (no Integrity).

Do not ship `tr_mob_…` in store builds once attestation register ships.

## API

Base path: `/wp-json/technorapper/v1/`

| Method | Path | Auth |
|--------|------|------|
| `GET` | `/auth/challenge` | none (rate-limited) |
| `POST` | `/auth/register` | attestation (or staging app key) |
| `POST` | `/auth/token` | install credentials (+ optional attestation) |
| `GET` | `/config` | Bearer |
| `GET` | `/desks` | Bearer |
| `GET` | `/home` | Bearer |
| `GET` | `/pulse` | Bearer |
| `GET` | `/posts?page=1&limit=20&desk=ai` | Bearer |
| `GET` | `/posts/{id}` | Bearer |
| `GET` | `/search?q=android&page=1&limit=20` | Bearer |

Limits: `limit` ≤ 20; `/posts` page ≤ 50; `/search` page ≤ 10; `q` 2–100 chars; register 5/hour/IP.

Prefer `api.technorapper.com/v1/` behind Cloudflare in production.

## Install

1. Upload/activate the plugin.
2. Generate Mobile API Token; configure app-only settings.
3. Point the mobile app at challenge → register → token (store) or shared Bearer (staging).
4. Verify: `GET .../config` with a valid Bearer.

## Important

WordPress remains the content origin. Cloudflare handles DDoS. Shared tokens and install secrets are powerful—revoke installs surgically; regenerate the shared token only when staging credentials leak.
