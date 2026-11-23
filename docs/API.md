# TechnoRapper Mobile API — Reference

**Base URL (production site):** `https://technorapper.com/wp-json/technorapper/v1`  
**Preferred gateway (when configured):** `https://api.technorapper.com/v1`  
**Plugin version:** 0.3.2  
**API version:** `v1`

All content responses are JSON. Send:

```http
Accept: application/json
Authorization: Bearer <Mobile API Token>
```

---

## Authentication

### Simple (recommended)

1. In WordPress: **Settings → TR Mobile API → Generate Mobile API Token**
2. Copy the token (looks like `tr_mob_…`)
3. Put it in the mobile app
4. Send it on every content request:

```http
Authorization: Bearer tr_mob_xxxxxxxx
```

No token configured → `503` (`tr_not_configured`).  
Wrong/missing Bearer → `401` (`tr_unauthorized`).

### Advanced (per-device installs)

Optional. Use when you need to revoke one device without regenerating the shared token.

1. `POST /auth/register` with header `X-TR-App-Key: <Mobile API Token>`
2. Store `install_id` + `install_secret`
3. `POST /auth/token` with those credentials → short-lived Bearer
4. Use that Bearer on content routes
5. Revoke the install in WP admin if needed

---

## Limits

| Rule | Value |
|------|--------|
| `limit` | 1–20 (default 20) |
| `/posts` `page` | 1–50 |
| `/search` `page` | 1–10 |
| Search `q` | 2–100 characters |

Pagination body meta: `page`, `limit`, `total`, `pages`.  
Pagination headers: `X-TR-Page`, `X-TR-Pages`, `X-TR-Total`.

---

## Endpoints

### `GET /config`

Bootstrap config for the app.

**Auth:** Bearer

**Example**

```bash
curl -sS \
  -H "Authorization: Bearer tr_mob_YOUR_TOKEN" \
  -H "Accept: application/json" \
  "https://technorapper.com/wp-json/technorapper/v1/config"
```

**Response `200`**

```json
{
  "api_version": "v1",
  "plugin_version": "0.3.2",
  "min_app_version": "1.0.0",
  "desks": [
    { "id": 12, "slug": "ai", "name": "AI", "order": 0 }
  ],
  "features": {
    "push_notifications": false,
    "search": true
  },
  "auth": {
    "mode": "bearer_mobile_token",
    "token_ttl": 3600,
    "register_path": "/technorapper/v1/auth/register",
    "token_path": "/technorapper/v1/auth/token"
  }
}
```

---

### `GET /desks`

Ordered desk/category list for navigation.

**Auth:** Bearer

```bash
curl -sS \
  -H "Authorization: Bearer tr_mob_YOUR_TOKEN" \
  -H "Accept: application/json" \
  "https://technorapper.com/wp-json/technorapper/v1/desks"
```

**Response `200`**

```json
{
  "data": [
    { "id": 12, "slug": "ai", "name": "AI", "order": 0 },
    { "id": 15, "slug": "android", "name": "Android", "order": 1 }
  ]
}
```

---

### `GET /home`

Home feed: hero + latest + desks.

**Auth:** Bearer  
**Query:** `limit` (optional, max 20)

```bash
curl -sS \
  -H "Authorization: Bearer tr_mob_YOUR_TOKEN" \
  -H "Accept: application/json" \
  "https://technorapper.com/wp-json/technorapper/v1/home?limit=10"
```

**Response `200`**

```json
{
  "data": {
    "hero": [ { "id": 101, "slug": "…", "title": "…", "excerpt": "…", "date": "2026-11-23T12:00:00+00:00", "url": "https://…", "canonical_url": "https://…", "image": "https://…", "desk": { "id": 12, "slug": "ai", "name": "AI" } } ],
    "latest": [ { "id": 101, "slug": "…", "title": "…" } ],
    "desks": [ { "id": 12, "slug": "ai", "name": "AI", "order": 0 } ]
  },
  "meta": {
    "generated_at": "2026-11-23T13:00:00+00:00"
  }
}
```

`hero` is the first story from `latest` (may overlap).

---

### `GET /posts`

Paginated post list.

**Auth:** Bearer  
**Query:**

| Param | Default | Notes |
|-------|---------|--------|
| `page` | `1` | max 50 |
| `limit` | `20` | max 20 |
| `desk` | _(empty)_ | category slug, e.g. `ai` |

```bash
curl -sS \
  -H "Authorization: Bearer tr_mob_YOUR_TOKEN" \
  -H "Accept: application/json" \
  "https://technorapper.com/wp-json/technorapper/v1/posts?page=1&limit=20&desk=ai"
```

