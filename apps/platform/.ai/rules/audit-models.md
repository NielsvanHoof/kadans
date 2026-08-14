---
paths:
  - 'app/Audit/Models/**'
---

# Audit Models

## Activity tenant_id is a Scout where, not a LIKE column
Do not put tenant_id in Activity::toSearchableArray(). DatabaseEngine LIKEs every key, so searching "1" would match tenant 12 and return the whole log. Filter tenant_id with ScoutSearchFilter wheres on the table column.
