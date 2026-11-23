# TechnoRapper Mobile API — Reference

**Base URL (production site):** `https://technorapper.com/wp-json/technorapper/v1`  
**Preferred gateway (when configured):** `https://api.technorapper.com/v1`  
**Plugin version:** 0.4.0  
**API version:** `v1`

All content responses are JSON. Send:

```http
Accept: application/json
Authorization: Bearer <access_token>
```

---

## Authentication

### Production (app-only — recommended for store builds)

Only the official TechnoRapper app should call the API. Shared `tr_mob_…` must be **disabled** in production (`accept_shared_bearer=false`).

1. `GET /auth/challenge` → single-use `nonce` (TTL ≤ 5 minutes)
2. Obtain **Play Integrity** (Android) or **App Attest** (iOS) token bound to that nonce
3. `POST /auth/register` with package/bundle + attestation → `install_id` + `install_secret` (secret shown once)
4. `POST /auth/token` with install credentials (+ fresh attestation when enabled) → short-lived Bearer JWT (`iid` claim)
5. Call content routes with `Authorization: Bearer <access_token>`
6. Revoke a compromised phone under **Settings → TR Mobile API** (does not force global key rotation)

No Mobile API Token generated in admin → `503` (`tr_not_configured`) for mint/content.

### Staging / development

- Keep `accept_shared_bearer=true` to allow `Authorization: Bearer tr_mob_…` on content routes, **or**
- Use staging IP allowlist + `X-TR-App-Key` to register without Integrity (emulators). Default allowlist is empty (off).
- Do **not** ship `tr_mob_…` in store builds once attestation register ships.

### Admin setup (production checklist)

1. Generate Mobile API Token (server fail-closed / staging)
2. Set allowed Android package + cert SHA-256 digests; iOS bundle ID
3. Paste Google Play Integrity service account JSON + cloud project number
4. Set Apple Team ID + App ID (`TeamID.bundleId`)
5. Turn **ON** Require Play Integrity / App Attest
6. Turn **OFF** Accept shared Bearer
7. Optional: attestation on `/auth/token`; staging IP allowlist for CI only

---

## Limits

| Rule | Value |
|------|--------|
| `limit` | 1–20 (default 20) |
| `/posts` `page` | 1–50 |
| `/search` `page` | 1–10 |
| Search `q` | 2–100 characters |
| `/auth/register` | 5 requests / hour / IP |
| `/auth/challenge` | 20 / minute / IP |
| `/auth/token` | 10 / minute / IP |

Pagination body meta: `page`, `limit`, `total`, `pages`.  
Pagination headers: `X-TR-Page`, `X-TR-Pages`, `X-TR-Total`.

---

## Endpoints

### `GET /auth/challenge`

Public (rate-limited). Returns a one-time nonce for Integrity / App Attest.

**Response `200`**

```json
{
  "nonce": "…",
  "expires_at": "2026-11-23T14:05:00+00:00"
}
```

---

### `POST /auth/register`

**Production:** verified attestation required (no `X-TR-App-Key` alone).  
**Staging (attestation off):** `X-TR-App-Key: <Mobile API Token>`.  
**Staging IP allowlist:** app key + nonce; attestation skipped.

**Body (Android example)**

```json
{
  "platform": "android",
  "app_version": "1.0.0",
  "package_name": "com.technorapper.app",
  "nonce": "<from /auth/challenge>",
  "attestation": {
    "provider": "play_integrity",
    "token": "<integrity token>"
  }
}
```

**iOS:** `provider: "app_attest"` with `token` (attestation object, base64) and `key_id`. Optional `assertion` for later token calls.

**Response `201`**

```json
{
  "install_id": "inst_…",
  "install_secret": "…",
  "token_path": "/technorapper/v1/auth/token",
  "note": "Store install_secret securely. It is shown only once. …"
}
```

---

### `POST /auth/token`

**Body**

```json
{
  "install_id": "inst_…",
  "install_secret": "…",
  "nonce": "<from /auth/challenge>",
  "attestation": {
    "provider": "play_integrity",
    "token": "<fresh integrity token>"
  }
}
```

`nonce` + `attestation` only when “Attestation on /auth/token” is enabled (default **OFF** so free apps stay within Play’s free daily quota). Staging IPs skip attestation.

**Response `200`**

```json
{
  "access_token": "….…",
  "token_type": "Bearer",
  "expires_in": 3600,
  "expires_at": "2026-11-23T14:00:00+00:00",
  "install_id": "inst_…"
}
```

---

### `GET /config`

**Auth:** Bearer (install JWT, or shared token if enabled)

**Response `200` (auth block)**

