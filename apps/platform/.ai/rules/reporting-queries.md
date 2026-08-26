---
paths:
  - 'app/Reporting/Queries/**'
---

# Reporting Queries

## Activity log uses simple pagination
FetchActivityLogIndexQuery is the only index that uses simplePaginate() — COUNT(*) on activity_log is too expensive, so the UI is prev/next only (SimplePaginated / SimpleIndexPagination). Every other PaginatedIndexQuery stays length-aware paginate() with numbered pages. Do not switch this index to cursor pagination: it allows sort=event, which is not unique.
