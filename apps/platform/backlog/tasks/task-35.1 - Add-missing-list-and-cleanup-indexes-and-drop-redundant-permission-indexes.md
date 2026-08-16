---
id: TASK-35.1
title: Add missing list and cleanup indexes and drop redundant permission indexes
status: Done
assignee:
  - cursor-agent
created_date: '2026-08-13 11:33'
updated_date: '2026-08-13 11:53'
labels:
  - audit
dependencies: []
references:
  - database/migrations/2026_07_15_134558_create_permission_tables.php
  - app/Tenancy/Http/Middleware/EnsureValidTenantMembership.php
  - app/Pipelines
modified_files:
  - database/migrations/2026_08_13_114931_add_list_and_cleanup_indexes.php
  - tests/Feature/Platform/ListAndCleanupIndexesTest.php
  - .ai/rules/migrations.md
parent_task_id: TASK-35
priority: high
type: chore
ordinal: 1000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Verified against live pg_indexes: several hot list, membership, and cleanup queries have no usable index, and three Spatie permission stub indexes are exact prefixes of larger indexes on the same table. Postgres does not auto-index foreign keys.

EnsureValidTenantMembership filters tenant_memberships by user_id; the live unique is (tenant_id, user_id), so that lookup cannot use it. Dossiers have no updated_at or deleted_at index despite default sort and archived view. Clients lack (tenant_id, name) and (tenant_id, deleted_at). Pipelines have status-leading composites but the default sort has no status predicate; StuckPipelinesCheck runs every minute. notifications are cleaned daily by created_at with only the morph index. document_requests media-library listing needs (tenant_id, type, updated_at).

This is a migration-only change. Do not mix application code into the same commit. TASK-8 already indexed activity_log, audio_recordings, and uploaded_documents — do not redo those.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 tenant_memberships lookups by user_id are index-backed (user_id is not only the second column of a composite)
- [x] #2 Default dossier list sort and archived-dossier listing are served by tenant-leading indexes on updated_at and deleted_at
- [x] #3 Default client list sort and archived-client listing are served by tenant-leading indexes on name and deleted_at
- [x] #4 Pipeline default sort (no status predicate) and the stuck-pipeline check are index-backed
- [x] #5 Daily notification cleanup by created_at is index-backed
- [x] #6 Media-library document-request listing by type and updated_at is served by a tenant-leading index
- [x] #7 Redundant prefix indexes roles_team_foreign_key_index, model_has_roles_team_foreign_key_index, and model_has_permissions_team_foreign_key_index are dropped
- [x] #8 A schema test confirms the new indexes exist and the three redundant ones do not
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
One Artisan migration, no application code.

Add (Laravel names unless noted):
- tenant_memberships (user_id)
- dossiers (tenant_id, updated_at), (tenant_id, deleted_at)
- clients (tenant_id, name), (tenant_id, deleted_at)
- pipelines (tenant_id, created_at)
- pipelines partial (id) WHERE status = 'stuck' via DB::statement — Laravel 13 PostgresGrammar::compileIndex does not emit WHERE, so Blueprint->index()->where() is a no-op
- notifications (created_at)
- document_requests (tenant_id, type, updated_at)

Drop redundant prefix indexes: roles_team_foreign_key_index, model_has_roles_team_foreign_key_index, model_has_permissions_team_foreign_key_index. Recreate them in down().

Feature test in tests/Feature/Platform asserting Schema::hasIndex for adds and !hasIndex for drops.

Verify on Sail Postgres: migrate, EXPLAIN dossier/client default-sort queries, pg_indexes, rollback --step=1, migrate again.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Partial stuck-pipelines index uses DB::statement because Laravel 13 compileIndex() does not emit WHERE. SQLite accepts the same SQL.

EXPLAIN on live Postgres uses the new dossier, client, pipeline, and media-library indexes. tenant_memberships (1 row) and empty notifications seq-scan by cost; enable_seqscan=off hits tenant_memberships_user_id_index and notifications_created_at_index. Rollback restored the three Spatie prefix indexes.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Added tenant-leading list/cleanup indexes and dropped three redundant Spatie permission prefix indexes. Zero application-code change.

## Changes
Migration `2026_08_13_114931_add_list_and_cleanup_indexes`:
- tenant_memberships (user_id)
- dossiers (tenant_id, updated_at), (tenant_id, deleted_at)
- clients (tenant_id, name), (tenant_id, deleted_at)
- pipelines (tenant_id, created_at)
- pipelines_stuck_id_index: partial (id) WHERE status = 'stuck' via raw SQL (Laravel compileIndex ignores where())
- notifications (created_at)
- document_requests (tenant_id, type, updated_at)
- dropped roles_team_foreign_key_index, model_has_roles_team_foreign_key_index, model_has_permissions_team_foreign_key_index

Recorded two migration rules in .ai/rules/migrations.md.

## Tests
- ListAndCleanupIndexesTest: 12 passed (Schema::hasIndex for adds, !hasIndex for drops)
- Sail Postgres: migrate, rollback --step=1 (prefix indexes restored), migrate again
- EXPLAIN: dossier/client/pipeline/media-library sorts use the new indexes; stuck count is Index Only Scan on pipelines_stuck_id_index
- tenant_memberships and notifications seq-scan on tiny tables; with enable_seqscan=off they use the new indexes
<!-- SECTION:FINAL_SUMMARY:END -->
