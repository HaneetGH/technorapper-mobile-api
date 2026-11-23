# Architecture

```text
Mobile app (first launch)
  -> POST /auth/register (X-TR-App-Key)
  -> install_id + install_secret (stored on device)

Mobile app (ongoing)
  -> POST /auth/token (install credentials)
  -> short-lived Bearer (claim iid = install_id)
  -> Cloudflare/WAF
  -> api.technorapper.com/v1
  -> Technorapper Mobile API (Bearer + active install)
  -> WordPress / MySQL
```

The mobile contract is versioned and decoupled from the theme. Categories/desks are read dynamically from WordPress/theme configuration so app ordering changes do not require an app release.

The API returns optimized image URLs, structured AI Wire provenance where available (post meta `_tr_ai_wire_provenance`, including JSON-string meta), and related stories. HTML scraping is not used.

Knowing the URL alone is not enough. Callers must register an install with the official App Key, mint a Bearer token with that install’s secret, and keep the install active in WP admin.
