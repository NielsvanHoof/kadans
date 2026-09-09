---
paths:
  - app/Platform/PlatformServiceProvider.php
---

# Platform

## Do not log empty missing translation keys
Lang::handleMissingKeysUsing must ignore empty keys. Spatie Health checks with no notification message call trans(''), which is not missing product copy and would otherwise warn on every health:check.
