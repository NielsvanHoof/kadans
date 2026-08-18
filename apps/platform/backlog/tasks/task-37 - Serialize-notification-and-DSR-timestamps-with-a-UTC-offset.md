---
id: TASK-37
title: Serialize notification and DSR timestamps with a UTC offset
status: Done
assignee: []
created_date: '2026-08-14 11:49'
updated_date: '2026-08-14 12:07'
labels: []
dependencies: []
ordinal: 90000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Six query/Data call sites serialize Carbon timestamps with ->toDateTimeString(), which emits "2026-08-14 11:21:54" with no offset. V8 parses an offset-less string as LOCAL time, so a UTC instant is misread and formatRelativeTime() reports the wrong age — a notification 8 minutes old renders as "2 uur geleden" for a CEST user. The other ~71 serialization sites already use ->toIso8601String(); these six are the outliers.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 All six toDateTimeString() call sites emit an offset-bearing ISO 8601 string
- [x] #2 A fresh notification renders its true age in the bell inbox instead of being off by the viewer's UTC offset
- [x] #3 requests-list-card.tsx no longer prints a raw server timestamp string
- [x] #4 A test asserts the serializer's own output carries an offset, so a seventh site cannot regress silently
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Replaced toDateTimeString() with toIso8601String() at all six sites: FetchWorkspaceNotificationsForUserQuery (readAt, createdAt), FetchWorkspaceDashboardQuery (updatedAt), DataSubjectRequestData (requestedAt, dueAt, completedAt). Left the adjacent toDateString() calendar-day serializations (due_date, dueDate) untouched — those are calendar days and must stay offset-free.

Frontend: requests-list-card.tsx rendered {request.due_at} raw, which would have started printing a full ISO string; routed through formatDateTime(). Updated its test fixture, which used the offset-less shape and so encoded the bug.

Guards added:
- tests/Unit/SerializedTimestampFormatTest.php scans app/ and fails on any toDateTimeString(, with an empty documented exceptions list. Verified non-vacuous: the traversal walks 540 PHP files.
- WorkspaceNotificationTest asserts the serializer's own output matches an offset-bearing ISO pattern, rather than a hand-written fixture. The pre-existing Vitest fixtures used a correct ...Z string, which is precisely why nothing caught this.
- format-date-time.test.ts pins that an offset-bearing string resolves to the instant it names, and that the offset-less form does not agree with it outside UTC.

Verified: full Pest suite 1139 passed / 6502 assertions; full Vitest 410 passed; Pint clean.

Pre-existing failure NOT caused by this work: 'npm run types:check' reports one error, notification-list-item.tsx:115 (Inertia Link onClick MouseEvent<HTMLAnchorElement> vs MouseEvent<Element>). That file is byte-identical to HEAD and the regenerated type dirs came back unchanged, so it fails on develop too. Flagged to the user; blocks the types:check gate TASK-38 depends on.

Strengthened the regression guards after finding they were vacuous. The test runner (container Node) is TZ=UTC, where an offset-less string and an offset-bearing one parse to the SAME instant — so the first versions of the relative-time assertions would have passed with the bug still present. Both now pin process.env.TZ='Europe/Amsterdam' (July date, so CEST +02:00) via beforeAll/afterAll, which makes the difference observable. Proof the pin is live: the offset-less case asserts '2 hours ago' and passes; under UTC it would read '8 minutes ago' and fail. Full Vitest re-run confirms no TZ leakage across files (412 passed).

Also fixed the pre-existing types:check error at notification-list-item.tsx:115 (user approved folding it in as a mechanical fix): the onClick handler narrowed the event to MouseEvent<HTMLAnchorElement>, which is not assignable to the MouseEvent<Element> that Inertia's Link declares. Widened it — the handler only reads the modifier keys and button, both present on the wider type. tsc --noEmit now reports 0 errors, so it can serve as the completeness gate for TASK-38.

Note for TASK-38: forcing a timezone in Vitest must use vi.stubEnv('TZ', ...) / vi.unstubAllEnvs(), not process.env.TZ directly — 'process' is not in the tsconfig types array and direct access costs 6 TS2591 errors. vi.stubEnv is typed and verifiably affects Date parsing.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Six call sites serialized Carbon timestamps with toDateTimeString(), emitting no UTC offset; V8 parses such strings as local time, so an 8-minute-old notification rendered as '2 uur geleden' for a CEST user. Switched all six to toIso8601String() and routed requests-list-card.tsx's raw {request.due_at} through formatDateTime(). Adjacent toDateString() calendar-day fields (due_date) deliberately untouched — a calendar day must stay offset-free.

Verified: TZ-pinned DOM test renders '8 minutes ago' for an offset-bearing 8-minute-old notification and '2 hours ago' for the offset-less form, reproducing the reported symptom and its fix; WorkspaceNotificationTest asserts the serializer's own output carries an offset rather than trusting a fixture; new tests/Unit/SerializedTimestampFormatTest.php scans app/ for toDateTimeString( and is confirmed non-vacuous (540 PHP files walked). Full Pest 1139 passed/6502 assertions, full Vitest 412 passed, Pint and Biome clean.
<!-- SECTION:FINAL_SUMMARY:END -->
