---
paths:
  - 'app/*/Listeners/**'
---

# Listeners

## Keep unused event type hints on discovered listeners
withEvents(discover) registers listeners from the handle()/__invoke type hint. Never remove an unused event parameter — Rector’s RemoveUnusedPublicMethodParameterRector is skipped for domain Listeners directories for this reason. ObservabilityContextTest and EventDiscoveryTest guard RemoveCurrentTenantFromContext.
