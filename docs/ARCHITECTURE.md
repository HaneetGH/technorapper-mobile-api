# Architecture

```text
WP Admin
  -> Generate Mobile API Token (fail-closed / staging)
  -> App-only settings (packages, attestation credentials)
  -> Revoke installs

Official mobile app
  -> GET /auth/challenge
  -> Play Integrity / App Attest
  -> POST /auth/register → install_id + install_secret
  -> POST /auth/token → short-lived JWT (iid)
  -> Authorization: Bearer <install JWT>
  -> Cloudflare/WAF
  -> api.technorapper.com/v1 (optional gateway)
  -> TechnoRapper Mobile API
  -> WordPress / MySQL (+ AI Wire tables for /pulse)
```

Staging may still accept shared `tr_mob_…` Bearer when `accept_shared_bearer` is on, or register via IP allowlist + app key without Integrity.

The mobile contract is versioned and decoupled from the theme. Categories/desks are read dynamically from WordPress/theme configuration so app ordering changes do not require an app release.

`/home` mirrors theme layout (hero, side, latest≤4, desk sections≤4). `/pulse` reads AI Wire queue/quarantine snapshots — never invents metrics.

The API returns optimized image URLs, structured AI Wire provenance where available, and related stories. HTML scraping is not used.