**Response `200`**

```json
{
  "data": [
    {
      "id": 101,
      "slug": "example-story",
      "title": "Example story",
      "excerpt": "Short plain-text excerpt",
      "date": "2026-11-23T12:00:00+00:00",
      "url": "https://technorapper.com/example-story/",
      "canonical_url": "https://technorapper.com/example-story/",
      "image": "https://technorapper.com/wp-content/uploads/…jpg",
      "desk": { "id": 12, "slug": "ai", "name": "AI" }
    }
  ],
  "meta": {
    "page": 1,
    "limit": 20,
    "total": 120,
    "pages": 6
  }
}
```

---

### `GET /posts/{id}`

Full post detail.

**Auth:** Bearer  
**Path:** numeric post ID

```bash
curl -sS \
  -H "Authorization: Bearer tr_mob_YOUR_TOKEN" \
  -H "Accept: application/json" \
  "https://technorapper.com/wp-json/technorapper/v1/posts/101"
```

**Response `200`**

```json
{
  "data": {
    "id": 101,
    "slug": "example-story",
    "title": "Example story",
    "excerpt": "Short plain-text excerpt",
    "date": "2026-11-23T12:00:00+00:00",
    "url": "https://technorapper.com/example-story/",
    "canonical_url": "https://technorapper.com/example-story/",
    "image": "https://technorapper.com/wp-content/uploads/…jpg",
    "desk": { "id": 12, "slug": "ai", "name": "AI" },
    "content": "<p>Sanitized HTML body…</p>",
    "ai_wire": {
      "schema": 1,
      "ai_involved": true,
      "primary_type": "ai-rewritten",
      "processing_types": ["ai-rewritten"],
      "sources": []
    },
    "related": [
      { "id": 99, "slug": "…", "title": "…" }
    ]
  }
}
```

`ai_wire` is `null` when no provenance meta exists.  
`content` is HTML via `wp_kses_post` (not full `the_content` shortcodes/embeds).  
Password-protected / non-published posts → `404`.

---

### `GET /search`

**Auth:** Bearer  
**Query:** `q` (required), `page`, `limit`

```bash
curl -sS \
  -H "Authorization: Bearer tr_mob_YOUR_TOKEN" \
  -H "Accept: application/json" \
  "https://technorapper.com/wp-json/technorapper/v1/search?q=android&page=1&limit=20"
```

**Response `200`** — same list shape as `/posts` (`data` + `meta`).

**Response `400`** — `q` length not in 2–100 (`tr_invalid_query`).

---

### `POST /auth/register` (advanced)

**Auth:** header `X-TR-App-Key: <Mobile API Token>`  
**Body (JSON, optional fields):**

```json
{
  "platform": "android",
  "app_version": "1.0.0",
  "device_label": "pixel-8"
}
```

```bash
curl -sS -X POST \
  -H "X-TR-App-Key: tr_mob_YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"platform":"android","app_version":"1.0.0","device_label":"postman"}' \
  "https://technorapper.com/wp-json/technorapper/v1/auth/register"
```

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

### `POST /auth/token` (advanced)

**Body:**

```json
{
  "install_id": "inst_…",
  "install_secret": "…"
}
```

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

Use `Authorization: Bearer <access_token>` on content routes.

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

Detail-only: `content`, `ai_wire`, `related`.

---

## Error codes

| Code | HTTP | Meaning |
|------|------|---------|
| `tr_not_configured` | 503 | Generate a Mobile API Token in admin |
| `tr_unauthorized` | 401 | Missing/invalid Bearer or credentials |
| `tr_install_revoked` | 401 | Install revoked in admin |
| `tr_token_expired` | 401 | Refresh via `/auth/token` |
| `tr_rate_limited` | 429 | Back off; `Retry-After: 60` |
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

## Postman

1. Method `GET`
2. URL `https://technorapper.com/wp-json/technorapper/v1/posts?page=1&limit=20`
3. Authorization → **Bearer Token** → paste Mobile API Token
4. Header `Accept: application/json`

---

## Notes for mobile engineers

- Prefer the **simple Bearer token** path unless you need per-device revoke.
- Cache list responses briefly; respect `Cache-Control` / gateway CDN.
- Render `content` as HTML with a safe WebView / HTML renderer.
- Do not ship WordPress admin passwords in the app.
- Production may sit behind `api.technorapper.com` with the same path suffix after the gateway rewrite.
