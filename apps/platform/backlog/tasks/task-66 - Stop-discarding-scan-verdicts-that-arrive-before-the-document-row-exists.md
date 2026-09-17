---
id: TASK-66
title: Stop discarding scan verdicts that arrive before the document row exists
status: Done
assignee:
  - '@NielsvanHoof'
created_date: '2026-09-17 07:07'
updated_date: '2026-09-17 09:59'
labels:
  - documents
  - aws
dependencies: []
type: bug
ordinal: 137000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
ConsumeScanResultsCommand matches a verdict back to a document by S3 object key, looking up uploaded_documents.path and falling back to audio_recordings. When no row matches, dispatchResult returns false and the loop deletes the message anyway. So "no row yet" and "no row ever" are indistinguishable, and the first case loses the verdict permanently: the document keeps whatever scan state it had, no audit entry is written, and no thumbnail is attached even though the Lambda rendered one and it is sitting in the bucket.

That ordering is reachable. DocumentPresigner::moveObject server-side copies the claimed upload into the dossier tree from inside moveIntoPlace, which runs within the same DB::transaction in UploadDocumentForRequestAction that creates the row. The object therefore exists at its tenants/ key, and is scannable, before the row is committed. In AWS this is masked by GuardDuty taking seconds to minutes to return a verdict, so the commit always wins in practice. It is latent rather than theoretical, and automating the local trigger (TASK-65) makes it reproducible, because a warm Lambda container can beat the commit while a cold one cannot.

Deleting an unmatched message is the right behaviour for a key that will never have a row, which is why this needs a way to tell the two apart rather than simply never deleting. Note the queue already has a redrive policy with maxReceiveCount 5 to a dead-letter queue, so there is existing machinery for a message that cannot yet be handled.

This does not affect document delivery safety. ResolveMalwareScanStatusAction reads the object tag at delivery time and remains the authority on whether bytes may be served; the database column is presentation state.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A verdict that arrives before its document row is committed is still applied once the row exists
- [x] #2 A verdict for a key that will never have a row is not retried indefinitely
- [x] #3 Tests cover both the arrives-early case and the no-row-ever case
- [x] #4 Document delivery gating behaviour is unchanged
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Correct the premise recorded on this task: moveIntoPlace runs at UploadDocumentForRequestAction:72, before DB::transaction opens at :79 — not inside it. The object is scannable for the whole transaction, so the window is wider than described.
2. ConsumeScanResultsCommand: request MessageSystemAttributeNames ApproximateReceiveCount on receiveMessage, so the loop can tell a first delivery from a repeat.
3. Branch the unmatched case instead of always deleting. Below a small attempt threshold, leave the message for redelivery so the row has time to commit; at or above it, delete with a warning, because by then no row is coming.
4. Pick the threshold below the redrive maxReceiveCount of 5 so a genuinely orphaned key is dropped quietly rather than landing on the dead-letter queue and tripping scan-results-dlq-alarm. With the loop visibility timeout of 30s, three attempts gives roughly a minute of grace against a transaction that commits in milliseconds.
5. Treat an unreadable receive count as a first attempt. SQS bounds the retries anyway through the redrive policy, so the failure mode is a DLQ message rather than an endless loop.
6. Tests: replace the single no-owner case with two — a first delivery that is left on the queue, and an exhausted one that is deleted — plus a case proving a verdict lands once the row exists. Keep the existing no-key and unparseable-body behaviour unchanged.
7. Confirm delivery gating is untouched: ResolveMalwareScanStatusAction and GuardUploadedDocumentDeliveryAction are not modified, and their tests still pass.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Correction to the premise recorded at creation: moveIntoPlace runs at UploadDocumentForRequestAction:72, before DB::transaction opens at :79 — not inside it. The object is therefore scannable for the whole transaction, including the dossier lock, the submitUpload transition and the OCR pipeline start, so the window is wider than described rather than narrower.

Fix: ConsumeScanResultsCommand now asks SQS for ApproximateReceiveCount and branches on it when no row matches. Below MISSING_SUBJECT_ATTEMPTS the message is left on the queue so redelivery can find the committed row; at or above it the message is deleted with a warning.

The threshold is 3, set between two bounds. It must exceed the time from object landing to row commit, which is one transaction — three attempts at the 30s loop visibility timeout is about a minute against a commit measured in milliseconds. It must also stay below the queue redrive maxReceiveCount of 5, so a key that genuinely has no row is dropped here rather than redriven to the dead-letter queue, where it would trip scan-results-dlq-alarm. That alarm should mean the consumer is broken, not that someone deleted a document while its verdict was in flight.

An unreadable count falls back to a first attempt, erring towards retry. That cannot loop, because the redrive policy still moves the message aside after maxReceiveCount deliveries.

Verified beyond the mocks, because a mocked SqsClient cannot prove the request shape is accepted. Against the real SQS API through Floci: receiveMessage with MessageSystemAttributeNames ApproximateReceiveCount returned {"ApproximateReceiveCount":"1"}, and repeated polls returned 2, 3, 4 — so the attribute is accepted, arrives as a string, and increments across redeliveries, which is exactly what the branch reads. is_numeric handles the string form.

AC#4: ResolveMalwareScanStatusAction and GuardUploadedDocumentDeliveryAction are untouched — git reports no changes to either — and the full suite passes, so the object-tag delivery gate is unchanged.

Checks: full suite 1933 passed, 14655 assertions (two net new tests); pint clean on the modified command.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
ConsumeScanResultsCommand no longer discards a scan verdict whose document row has not committed yet.

It matched verdicts to documents by S3 object key and deleted the message whenever nothing matched, so "no row yet" and "no row ever" were indistinguishable and the first lost the verdict permanently — no status, no audit entry, and no thumbnail despite the Lambda having rendered one into the bucket.

That ordering is reachable, and by more than the task first recorded: moveIntoPlace copies the claimed upload into the dossier tree at UploadDocumentForRequestAction:72, before the transaction that writes the row opens at :79. The object is scannable for the whole of that transaction, not just its tail. GuardDuty normally takes far longer than a commit, which is why it had never been seen.

The command now requests ApproximateReceiveCount and branches on it. Below three deliveries an unmatched key is left on the queue, so the redelivery after the commit finds the row and applies the verdict; from the third it is deleted with a warning. Three sits deliberately between the commit time it must outlast and the redrive maxReceiveCount of 5 it must stay under, so an orphaned key is dropped quietly instead of reaching the dead-letter queue and tripping scan-results-dlq-alarm.

Verified past the mocks, since a mocked client cannot prove the request is well formed: against the real SQS API, MessageSystemAttributeNames returned ApproximateReceiveCount as "1" and successive polls returned 2, 3 and 4, confirming the attribute is accepted and increments across redeliveries. Tests cover the deferred first delivery, the exhausted delete, and a redelivered verdict applied once its row exists. Full suite 1933 passed.

Delivery safety is unchanged: ResolveMalwareScanStatusAction still reads the object tag at delivery time and neither it nor GuardUploadedDocumentDeliveryAction was modified.
<!-- SECTION:FINAL_SUMMARY:END -->
