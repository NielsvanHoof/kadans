---
paths:
  - 'resources/js/pages/auth/**,resources/js/features/integrations/**,app/Integrations/Http/Controllers/**'
---

# Integrations Http Controllers

## OAuth IdP redirects use plain anchors + Inertia::location
Never LinkButton/Inertia visit for Socialite redirect URLs. RouterProvider turns React Aria href into XHR; following the IdP 302 hits connect-src CSP (accounts.google.com / login.microsoftonline.com). Use plain <a> + buttonVariants (same pattern as S3 download links). OAuthController::redirect must return Inertia::location() when X-Inertia is present so a stray XHR still does window.location.
