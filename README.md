# TechnoRapper Mobile API

First-party WordPress plugin providing the stable, read-only API contract for the TechnoRapper Android/iOS app.

## API

Base path: `/wp-json/technorapper/v1/`

- `GET /config`
- `GET /desks`
- `GET /home`
- `GET /posts?page=1&limit=20&desk=ai`
- `GET /posts/{id}`
- `GET /search?q=android&page=1&limit=20`

The mobile app should consume these endpoints through `api.technorapper.com/v1/` behind the production gateway/CDN rather than calling raw WordPress REST routes directly.

## Security

This plugin is intentionally read-only. It exposes no mobile write endpoint and never stores WordPress admin credentials in the app. Production should put Cloudflare/WAF/rate limiting in front of the API and configure `tr_mobile_app_key` if a bootstrap app key is desired. Do not treat an embedded mobile app key as a secret; it is only an additional gate.

## Install

Copy the plugin directory into `wp-content/plugins/technorapper-mobile-api`, activate it, and verify `/wp-json/technorapper/v1/config`.

## Important

The API should eventually move authentication/rate limiting to a dedicated edge/API service. WordPress remains the content origin.
