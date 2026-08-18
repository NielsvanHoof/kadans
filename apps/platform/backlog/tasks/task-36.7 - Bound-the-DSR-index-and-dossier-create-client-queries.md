---
id: TASK-36.7
title: Bound the DSR index and dossier-create client queries
status: Done
assignee:
  - '@claude'
created_date: '2026-08-13 11:34'
updated_date: '2026-08-14 22:42'
labels:
  - audit
dependencies: []
references:
  - app/Platform/Queries/FetchDataSubjectRequestIndexQuery.php
  - app/Dossiers/Queries/FetchDossierCreateQuery.php
  - app/Platform/Http/Controllers/DataSubjectRequestController.php
modified_files:
  - app/Platform/Queries/FetchDataSubjectRequestIndexQuery.php
  - app/Platform/Http/Controllers/DataSubjectRequestController.php
  - app/Dossiers/Queries/FetchDossierCreateQuery.php
  - resources/js/pages/data-subject-requests/index.tsx
  - resources/js/features/data-subject-requests/requests-list-card.tsx
  - tests/Feature/Retention/DataSubjectRequestControllerTest.php
parent_task_id: TASK-36
priority: medium
type: enhancement
ordinal: 8000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
FetchDataSubjectRequestIndexQuery never paginates — it loads every DSR the tenant has ever had, with three eager loads, on every render. The set grows monotonically and the model is not Prunable. It also violates .ai/rules/actions-queries-services-transitions.md.

FetchDossierCreateQuery loads the whole client table and sorts in PHP to fill a select.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 The data-subject request index does not load every DSR the tenant has ever had on each render
- [x] #2 The dossier-create client picker does not load and PHP-sort the entire client table
- [x] #3 Pagination or a bounded query matches .ai/rules/actions-queries-services-transitions.md
- [x] #4 The DSR model remaining non-Prunable is an explicit follow-up or is addressed here
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implemented as a full-stack change.

FetchDataSubjectRequestIndexQuery now extends Shared\Queries\PaginatedIndexQuery, as .ai/rules/actions-queries-services-transitions.md requires for filterable index lists. Status and type exact filters, due_at/requested_at sorts, 15 per page.

The two-part ordering (open first, then deadline) is preserved but expressed as an AllowedSort::callback rather than baked into subject(). Baking it in would have left a leading ORDER BY that silently overrides whatever the user picks in the toolbar, so 'Newest first' would not have reordered anything. The callback also binds the status value rather than interpolating it.

Controller spreads ...$fetchIndex->indexQueryProps(), matching DossierController.

Both client pickers bounded (AC#2). FetchDossierCreateQuery loaded every client row and sorted in PHP with Collection::sortBy — no ORDER BY, no LIMIT; DataSubjectRequestController::clientOptions() loaded every FULL client row. Both now select only the three columns the picker renders, order in the database, and cap at 500. A firm past the cap picks the client from the paginated, searchable client list (or the client's own page) instead. PHPStan strict-rules flags chained orderBy/limit on an Eloquent builder as a dynamic static call, so both go through getQuery() — the idiom the original clientOptions() already used.

React side: pages/data-subject-requests/index.tsx takes Paginated<DataSubjectRequest> plus IndexQueryConfig and renders IndexQueryToolbar / IndexQueryResults / IndexPagination exactly like pages/clients/index.tsx; requests-list-card.tsx reads requests.data. The vitest fixture gained the same paginated() shim used in features/audit/tests/activity-log-card.test.tsx.

AC#4 addressed explicitly rather than deferred: DataSubjectRequest stays non-Prunable ON PURPOSE and the reason is now recorded in the query's docblock — a compliance record has to outlive the request it describes, which is exactly why the list had to be paginated instead.

Tests: three added to DataSubjectRequestControllerTest — 40 requests yield 15 rows with total 40 and last_page 3 plus toolbar config; a closed request with an earlier deadline still ranks below an open one; and the client picker comes back database-ordered. Existing assertions moved from 'requests' to 'requests.data'.

The pagination test was confirmed to discriminate by raising perPage to 10000, which fails it on the row count.

Verified: full suite 1211 tests / 6881 assertions; 428 vitest tests; PHPStan level 8, Rector, Pint, tsc all clean.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
The data-subject request index is paginated and both client pickers are bounded.

The index extends Shared\Queries\PaginatedIndexQuery with status/type filters and due_at/requested_at sorts, so it no longer loads every request the tenant ever logged with three eager loads on each render. The open-first-then-deadline ordering is kept as an AllowedSort callback rather than baked into the subject query — baked in, a leading ORDER BY would have silently overridden every toolbar sort.

Both pickers now select three columns, order in the database and cap at 500, replacing a full-table load plus a PHP Collection::sortBy. The React page adopts the shared IndexQueryToolbar / IndexQueryResults / IndexPagination trio used by the clients index.

AC#4 is answered rather than deferred: DataSubjectRequest stays non-Prunable deliberately — a compliance record must outlive the request it describes — and that is now recorded in the query, which is precisely why pagination was the right fix.

Verified: full suite 1211 tests / 6881 assertions and 428 vitest tests pass; the pagination assertion was confirmed to fail when perPage is raised to 10000. PHPStan level 8, Rector, Pint and tsc clean.
<!-- SECTION:FINAL_SUMMARY:END -->
