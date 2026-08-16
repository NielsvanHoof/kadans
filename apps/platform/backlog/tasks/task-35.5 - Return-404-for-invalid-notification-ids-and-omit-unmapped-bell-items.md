---
id: TASK-35.5
title: Return 404 for invalid notification ids and omit unmapped bell items
status: Done
assignee:
  - cursor-agent
created_date: '2026-08-13 11:33'
updated_date: '2026-08-13 14:26'
labels:
  - audit
dependencies: []
references:
  - routes/tenant/notifications.php
  - >-
    app/WorkspaceNotifications/Queries/FetchWorkspaceNotificationsForUserQuery.php
  - app/WorkspaceNotifications/WorkspaceNotificationTypes.php
  - tests/Feature/Notifications/WorkspaceNotificationTest.php
  - app/WorkspaceNotifications/Support/WorkspaceNotificationTypes.php
parent_task_id: TASK-35
priority: high
type: bug
ordinal: 5000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Two cheap correctness gaps in workspace notifications, intended as one commit.

1. routes/tenant/notifications.php binds {notification} with no UUID constraint while notifications.id is a real PostgreSQL uuid. A probe like DELETE /workspaces/acme/notifications/foo becomes SQLSTATE 22P02 → uncaught QueryException → 500 and a Sentry event. Every other id route in the repo is constrained.

2. FetchWorkspaceNotificationsForUserQuery falls back twice to "client finished the questionnaire" (null-coalesce and match default). Adding a fourth type and forgetting the match arm shows plausible wrong copy with dossierId 0 in a compliance product. PHPStan cannot exhaustiveness-check a match on class constants. Wrong message must become no message. Do not build a presenter registry for three types — revisit at the fifth.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A non-UUID notification id on the workspace notification routes returns 404, not 500
- [x] #2 An unmapped workspace notification type produces no bell item rather than a questionnaire message with dossierId 0
- [x] #3 Existing mapped types still render their correct copy
- [x] #4 A feature test covers the non-UUID 404; a test covers the unknown-type skip
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Two cheap fixes in one change set. No presenter registry (revisit at a fifth bell type).

1. routes/tenant/notifications.php: chain ->whereUuid('notification') on the read and destroy routes (same idea as data-subject-requests [0-9]+). Static read-all/clear-all stay first. Non-UUID segments 404 at routing, so Postgres never sees SQLSTATE 22P02.
2. FetchWorkspaceNotificationsForUserQuery::mapNotification: read data.type with no questionnaire default; match the three DatabaseType constants and default to null; skip nulls in the loop (no bell item). Leave unreadCount as the SQL count of databaseTypes() — a forgotten match arm must not invent copy.
3. Tests in tests/Feature/Notifications/WorkspaceNotificationTest.php: dataset post-read + delete-destroy with notification=foo assertNotFound, and assert the route wheres UUID pattern (SQLite already 404s via ModelNotFound, so the constraint is what pins the Postgres 500). Second test: insert a row whose column type is in databaseTypes() but data.type is unknown — query returns only the mapped sibling, never questionnaire copy with dossierId 0.
4. record-rule: UUID route params use whereUuid; unmapped bell types are omitted.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
SQLite tests already 404 a non-UUID id via ModelNotFoundException; the production 500 is Postgres 22P02. The test therefore also asserts the route wheres UUID pattern so the constraint is what is pinned, not only the status code.

Pest WorkspaceNotificationTest 15 passed (including the two new cases). PHPStan 0 errors on the query.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Workspace notification routes no longer 500 on a non-UUID id, and the bell no longer invents questionnaire copy for an unknown type.

Read and destroy now chain whereUuid('notification') so a probe like DELETE .../notifications/foo 404s at routing instead of sending 'foo' into a PostgreSQL uuid column (SQLSTATE 22P02). FetchWorkspaceNotificationsForUserQuery maps only the three DatabaseType constants and omits anything else — no null-coalesce and no match default to the questionnaire presenter. Unread count still follows the SQL allow-list; a forgotten match arm must not show plausible wrong copy with dossierId 0. No presenter registry (revisit at a fifth type).

Tests in WorkspaceNotificationTest: non-UUID 404 dataset for read and destroy (also asserts the route UUID where pattern, because SQLite already 404s via ModelNotFound); unmapped data.type is omitted while the mapped sibling still renders. File: 15 passed. PHPStan clean. Rules in routes.md and queries.md.
<!-- SECTION:FINAL_SUMMARY:END -->