```json
{
  "auth": {
    "mode": "install_jwt",
    "token_ttl": 3600,
    "accept_shared_bearer": false,
    "require_app_attestation": true,
    "challenge_path": "/technorapper/v1/auth/challenge",
    "register_path": "/technorapper/v1/auth/register",
    "token_path": "/technorapper/v1/auth/token"
  }
}
```

`mode` is `install_jwt` when shared Bearer is off; otherwise `shared_or_install`.

---

### `GET /desks`

Ordered desk/category list for navigation.

**Auth:** Bearer

```json
{
  "data": [
    { "id": 12, "slug": "ai", "name": "AI", "order": 0 }
  ]
}
```

---

### `GET /home`

Theme-parity home layout (mobile caps).

**Auth:** Bearer

**Response `200`**

```json
{
  "data": {
    "hero": { "id": 101, "slug": "…", "title": "…", "excerpt": "…", "date": "…", "url": "…", "canonical_url": "…", "image": "…", "desk": { "id": 12, "slug": "ai", "name": "AI" } },
    "side": [ { "id": 102, "slug": "…" }, { "id": 103, "slug": "…" } ],
    "latest": [ { "id": 104, "slug": "…" } ],
    "sections": [
      {
        "desk": { "id": 12, "slug": "ai", "name": "AI" },
        "posts": [ { "id": 110, "slug": "…" } ]
      }
    ]
  },
  "meta": { "generated_at": "2026-11-23T13:00:00+00:00" }
}
```

| Slot | Count |
|------|--------|
| `hero` | 1 object or `null` |
| `side` | 2–3 |
| `latest` | ≤4 |
| `sections[].posts` | ≤4; empty desks omitted |

---

### `GET /pulse`

AI Wire newsroom counters (same SQL as the website homepage). **No invented metrics.**

**Auth:** Bearer

```json
{
  "data": {
    "discovered": 120,
    "evaluated": 80,
    "published": 40,
    "rejected": 15,
    "window_days": 30,
    "source": "queue_snapshot"
  }
}
```

`source` is `unavailable` (zeros) when AI Wire tables are missing.

---

### `GET /posts`

Paginated post list.

**Auth:** Bearer  
**Query:** `page` (max 50), `limit` (max 20), `desk` (category slug)

---

### `GET /posts/{id}`

Full post detail. Password-protected / non-published → `404`.  
`content` is HTML via `wp_kses_post` (not full `the_content`).  
Detail-only fields: `content`, `ai_wire`, `related`.

---

### `GET /search`

**Auth:** Bearer  
**Query:** `q` (required, 2–100 chars), `page`, `limit`

---

## Shared post card fields

| Field | Type | Notes |
|-------|------|--------|
| `id` | number | WP post ID |
| `slug` | string | |
| `title` | string | |
| `excerpt` | string | tags stripped |
| `date` | string | ISO-8601 UTC |
| `url` | string | permalink |
| `canonical_url` | string | same as url today |
| `image` | string\|null | card / medium_large / full |
| `desk` | object\|null | `{ id, slug, name }` |

---

## Error codes

| Code | HTTP | Meaning |
|------|------|---------|
| `tr_not_configured` | 503 | Generate a Mobile API Token in admin |
| `tr_unauthorized` | 401 | Missing/invalid Bearer or credentials |
| `tr_shared_bearer_disabled` | 401 | `tr_mob_…` rejected; use install JWT |
| `tr_install_revoked` | 401 | Install revoked in admin |
| `tr_token_expired` | 401 | Refresh via `/auth/token` |
| `tr_invalid_nonce` | 400 | Missing/expired/used challenge nonce |
| `tr_attestation_required` | 403 | Attestation payload required |
| `tr_attestation_failed` | 403 | Integrity / App Attest failed |
| `tr_attestation_unverified` | 503 | Server credentials not configured |
| `tr_app_not_allowed` | 403 | Package / cert not allowlisted |
| `tr_rate_limited` | 429 | Back off; see `Retry-After` |
| `tr_invalid_query` | 400 | Bad search `q` |
| `tr_invalid_page` | 400 | Page above max |
| `tr_not_found` | 404 | Missing / unpublished / password post |

Typical error body:

```json
{
  "code": "tr_unauthorized",
  "message": "Missing or invalid Authorization Bearer token.",
  "data": { "status": 401 }
}
```

---

## Notes for mobile engineers

- Store builds: challenge → attested register → token → JWT. Remove `API_APP_KEY` from release binaries once attestation register ships.
- Shared `tr_mob_…` is staging-only when `accept_shared_bearer` is on.
- Cache list responses briefly; respect `Cache-Control` / gateway CDN.
- Render `content` as HTML with a safe WebView / HTML renderer.
- Do not invent Pulse numbers client-side; use `/pulse` or hide the strip when `source` is `unavailable`.
- Cloudflare is the real DDoS control; attestation stops casual clone apps, not a determined attacker with a rooted device (revoke that install).
