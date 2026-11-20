# Architecture

```text
Mobile app
  -> Cloudflare/WAF
  -> api.technorapper.com/v1
  -> Technorapper Mobile API
  -> WordPress
  -> MySQL
```

The mobile contract is versioned and decoupled from the theme. Categories/desks are read dynamically from WordPress/theme configuration so app ordering changes do not require an app release.

The API returns optimized image URLs, structured AI Wire provenance where available, and related stories. HTML scraping is not used.
