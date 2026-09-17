---
id: TASK-62.3
title: Record scan verdicts and thumbnails in the platform
status: Done
assignee:
  - '@NielsvanHoof'
created_date: '2026-09-16 08:13'
updated_date: '2026-09-16 09:10'
labels:
  - documents
  - backend
milestone: m-0
dependencies:
  - TASK-62.2
parent_task_id: TASK-62
type: feature
ordinal: 132000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Consumes the verdict messages the Lambda publishes and turns them into application state, so the UI finally has something to query and every verdict lands in the audit log instead of a dead-end SNS topic. This is the half of TASK-34.1 AC#4 that was never wired up.

Follow the shape the async provider rules already mandate: SQS long-poll and envelope parsing in a domain Console command that parses and dispatches only, terminal work in a domain Action. ConsumeTranscribeResultsCommand is the model, including why applying results inside the poll loop made one long recording everyone problem.

The delivery gate is deliberately out of scope. GuardUploadedDocumentDeliveryAction keeps reading the object tag as the authority for serving bytes, and the new column drives UI state only. A stale column that says clean is a worse failure than a slow download, and the existing tests in tests/Feature/Security/MalwareScanGateTest.php stay meaningful. The ~20ms per download stays; revisit separately if it ever matters.

The thumbnail is our own derived output from an already-clean source and carries no scan tag of its own, so serving it through the malware gate would refuse it forever.

Scanning is off by default outside AWS: there is no Lambda in the local stack, so nothing would ever arrive.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Documents carry a malware scan status, a scanned-at timestamp and a thumbnail path; audio recordings carry the scan fields
- [x] #2 A consumer command long-polls the results queue and parses and dispatches only, leaving transport failures to the redrive policy
- [x] #3 Applying a verdict updates the subject, writes an audit activity and broadcasts the change
- [x] #4 A message for an unknown key is discarded without wedging the queue
- [x] #5 The thumbnail is served through an authorised endpoint that does not apply the malware delivery gate
- [x] #6 Existing malware gate behaviour and its tests are unchanged
- [x] #7 Thumbnails are removed everywhere the source document is removed, including legal purge
- [x] #8 The feature is off by default locally and pinned off in the test configuration
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Migration adding malware_scan_status/malware_scanned_at to both tables and thumbnail_path to documents.
2. A MalwareScannable contract implemented by UploadedDocument and AudioRecording, so a verdict is applied through a method rather than by writing columns from outside.
3. ScanResultNotification parsing the Lambda message at the trust boundary.
4. scan:consume long-polling the queue, resolving the owning tenant and dispatching inside it.
5. ApplyScanResultJob on the default queue, and ApplyMalwareScanResultAction writing the row, auditing non-clean verdicts and broadcasting.
6. A thumbnail endpoint that bypasses the malware gate and password confirmation, with both departures documented.
7. config/scanning.php, off by default locally and pinned off in phpunit.
8. Thumbnail deletion on document delete and on legal purge.
9. Tests, then the registry tripwires those changes trip.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Two departures from the surrounding code, both deliberate and both pinned by tests.

**The thumbnail endpoint does not go through GuardUploadedDocumentDeliveryAction.** A thumbnail carries no GuardDutyMalwareScanStatus tag of its own -- it is output this application rendered, not bytes a client uploaded -- so the gate would fail closed on every thumbnail forever. Safety comes from the ordering instead: the render is triggered by the clean verdict, so an object that was never cleared is never rendered.

**The thumbnail endpoint is not behind RequirePassword, unlike preview and download.** It cannot be: an <img> in a list would follow the redirect to the password page and render as a broken image, which is the entire feature. The accepted cost is real -- a 400x560 render of page one is meaningful content, so a member whose confirmation has lapsed sees a little more than the filename they already could. The download policy ability is still required, and cross-firm access 404s on the tenant scope before the policy is even consulted. Flagged for the author rather than settled unilaterally.

**Clean verdicts are not audited.** One activity row per successful upload would bury the threats, unsupported files and scanner failures in the volume of everything that went fine. The row records that it was scanned and when; only non-clean verdicts write FileMalwareScanFlagged.

**PHPStan drove a better design.** The action first wrote the columns directly on an Illuminate Model, which PHPStan rejected as an undefined property. Rather than narrowing with a cast, the verdict now goes through MalwareScannable::recordScanVerdict, so each model decides what a verdict means for it -- and the instanceof UploadedDocument branch for the thumbnail disappeared.

**The tenant trap.** scan:consume is a landlord daemon but ApplyScanResultJob is tenant-aware, so a dispatch without a current tenant is silently discarded by Spatie on the worker. The tenant is resolved from the owning row rather than from the tenants/{id}/ path segment, so a crafted key selects nothing instead of the wrong firm. The test deliberately runs the job rather than faking the queue, because the tenant-scoped lookup finding the document is what proves the tenant was current.

**Registry tripwires this tripped, all updated:** WorkerJobs::NAMES, ControllerActionsTest non-REST actions, and two pinned translation dictionary counts. The AuditEventCoverage tests also required the new event to be both emitted and asserted.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Malware verdicts from the document-scan Lambda now reach the application. scan:consume drains the results queue, resolves the owning tenant and dispatches ApplyScanResultJob into it; ApplyMalwareScanResultAction writes the verdict through MalwareScannable::recordScanVerdict, audits the non-clean ones, and broadcasts so an open page can stop saying "scanning".

This closes the half of TASK-34.1 AC#4 that was never wired up: the THREATS_FOUND rule targeted an SNS topic and nothing in the application ever learned a verdict. It also gives the interface the queryable scan state that ResolveMalwareScanStatusAction openly conceded it had given up.

The delivery gate is untouched by design. GuardUploadedDocumentDeliveryAction still reads the object tag as the authority for serving bytes, and a test pins that writing "clean" into the column does not on its own make a document servable -- a stale copy that said clean would serve an infected file, which is a far worse failure than the ~20ms the tag read costs.

Verified: 1,926 Pest tests passing (up from 1,920), including 18 new ones across applying verdicts and the consumer daemon, plus thumbnail deletion on both document delete and legal purge. PHPStan clean at the project level, Pint clean, Rector clean, lang:organize reports no drift. The tenancy test runs the job rather than faking the queue, so it fails if the tenant is not current -- which is the production failure mode Spatie makes silent.

Not verified here: nothing has run against a deployed Lambda or a real queue. The consumer is exercised with a mocked SqsClient, and the wire format is pinned on both sides -- the Go tests assert what is written, these assert what is read -- but the two have never met.
<!-- SECTION:FINAL_SUMMARY:END -->
