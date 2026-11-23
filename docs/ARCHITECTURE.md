# Architecture

```text
Mobile app
  -> POST /auth/token (X-TR-App-Key)
  -> short-lived Bearer token
  -> Cloudflare/WAF
  -> api.technorapper.com/v1
  -> Technorapper Mobile API (Bearer required)
  -> WordPress
  -> MySQL
```

The mobile contract is versioned and decoupled from the theme. Categories/desks are read dynamically from WordPress/theme configuration so app ordering changes do not require an app release.

The API returns optimized image URLs, structured AI Wire provenance where available (post meta `_tr_ai_wire_provenance`, including JSON-string meta), and related stories. HTML scraping is not used.

Knowing the URL alone is not enough to fetch posts: callers must mint a Bearer token with the official App Key, then send it on every content request.
