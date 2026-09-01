---
paths:
  - 'resources/js/features/security/passkeys/**'
---

# Passkeys

## Passkey success uses a full page load for file downloads
After passkey confirmation, router.visit() on a /download URL XHRs a route that 302s to S3 and dies on CORS. Detect pathname ending in /download and window.location.assign() instead. In-app pages still use router.visit so login stays an SPA.
