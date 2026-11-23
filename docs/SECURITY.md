# Security baseline

## Auth model

1. **Fail closed** — no `tr_mobile_app_key` ⇒ API returns `503`.
2. **App Key** (`X-TR-App-Key`) is only accepted on `POST /auth/token`.
3. **All content GETs** require `Authorization: Bearer <access_token>`.
4. Tokens are HMAC-signed, short-lived (default 3600s, option `tr_mobile_token_ttl`, range 300–86400).
5. WordPress credentials never ship in the app.
6. An App Key inside the APK/IPA can be extracted. Rotate the key and force an app update if leaked. Play Integrity / App Attest is the future upgrade for “our binary only.”

## Edge (required for DDoS)

1. HTTPS only.
2. Cloudflare/WAF in front of `api.technorapper.com`.
3. Stricter rate limits on `/auth/token` and `/search` than on list/detail.
4. Bot Fight / managed rules as appropriate.
5. Monitor 4xx/5xx and origin CPU/DB load.

## Origin controls

1. Maximum page size **20**.
2. `/posts` page max **50**; `/search` page max **10**.
3. Search query **2–100** characters.
4. No mobile content write routes (`POST` only for token mint).
5. Password-protected posts are omitted / `404`.
6. Post body uses `wp_kses_post` (not `the_content`) to avoid shortcode/embed side effects.
7. Origin rate limit uses `CF-Connecting-IP` when present; best-effort only.
8. `429` includes `Retry-After: 60`.
9. Error and token responses: `Cache-Control: no-store`. Successful GETs: `private, max-age=30` + `Vary: Authorization`.
10. Disable or restrict unrelated WordPress write endpoints where operationally safe.
