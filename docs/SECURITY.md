# Security baseline

## Auth model

1. **Fail closed** — no Mobile API Token ⇒ `503`.
2. **Admin Generate button** creates the long-lived Mobile API Token (stored as `tr_mobile_app_key`). Shape: `tr_mob_` + 40 random alphanumeric chars.
3. **Simple mobile path** — content GETs accept `Authorization: Bearer <Mobile API Token>`.
4. **Advanced path** — `POST /auth/register` with `X-TR-App-Key` (same token) issues per-install secrets; `POST /auth/token` mints short-lived Bearer with `iid`; revoked installs fail.
5. WordPress credentials never ship in the app.
6. Regenerating the Mobile API Token invalidates the previous shared token. Use install revoke when you need surgical kill without rotating the shared token.
7. Tokens in the APK can be extracted. Rotate + ship an update if leaked. Play Integrity / App Attest is a future upgrade.

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
4. No mobile content write routes.
5. Password-protected posts are omitted / `404`.
6. Post body uses `wp_kses_post` (not `the_content`).
7. Origin rate limit uses `CF-Connecting-IP` when present; best-effort only.
8. `429` includes `Retry-After: 60`.
9. Error and auth responses: `Cache-Control: no-store`. Successful GETs: `private, max-age=30` + `Vary: Authorization`.
