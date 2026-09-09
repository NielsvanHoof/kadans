---
paths:
  - 'app/**/{Actions,Queries,Services,Transitions}/**'
---

# Actions Queries Services Transitions

## Actions write, Queries read, Transitions guard status — all via handle()
Actions = mutations/multi-step writes. Queries = reads and page payloads. Both are invoked with `handle()` — never `execute()` or `__invoke()`.

No repository layer, and Query classes must not expose `builder(): Builder`. Query Eloquent directly from Actions and Queries.

Filterable workspace index lists extend `Shared\Queries\PaginatedIndexQuery` and use length-aware `paginate()`.

Guarded status changes belong in `Transitions/` (dossiers, document requests, pipelines, OCR, transcription) — not scattered across controllers and jobs.

Services are for infrastructure drivers and orchestrators only, never a substitute for an HTTP use-case Action.

Persist with explicit `create`/`update`/`save`. `updateOrCreate`/`firstOrCreate` are for seeders, not application code.

## Activity log is the paginate() exception
Filterable workspace indexes use length-aware paginate() except FetchActivityLogIndexQuery, which must call simplePaginate() (no COUNT on activity_log). See reporting-queries.md. Do not 'fix' that index back to paginate().
