---
paths:
  - 'app/Shared/Queries/Filters/**'
---

# Filters

## Bound Scout key hydration; pass engine wheres; surface truncation
ScoutSearchFilter::searchKeys() must take(config('scout.key_limit') + 1) before keys(). The extra key detects truncation without raising the bind cap; results still apply only the first key_limit IDs. DatabaseEngine hydrates every match when limit is null; an IN list past 65535 binds is a Postgres fatal. Pass tenant (or other) equality filters as make(..., wheres: [...]) so the engine constrains in SQL, not only after whereKey() on the index query. When truncated, set request attribute ScoutSearchFilter::REQUEST_ATTRIBUTE_TRUNCATED so PaginatedIndexQuery can expose indexQuery.search_truncated (call handle() before indexQueryProps()).
