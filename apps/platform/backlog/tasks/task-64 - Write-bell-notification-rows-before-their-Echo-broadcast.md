---
id: TASK-64
title: Write bell notification rows before their Echo broadcast
status: Done
assignee:
  - '@NielsvanHoof'
created_date: '2026-09-16 12:54'
updated_date: '2026-09-16 13:03'
labels:
  - backend
  - notifications
dependencies: []
priority: high
type: bug
ordinal: 135000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Staff on a workspace page have to refresh manually before a new bell notification appears, even though the Echo subscription fires correctly.

NotifyWorkspaceIfQuestionnaireCompleteAction comments that it persists inbox rows before broadcasting, but ClientQuestionnaireCompletedNotification is ShouldQueue, so notify() only enqueues SendQueuedNotifications on the `default` queue. The ShouldBroadcast event goes to the `broadcasts` queue. WorkerQueues::INTERACTIVE splits those two queues on purpose and Horizon balance=auto gives each its own process pool, so they drain in parallel with no ordering. The broadcast (one Reverb publish) normally beats the notification jobs (one insert each, ~13ms observed), so the router.reload({ only: [notifications] }) that useWorkspaceNotifications triggers resolves the deferred notifications prop before any row exists. Nothing re-fetches afterwards.

NotifyUploaderOfTranscriptionCompletedAction has the same defect for `.audio.transcription.completed`, and dispatches the event before notify() rather than after.

The suite cannot catch this: phpunit.xml pins QUEUE_CONNECTION=sync, under which notify() really does write before the event is dispatched.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 The client_questionnaire_completed database notification rows for every active tenant member exist at the moment ClientQuestionnaireCompleted is dispatched
- [x] #2 The audio_transcription_completed database notification row exists at the moment AudioTranscriptionCompleted is dispatched
- [ ] #3 A rolled back portal submission transaction still writes no notification rows and dispatches no broadcast
- [x] #4 Regression tests assert the ordering by observing state from inside an event listener, so they fail under QUEUE_CONNECTION=sync if the ordering regresses
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. In NotifyWorkspaceIfQuestionnaireCompleteAction, replace the queued per-user notify() loop with Notification::sendNow() over the active member collection, keeping it before event(). via() is database-only, so the queue hop bought nothing and cost the ordering. The caller runs inside DB::transaction and the event is afterCommit, so rows commit before the broadcast leaves.
2. In NotifyUploaderOfTranscriptionCompletedAction, move event() after the notify call and switch to notifyNow(), keeping the existing no-uploader early return from suppressing the firm-wide broadcast.
3. Drop ShouldQueue from both notifications so a stray notify() elsewhere cannot reintroduce the race, and update the class docblocks that claim the ordering.
4. Add regression coverage that captures notification row counts from inside an Event::listen closure, so the assertion observes the state at dispatch time rather than after the request.
5. Run the touched feature tests plus pint.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Fix: dropped ShouldQueue (and the now-dead Queueable/afterCommit/tags) from ClientQuestionnaireCompletedNotification and AudioTranscriptionCompletedNotification. Both are via() => [database], so the queue hop only ever bought one inserts worth of latency while costing the ordering. The rows now join the callers transaction, ahead of the afterCommit broadcast. NotifyUploaderOfTranscriptionCompletedAction also had event() before notify(); moved the dispatch to the end while keeping it outside the uploader check, since the bell is a firm-wide inbox.

tests/Unit/QueueableTagsTest.php had a test named "inbox notifications the bell reloads from Echo are queued" that pinned the bug in place. Inverted rather than deleted, with the reason recorded above it; its tags() assertions for these two went with the removed methods.

Verified the ordering test actually bites: reordering NotifyUploaderOfTranscriptionCompletedAction back to event()-then-notify() fails it with "Failed asserting that 0 is identical to 1" - the exact production symptom. Listeners use ??= so a second, correctly ordered dispatch cannot mask an early first one.

AC 3 partially met. The rows half is covered by "a rolled back submission leaves no inbox rows behind". The no-broadcast half is NOT asserted: QueueFake records pushes as they are made and ignores after-commit deferral, so Queue::assertNotPushed(BroadcastEvent::class) fails against correct code. That half is carried by $afterCommit on the event, is unchanged by this task, and is noted in a comment in the test.

Validation: vendor/bin/sail bin pest tests/Feature/Portal tests/Feature/Notifications tests/Feature/AudioRecordings tests/Feature/Retention tests/Unit -> 457 passed, 7550 assertions. phpstan analyse -> 0 errors. pint -> passed.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Un-queued the two database-only bell notifications so their rows are written before the Echo broadcast that tells the bell to go and read them, and moved the transcription dispatch after its notify(). Verified by counting rows from inside a real event listener in both domains, and by confirming the transcription test fails with "0 is identical to 1" when the old ordering is restored. 457 tests green across Portal/Notifications/AudioRecordings/Retention/Unit, phpstan clean, pint clean. AC 3s no-broadcast half is unasserted because QueueFake ignores after-commit deferral; see implementation notes.
<!-- SECTION:FINAL_SUMMARY:END -->
