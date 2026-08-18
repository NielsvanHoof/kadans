---
paths:
  - 'app/Shared/Queries/Filters/**'
---

# Filters

## Bound Scout key hydration; pass engine wheres
ScoutSearchFilter::searchKeys() must take(config('scout.key_limit')) before keys(). DatabaseEngine hydrates every match when limit is null; an IN list past 65535 binds is a Postgres fatal. Pass tenant (or other) equality filters as make(..., wheres: [...]) so the engine constrains in SQL, not only after whereKey() on the index query.
