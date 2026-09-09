---
paths:
  - 'app/Shared/Queries/Filters/**'
---

# Filters

## Bound Scout key hydration; pass engine wheres; surface truncation
ScoutSearchFilter::keys() must take(config('scout.key_limit') + 1) before iterating Scout keys. The extra key detects truncation without raising the bind cap; results still apply only the first key_limit IDs. DatabaseEngine hydrates every match when limit is null; an IN list past 65535 binds is a Postgres fatal. Pass tenant (or other) equality filters as make(..., wheres: [...]) so the engine constrains in SQL, not only after whereKey() on the index query. When truncated, set request attribute ScoutSearchFilter::REQUEST_ATTRIBUTE_TRUNCATED so PaginatedIndexQuery can expose indexQuery.search_truncated (call handle() before indexQueryProps()).

## Scout hybrid() is database-driver only
Call Scout Builder hybrid() only when scout.driver is database (Postgres + pgvector). PHPUnit uses SCOUT_DRIVER=collection, which has no vectors — ScoutSearchFilter::keys() must skip hybrid() there. Same portability rule as pg_trgm migrations.

## Hybrid Scout search falls back to keyword
When hybrid() runs, Scout embeds the query via Bedrock Titan. A zero-vector response (EmbeddingsCountMismatchException), missing pgvector, or an empty hybrid result (seeded Embeddings::fake() vectors vs a real Titan query) must not 500 the palette — catch and retry keyword-only. Do not log the search term (it may be an IBAN).
