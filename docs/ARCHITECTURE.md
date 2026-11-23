# Architecture

```text
WP Admin
  -> Generate Mobile API Token
  -> copy into mobile app

Mobile app
  -> Authorization: Bearer <Mobile API Token>
  -> Cloudflare/WAF
  -> api.technorapper.com/v1
  -> Technorapper Mobile API
  -> WordPress / MySQL
```

Optional advanced path: register per-install credentials, mint short-lived Bearers, revoke individual installs in admin.

The mobile contract is versioned and decoupled from the theme. Categories/desks are read dynamically from WordPress/theme configuration so app ordering changes do not require an app release.

The API returns optimized image URLs, structured AI Wire provenance where available, and related stories. HTML scraping is not used.
