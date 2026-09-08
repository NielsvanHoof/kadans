---
paths:
  - 'app/**/Listeners/**'
---

# Listeners

## Keep unused event type hints on discovered listeners
withEvents(discover) registers listeners from the handle()/__invoke type hint. Never remove an unused event parameter — Rector’s RemoveUnusedPublicMethodParameterRector is skipped globally in rector.php for this reason (path globs in withSkip do not match Listeners). ObservabilityContextTest and EventDiscoveryTest guard RemoveCurrentTenantFromContext.
