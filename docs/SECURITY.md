# Security baseline

## Auth model

1. **Fail closed** — no `tr_mobile_app_key` ⇒ API returns `503`.
2. **App Key** (`X-TR-App-Key`) is only accepted on `POST /auth/register`.
3. **Per-install credentials** — register returns `install_id` + `install_secret` once; only a password hash is stored.
4. **`POST /auth/token`** requires install credentials and returns a short-lived Bearer whose claim `iid` is the install id.
5. **All content GETs** require `Authorization: Bearer <access_token>`; revoked installs fail even if the token is unexpired.
6. Tokens are HMAC-signed (default TTL 3600s, option `tr_mobile_token_ttl`, range 300–86400).
7. WordPress credentials never ship in the app.
8. Admin can list / revoke / reactivate installs (last seen, IP, platform, app version).
9. An App Key or install secret inside a binary can be extracted. Rotate the App Key and revoke installs if leaked. Play Integrity / App Attest is the future upgrade for “our binary only.”

## Edge (required for DDoS)

1. HTTPS only.
2. Cloudflare/WAF in front of `api.technorapper.com`.
3. Stricter rate limits on `/auth/register`, `/auth/token`, and `/search`.
4. Bot Fight / managed rules as appropriate.
5. Monitor 4xx/5xx and origin CPU/DB load.

## Origin controls

1. Maximum page size **20**.
2. `/posts` page max **50**; `/search` page max **10**.
3. Search query **2–100** characters.
4. No mobile content write routes (`POST` only for register/token).
5. Password-protected posts are omitted / `404`.
6. Post body uses `wp_kses_post` (not `the_content`) to avoid shortcode/embed side effects.
7. Origin rate limit uses `CF-Connecting-IP` when present; best-effort only (`/auth/register` 5/min, `/auth/token` 10/min).
8. `429` includes `Retry-After: 60`.
9. Error and auth responses: `Cache-Control: no-store`. Successful GETs: `private, max-age=30` + `Vary: Authorization`.
10. Disable or restrict unrelated WordPress write endpoints where operationally safe.
