---
paths:
  - 'config/**'
---

# Config

## Production config is validated at boot — add new unsafe keys to the validator
`App\Platform\Services\Production\ProductionConfigurationValidator` **fails boot** in production on unsafe config (debug on, non-HTTPS `APP_URL`, cache/queue/session not Redis, Redis without TLS, session not encrypted/secure/domain-scoped, filesystem not S3, broadcasting not Reverb, OCR driver not Textract, non-delivering mailer, dev Reverb credentials, trusted proxies unset or `REMOTE_ADDR`/`*`).

When you add a config key that would be dangerous with its development default, add a check here rather than trusting the deploy. Follow the existing private-predicate style: each check returns the offending key name or `null` into the `$invalidKeys` filter.

`phpunit.xml` deliberately pins `SECURITY_CSP_*` and `SECURITY_HSTS_*` so a developer's local `.env` cannot mask a broken policy. Keep new security config pinned the same way.

Config must stay `config:cache`-serializable — register class names, not Closures (see `configureDebugbarStorageOpen`).
