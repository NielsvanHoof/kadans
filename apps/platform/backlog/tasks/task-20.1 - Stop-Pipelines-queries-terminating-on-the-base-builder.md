---
id: TASK-20.1
title: Stop Pipelines queries terminating on the base builder
status: Done
assignee:
  - '@claude'
created_date: '2026-08-08 10:58'
updated_date: '2026-08-08 11:16'
labels: []
dependencies: []
parent_task_id: TASK-20
priority: high
type: bug
ordinal: 65000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Pipeline::query()->getQuery() returns the underlying Query\\Builder. TenantScope is a global scope applied by Eloquent\\Builder at execution time, so terminating a query on the base builder skips tenant isolation entirely.

MonitorPipelinesCommand calls $activeQuery->getQuery()->count() and $failedQuery->getQuery()->count() inside a per-tenant runForTenant loop, so the reported 'Active' and 'Repeatedly failing' figures are global totals summed once per tenant — N times the truth with N tenants. The stuck-marking path on the same page terminates on the Eloquent builder and is correct; only the reporting lies.

DeletePipelinesForSubjectsAction calls getQuery()->delete(), an unscoped cross-tenant delete. It is benign today only because pipelineable_id values come from globally unique auto-increment primary keys, so no other tenant's row can match. That is an accident rather than a design and should not be relied on.

The idiom appears ~115 times across app/, so the rule matters more than the two patches.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 MonitorPipelinesCommand reports per-tenant counts, terminating on the Eloquent builder
- [x] #2 DeletePipelinesForSubjectsAction deletes through the Eloquent builder so TenantScope applies
- [x] #3 A multi-tenant feature test covers pipelines:monitor and fails against the old counting behaviour
- [x] #4 A rule is recorded under .ai/rules covering app/** stating getQuery() is for building only, never for terminating a TenantOwnedModel query
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Change MonitorPipelinesCommand's two count() calls to terminate on the Eloquent builder so TenantScope applies; keep the where clauses on the base builder or lift them to Eloquent for consistency.
2. Change DeletePipelinesForSubjectsAction's delete() to terminate on the Eloquent builder.
3. Add tests/Feature/Pipelines/MonitorPipelinesCommandTest.php: two tenants each with pipelines in pending/running/failed states, assert the reported counts are per-tenant totals and not the global sum, plus a case asserting a running pipeline past the inactivity cutoff is marked stuck.
4. Extend an existing DeletePipelinesForSubjectsAction assertion (or add one) proving another tenant's pipelines survive.
5. Record a rule via record-rule covering app/** on base-builder termination and add the row to .ai/rules/index.md.
6. Run the Pipelines suite plus pint.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
MonitorPipelinesCommand: the two active/failed counters now terminate on the Eloquent builder ($activeQuery->count(), $failedQuery->count()); the where clauses stay on the base builder, which is safe because it mutates the same underlying query. DeletePipelinesForSubjectsAction: same change on delete(). Both carry a comment explaining why, since the surrounding code uses the getQuery() idiom heavily.

Verified the tests actually catch the defect by temporarily reverting each terminator: MonitorPipelinesCommandTest then reports 'Active: 6 ... Repeatedly failing: 6' against the expected 3 and 3 (two tenants, global totals summed once per tenant), and DeletePipelinesForSubjectsActionTest's no-current-tenant case deletes 1 instead of 0.

Checked every caller of DeletePipelinesForSubjectsAction before tightening the scope — PurgeExpiredDossierAction, PruneDossierExportsCommand, AudioRecordingController and DeleteUploadedDocumentAction all run inside tenant context, so nothing depended on the unscoped delete.

Rule recorded via record-rule into .ai/rules/app.md; the index row for app/** was added automatically.

Validation: vendor/bin/sail artisan test --compact tests/Feature/Pipelines -> 84 passed. vendor/bin/sail bin pint --format agent app/Pipelines tests/Feature/Pipelines -> passed.

Unrelated pre-existing failure noted on develop: tests/Feature/Platform/InertiaPropContractTest 'dossier page props keep their contract' fails over indexQuery.defaults.per_page. Confirmed it fails with these changes stashed, so it is not caused by this work.

Correction after running composer types:check (phpstan level 8 + strict rules), which the original pass had not run: terminating on the Eloquent builder ($query->count()) trips phpstan-strict-rules' staticMethod.dynamicCall, because larastan models Builder::count() as a static method on the model. That is precisely why the getQuery() idiom is all over this codebase.

The correct terminator is toBase(), not getQuery() and not the Eloquent builder. Eloquent\Builder::toBase() is applyScopes()->getQuery(), so global scopes are baked in before the drop to the query builder — it is both tenant-safe and PHPStan-clean, and it is already the house pattern (FetchWorkspaceDashboardQuery uses ->toBase()->count() throughout). MonitorPipelinesCommand now uses $activeQuery->toBase()->count() and $failedQuery->toBase()->count(). DeletePipelinesForSubjectsAction's $pipelineQuery->delete() was already PHPStan-clean and stays as is.

The recorded rule in .ai/rules/app.md was rewritten to lead with toBase() and to explain the PHPStan pressure that makes this trap easy to fall into, since 'just use the Eloquent builder' would have sent the next reader straight into a failing types:check.

Validation: composer types:check -> 6 errors, identical in file and line to the clean-tree baseline, so no new static analysis debt. Both regression tests still pass.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Fixed two Pipelines queries that terminated on the base query builder and so bypassed TenantScope: MonitorPipelinesCommand reported global pipeline counts once per tenant (N times the truth), and DeletePipelinesForSubjectsAction issued an unscoped cross-tenant delete. Both now terminate on the Eloquent builder. Added tests/Feature/Pipelines/MonitorPipelinesCommandTest.php (multi-tenant counts plus stuck marking) and DeletePipelinesForSubjectsActionTest.php, both verified to fail against the previous behaviour. Recorded the general rule in .ai/rules/app.md so the ~115 other getQuery() uses have guidance.
<!-- SECTION:FINAL_SUMMARY:END -->
