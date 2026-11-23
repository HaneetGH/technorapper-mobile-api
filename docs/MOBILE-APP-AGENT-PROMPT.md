# Agent prompt — TechnoRapper mobile app

Copy everything below the line into a coding agent that will **audit first, then build**.

---

## Mission

Build the **TechnoRapper** native/Expo mobile app so it feels like the live site **https://technorapper.com**, using the TechnoRapper Mobile API.

**Do this in order:**

1. **Audit** what already exists (API, theme, AI Wire, any app repo).
2. Report gaps vs the website.
3. **Then build** screens and data wiring to match the website layout counts and AI Wire story.

Do not invent endpoints that do not exist. If the API is missing a website feature, call it out as a **backend gap** and either stub with a clear TODO or propose the exact API shape needed.

---

## Brand & product

| Item | Value |
|------|--------|
| Product | TechnoRapper |
| Website | https://technorapper.com |
| AI Wire explainer (web) | https://technorapper.com/ai-wire/ |
| Theme reference | TechnoRapper WP theme (`technorapper-wp-theme`) — match its IA and counts, not a generic news template |
| API plugin | https://github.com/HaneetGH/technorapper-mobile-api |
| API docs | `docs/API.md` in that repo (also WP Admin → Settings → TR Mobile API Docs) |
| API base (today) | `https://technorapper.com/wp-json/technorapper/v1` |
| Future gateway | `https://api.technorapper.com/v1` (same paths after rewrite when live) |

---

## Auth (required)

**Store builds (production):** challenge → Play Integrity / App Attest → register → token → short-lived install JWT. Do **not** ship `tr_mob_…` / `API_APP_KEY` in release binaries once attestation register is live.

1. `GET /auth/challenge` → nonce  
2. `POST /auth/register` with package + attestation  
3. `POST /auth/token` → Bearer JWT  
4. Content requests:

```http
Accept: application/json
Authorization: Bearer <install JWT>
```

**Staging only:** shared `tr_mob_…` Bearer when server has `accept_shared_bearer=true`, or IP-allowlisted register with `X-TR-App-Key`.

Read full contract: **docs/API.md** / **docs/SECURITY.md**.

---

## Phase 1 — Audit checklist (do first)

### A. API (`technorapper-mobile-api`)

Confirm and document actual responses for:

- `GET /config`
- `GET /desks`
- `GET /home` (hero, side, latest≤4, sections)
- `GET /pulse`
- `GET /posts`, `GET /posts/{id}`, `GET /search`

Note:

- Max `limit` = 20; posts `page` ≤ 50; search `page` ≤ 10
- Detail includes `content` (HTML), `ai_wire` provenance (or null), `related`
- Current `/home` is **simplified** (hero + latest + desks) — **not** full website home layout

### B. Website home layout (theme) — **counts the app must match**

Live home is `technorapper-wp-theme/template-parts/homepage.php` (not legacy `board.php`).  
**One pool:** `technorapper_recent_posts(100)` → layout slices/dedupes in PHP (`technorapper_home_layout`).

| UI block | Pool / source | Shown on screen |
|----------|----------------|-----------------|
| TR Pulse strip | AI Wire queue+quarantine, last **30 days** (theme helper) | **4** metrics: Discovered, Evaluated, Published, Rejected + **6** pipeline steps |
| Hero main | `$posts[0]` | **1** large story |
| Hero side | desks (+ comics preference) | **2–3** cards (`side_limit` 2 if comics present, else 3) |
| Latest Stories | remaining after hero/side | layout reserves up to **8**, UI renders **`array_slice(..., 0, 4)` → 4** |
| Desk sections | remaining by category | **up to 4** cards per desk (empty desk omitted) |
| Desk nav | theme mod `desk_slugs` | same order as web |

Default desk slug order when customizer empty (only if category exists):  
`apple`, `ai`, `android`, `auto`, `pc-hardware`, `reviews`, `comics`, `guides`, `tech`.

Rough max unique stories on home: `1 + 3 + 4 + (4 × N desks)` from the 100-post pool.

**App requirement:** same *shown* counts and section structure. Do not ship a flat 20-item list as Home unless labeled temporary fallback.

### C. AI Wire on website (audit facts)

