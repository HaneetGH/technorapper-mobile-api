# Security baseline

## Auth model (app-only)

1. **Fail closed** — no Mobile API Token ⇒ `503` for content and auth mint paths.
2. **Production path** — `GET /auth/challenge` → Play Integrity / App Attest → `POST /auth/register` → `POST /auth/token` → short-lived install JWT (`iid`) on content routes.
3. **`accept_shared_bearer=false` in production** — long-lived `tr_mob_…` Bearer is rejected (`tr_shared_bearer_disabled`). Staging may leave it on.
4. **`require_app_attestation=true` in production** — register (and optionally token) requires server-verified Integrity / App Attest. Package/bundle allowlist + Android cert digests from the Integrity payload.
5. **Staging IP allowlist** — optional; when set, those IPs may register with `X-TR-App-Key` + nonce without attestation (emulators). Default empty.
6. **Per-install revoke** — compromising one phone → revoke that install only; no global forced rotation for every user.
7. WordPress credentials never ship in the app. Do not put `tr_mob_…` in store binaries once attestation register ships.
8. Obfuscation alone is **not** a security control. Cloudflare remains the real DDoS layer.

## Attestation credentials (free — no Google/Apple API bill)

Play Integrity and App Attest are **$0**. There is no per-request charge. Google gives a default free quota (~10,000 Integrity token requests + ~10,000 server decodes per day per Cloud project); over quota returns errors, not an invoice. Apple App Attest is free (rate-limited). Do **not** add paid wrappers (reCAPTCHA Enterprise, Approov, etc.) for this app.

| Setting | Purpose |
|---------|---------|
| Google service account JSON + Play cloud project number | Decode Play Integrity tokens (free quota) |
| Apple Team ID + App ID | Verify App Attest (free) |
| Allowed Android cert SHA-256 | Must match digests inside Integrity payload |
| Attestation on `/auth/token` | **Default OFF** — only register uses Integrity so free apps stay under daily quota |

Missing credentials + attestation required ⇒ `503` (`tr_attestation_unverified`).

## Edge (required for DDoS)

1. HTTPS only.
2. Cloudflare/WAF in front of `api.technorapper.com`.
3. Stricter rate limits on `/auth/register` (5/hour), `/auth/challenge`, `/auth/token`, and `/search`.
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
8. `429` includes `Retry-After` (60s typical; 3600s for register).
9. Error and auth responses: `Cache-Control: no-store`. Successful GETs: `private, max-age=30` + `Vary: Authorization`.
10. Challenge nonces: single-use, TTL ≤ 5 minutes.

## Acceptance (production)

- [ ] Curl with stolen `tr_mob_…` cannot read content (`accept_shared_bearer=false`)
- [ ] Curl cannot register without real Play / App Store attestation for the allowlisted app
- [ ] Emulator/sideload fails attestation (expected); staging IP path documented
- [ ] Per-install revoke works in WP admin
- [ ] Mobile team can remove `API_APP_KEY` from store builds
