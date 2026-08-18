---
id: TASK-8
title: Index the hot filtered columns
status: Done
assignee:
  - Cursor
created_date: '2026-08-06 09:52'
updated_date: '2026-08-06 16:02'
labels: []
dependencies: []
references:
  - database/migrations
  - app/Reporting/Queries/FetchActivityLogIndexQuery.php
modified_files:
  - >-
    database/migrations/2026_08_06_160218_add_hot_filter_indexes_to_activity_audio_and_uploaded_documents_tables.php
  - tests/Feature/Schema/HotFilterIndexesTest.php
priority: medium
type: enhancement
ordinal: 28000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Four missing indexes on columns that are filtered or joined on request paths. This is PostgreSQL, so foreign key constraints do not create indexes automatically.

- activity_log.event is exposed as an exact filter on the index query, but the table is only indexed on tenant and created_at plus log_name. Filtering the largest table in the system by event is a full scan.
- activity_log.causer_id is exposed as an exact filter; the morph index leads with causer_type, so it does not serve this.
- audio_recordings has no index at all beyond the tenant/id unique and the later provider job id. No tenant/dossier index, no tenant/status index - unlike dossier_exports, document_requests and pipelines, which all have both.
- uploaded_documents.processing_status was added with no index, while the parallel document_requests.status has a tenant/status composite.

The uploaded_documents case already needed one follow-up migration to add a missing index, so this is a known recurring gap rather than a one-off.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 activity_log has indexes serving exact filters on event and on causer_id
- [x] #2 audio_recordings has tenant-leading composite indexes for dossier and transcription status, matching the sibling tables
- [x] #3 uploaded_documents has a tenant-leading composite index for processing_status
- [x] #4 The migration runs cleanly on a fresh PostgreSQL database and rolls back
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Create one migration adding indexes:
   - activity_log: (tenant_id, event), (tenant_id, causer_id) — serves exact filters with tenant scope used by FetchActivityLogIndexQuery
   - audio_recordings: (tenant_id, dossier_id), (tenant_id, transcription_status) — match dossier_exports pattern
   - uploaded_documents: (tenant_id, processing_status) — match document_requests.status
2. down() drops the same indexes by name.
3. Verify migrate + migrate:rollback on Sail PostgreSQL; assert indexes via information_schema/pg_indexes in a Pest test or artisan tinker check.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Used tenant-leading composites for activity_log because FetchActivityLogIndexQuery always scopes by tenant_id; still serves exact event/causer_id filters.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Added tenant-leading indexes for hot filtered columns on activity_log, audio_recordings, and uploaded_documents.

## Changes
Migration `2026_08_06_160218_add_hot_filter_indexes_to_activity_audio_and_uploaded_documents_tables`:
- activity_log: (tenant_id, event), (tenant_id, causer_id)
- audio_recordings: (tenant_id, dossier_id), (tenant_id, transcription_status)
- uploaded_documents: (tenant_id, processing_status)

## Tests
- HotFilterIndexesTest asserts Schema::hasIndex for all five.
- Verified migrate + migrate:rollback --step=1 + migrate on Sail PostgreSQL.
<!-- SECTION:FINAL_SUMMARY:END -->