- **TR Pulse on home:** live numbers from theme querying `{prefix}tr_ai_wire_items` + quarantine (30d). Can under-count Published after queue cleanup.
- **Durable funnel (plugin):** `{prefix}tr_ai_wire_funnel_days` columns `checked`, `validated`, `published` — used by **admin Metrics** / social cards, **not** by the public how-it-works page.
- **`/ai-wire/`:** narrative “How AI powers …” only — **no** live metric numbers.
- **Per-post disclosure:** `_tr_ai_wire_provenance` (mobile detail already maps to `ai_wire`).
- **Admin Metrics** (`manage_options`) is not a consumer mobile API.
- Label mismatch to remember: home = Discovered/Evaluated/Rejected/Published; admin/social = Checked/Validated/Published.

### D. Gaps (expected today)

Mobile API **does** expose: news reads + `ai_wire` on post detail.

Mobile API **does not** expose:

- TR Pulse / funnel metrics JSON
- Structured how-it-works payload (use WebView of `/ai-wire/` until then)
- Full home layout (`hero`, `side[2–3]`, `latest[4]`, `sections[]` ≤4 each)

**Do not invent fake metric numbers.** Backend needs a chosen contract (prefer durable `funnel_days`, optionally aligned labels) before Pulse can be native.

Report these gaps explicitly before building workarounds.

---

## Phase 2 — Build requirements

### Design

- Match **live** technorapper.com / theme tokens: cream/paper surfaces (`#f4efe6` / `#fbf8f2`), ink `#1c1733`, accent purple `#5b4dff`, dark header bar `#16122b`; sans for UI (`Avenir Next` / Segoe), serif for deks where the theme uses Georgia/Palatino.
- TR Pulse banner + desk beats + card hierarchy (hero ≫ side ≫ latest grid ≫ desk rows). How-it-works page on web is a darker narrative screen — keep that distinction.
- Do **not** ship a generic Material “news list” as the home.
- IA: Pulse → Hero → Latest → Desk sections → Article → Search → How AI Wire works.

### Screens (MVP)

1. **Home** — same sections/counts as website (compose client-side from API if backend home is still flat; prefer asking backend for a proper `/home` later).
2. **Desk / category feed** — `GET /posts?desk={slug}&page=&limit=` (show 20 per page like API max; infinite scroll OK).
3. **Article** — title, image, desk, date, HTML `content`, `ai_wire` disclosure if present, related stories.
4. **Search** — `GET /search?q=`
5. **How AI Wire works** — at minimum open/in-app WebView of `https://technorapper.com/ai-wire/`; better: native screen mirroring that page’s steps once API/content exists.
6. **TR Pulse metrics** — show Discovered / Evaluated / Published / Rejected like the site **when API exists**; until then show link to web Pulse/How-it-works and mark as blocked on backend.

### Data rules

- Use `/config` for desks + `min_app_version` + features.
- Never hardcode desk order if `/config` or `/desks` returns it.
- Respect API error codes (`503` not configured, `401`, `429` with Retry-After, etc.).
- Securely store Bearer token; no WP admin passwords in the app.

### Acceptance criteria

- [ ] Audit doc written (API vs theme vs AI Wire gaps)
- [ ] Home shows hero + side + latest(4) + desk rows(≤4) semantics matching web
- [ ] Article shows AI Wire provenance when `ai_wire` is non-null
- [ ] How AI Wire works reachable (WebView or native)
- [ ] Metrics strip either live from API or clearly “coming soon” with web fallback — **not fake numbers**
- [ ] All traffic uses Bearer token against technorapper.com mobile API

---

## Reference curl

```bash
curl -sS \
  -H "Authorization: Bearer tr_mob_YOUR_TOKEN" \
  -H "Accept: application/json" \
  "https://technorapper.com/wp-json/technorapper/v1/config"
```

Full examples: `docs/API.md`.

---

## Repos to open while working

1. https://github.com/HaneetGH/technorapper-mobile-api — API + `docs/API.md`
2. Live site https://technorapper.com — visual + count reference
3. https://technorapper.com/ai-wire/ — How it works content
4. Theme: `technorapper-wp-theme` (homepage.php, functions.php `technorapper_home_layout`)
5. AI Wire plugin: metrics/funnel + how-it-works template (for parity, not for calling admin APIs from the phone)
