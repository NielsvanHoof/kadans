---
id: TASK-66
title: Stop discarding scan verdicts that arrive before the document row exists
status: To Do
assignee: []
created_date: '2026-09-17 07:07'
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
- [ ] #1 A verdict that arrives before its document row is committed is still applied once the row exists
- [ ] #2 A verdict for a key that will never have a row is not retried indefinitely
- [ ] #3 Tests cover both the arrives-early case and the no-row-ever case
- [ ] #4 Document delivery gating behaviour is unchanged
<!-- AC:END -->
