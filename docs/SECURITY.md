# Security baseline

1. HTTPS only.
2. Cloudflare/WAF in front of `api.technorapper.com`.
3. Per-IP + installation/token rate limiting at the edge.
4. Cache public GET responses to reduce origin load.
5. Maximum page size 20.
6. Search query limited to 2-100 characters.
7. No POST/PUT/PATCH/DELETE mobile content routes.
8. WordPress credentials never ship in the app.
9. Disable or restrict unrelated WordPress write endpoints where operationally safe.
10. Monitor 4xx/5xx rates and origin CPU/DB load.
