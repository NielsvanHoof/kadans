---
id: TASK-35.6
title: Bound Scout search key hydration and tenant-filter audit search
status: Done
assignee:
  - cursor-agent
created_date: '2026-08-13 11:33'
updated_date: '2026-08-13 14:39'
labels:
  - audit
dependencies: []
references:
  - app/Shared/Queries/Filters/ScoutSearchFilter.php
  - app/Audit/Models/Activity.php
  - app/Reporting/Queries/FetchActivityLogIndexQuery.php
  - vendor/laravel/scout/src/Engines/DatabaseEngine.php
  - config/scout.php
  - tests/Feature/IndexQuery/ClientIndexQueryTest.php
  - tests/Feature/Reporting/ActivityLogTest.php
parent_task_id: TASK-35
priority: high
type: bug
ordinal: 6000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
ScoutSearchFilter hydrates every matching row then plucks ids. With SCOUT_DRIVER=database, Scout's DatabaseEngine applies no LIMIT when builder limit is null, so a search hydrates a full Eloquent model per match. Activity::toSearchableArray() exposes only id, description, event — no tenant_id — so the inner query cannot be tenant-filtered and scans the whole table.

Typing a short term into audit-log search hydrates every matching Activity. Past ~65k matches the IN (...) exceeds PostgreSQL's 65535 bind-parameter limit (fatal, not slow). Under Octane the worker retains peak RSS. The same unbounded searchKeys() is used by seven index queries (activity log, dossiers, archived dossiers, clients, archived clients, media library, questionnaire templates).

Bound the key set once in searchKeys() so all call sites are fixed. Add tenant_id to Activity searchable data so audit search can filter in the database. Do not treat this as a tenant-isolation bug.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 searchKeys() never returns more than a documented bound N of keys
- [x] #2 The Scout builder used for key lookup receives a limit, including under the database driver
- [x] #3 Activity searchable data includes tenant_id and audit-log Scout search is constrained in the database, not only after hydration
- [x] #4 A test asserts the key-set bound and that the Scout builder receives a limit
- [x] #5 This is not a cross-tenant leak: FetchActivityLogIndexQuery already intersects Scout keys with tenant_id
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Bound Scout key hydration once in ScoutSearchFilter; tenant-filter audit search in SQL. Not a cross-tenant leak (FetchActivityLogIndexQuery already intersects keys with tenant_id).

1. ScoutSearchFilter::searchKeys(): $scoutBuilder->take(Config::integer('scout.key_limit')) before keys(). Default 1000 in config/scout.php (below Postgres 65535 bind params; caps Octane RSS). All seven index call sites inherit the bound.
2. ScoutSearchFilter::make() accepts optional array $wheres passed to Scout where(). FetchActivityLogIndexQuery passes tenant_id. CollectionEngine applies that as SQL before PHP contains; DatabaseEngine as addAdditionalConstraints.
3. Do not add tenant_id to Activity::toSearchableArray(). DatabaseEngine LIKEs every key, so searching '1' on tenant 12 would match tenant_id and return the whole log. Scout where() on the table column is the in-database constraint. PHPDoc on toSearchableArray states this.
4. Tests: (a) config key_limit=3, four matching clients, index search total is 3 (proves take/limit). (b) two tenants, identical activity description, search from A returns only A's row. (c) existing client/dossier search tests still pass.
5. record-rule on ScoutSearchFilter / Activity.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Did not add tenant_id to toSearchableArray. DatabaseEngine LIKEs every key in that array (column ILIKE), so a search of "1" ANDed with tenant_id=12 would still match every row because 12 ILIKE '%1%'. Scout where() on the table column is the in-database constraint. Tests use SCOUT_DRIVER=collection; CollectionEngine also honors builder->take() and builder->where() as SQL.

Pest ClientIndexQueryTest + ActivityLogTest + DossierIndexQueryTest: 21 passed. PHPStan 0 errors.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Scout index search no longer hydrates an unbounded key set, and audit-log search applies tenant_id in the engine query.

ScoutSearchFilter::searchKeys() now take(config('scout.key_limit')) before keys(). Default 1000 (SCOUT_KEY_LIMIT), well below Postgres 65535 bind params, so DatabaseEngine emits SQL LIMIT and the later whereKey() IN list cannot go fatal. All seven index queries inherit this.

FetchActivityLogIndexQuery passes wheres: [tenant_id => tenant]. CollectionEngine applies that as SQL before PHP contains; DatabaseEngine as addAdditionalConstraints. tenant_id is not a toSearchableArray key: DatabaseEngine LIKEs every key, so searching "1" on tenant 12 would match the whole log. The Eloquent index query still intersects tenant_id (not a cross-tenant leak).

Tests: ClientIndexQueryTest sets key_limit=3, four matching clients, total is 3. ActivityLogTest: unscoped Scout keys include the other tenant; scoped keys and the HTTP search do not. 21 related tests passed. PHPStan clean.
<!-- SECTION:FINAL_SUMMARY:END -->
