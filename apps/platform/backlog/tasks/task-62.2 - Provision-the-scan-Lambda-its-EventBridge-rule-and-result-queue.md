---
id: TASK-62.2
title: 'Provision the scan Lambda, its EventBridge rule and result queue'
status: Done
assignee:
  - '@NielsvanHoof'
created_date: '2026-09-16 08:12'
updated_date: '2026-09-16 08:32'
labels:
  - infra
  - aws
milestone: m-0
dependencies:
  - TASK-62.1
parent_task_id: TASK-62
type: feature
ordinal: 131000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Adds the first compute resource to a stack that has had none since TASK-43.3 reduced it to a data plane.

The existing malware-findings rule in malwareProtection.ts matches THREATS_FOUND only and targets the alerts SNS topic. It stays as it is -- operator alerting is a separate concern from application state -- and a new rule matching every scan status targets the Lambda.

Delivery back to the application is SQS plus a consumer command rather than an HTTPS webhook, mirroring the existing Textract and Transcribe plumbing: no new public endpoint, messages buffer while the application is down, and alarms.ts already has the DLQ-depth alarm pattern to copy.

Two traps the infra rules already record: environment differences belong in environments/settings.ts as data and never as a check on environment.name, and resourceName() output is a wire format, so read the preview diff -- a rename reads as delete-then-create and `pulumi up` destroys whatever the program stops declaring.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A Lambda function is provisioned from the built zip on provided.al2023/arm64 with an execution role scoped to reading tenants/, writing thumbnails/, the documents CMK, SQS send and its own logs
- [x] #2 An EventBridge rule matching all GuardDuty scan statuses for the documents bucket targets the function, with a delivery dead-letter queue
- [x] #3 The existing THREATS_FOUND rule to the alerts topic is left in place and unchanged
- [x] #4 A scan-results queue and its dead-letter queue exist with a redrive policy, and the application access policy can consume from it and read thumbnails/
- [x] #5 Dead-letter queue depth is alarmed in line with the existing queues
- [x] #6 Thumbnail objects are covered by the bucket lifecycle rules
- [x] #7 The bootstrap deploy policy is widened so a deploy cannot fail halfway on a missing permission
- [x] #8 Environment differences are expressed as settings data, with stack tests covering the new resources in staging and production
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. New component src/shared/documentScanHandler.ts: results queue + redrive DLQ, an EventBridge delivery DLQ with its queue policy, a scoped execution role, the Lambda from a FileArchive, a scan-result rule matching every verdict for this bucket, the invoke permission and the target.
2. Wire it from index.ts ahead of the access policy, and export the queue URLs and function name.
3. Extend alarms.ts with createDocumentScanAlarms for both new DLQs, following the three existing DLQ alarms.
4. Extend policies.ts so the application can consume the results queue and read thumbnails/ -- read only, since thumbnails are the Lambdas output.
5. Widen bootstrap deployPolicy.ts for Lambda, and for the role lifecycle and PassRole the stack has always needed.
6. Stack tests in staging (behaviour), production (parity plus one function only) and local (it is built there too).
7. Build the zip in deploy.yml before pulumi up, and add a discovered-matrix Lambdas CI workflow.
8. Document it in infra/README.md.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Deviation from the plan, with reason: the Lambda test job went into a new .github/workflows/lambdas.yml rather than platform.yml. platform.yml is path-filtered to apps/platform/** and defaults its working directory there, so apps/lambdas changes would never have triggered it. The new workflow discovers the matrix by finding folders that contain a Makefile, so adding a function is adding a directory, and toolchain setup is conditional on the manifest that proves it is needed -- a Python function needs no edit here.

No thumbnails lifecycle rule was added, deliberately. expire-noncurrent-versions already uses an empty filter, which is bucket-wide, so superseded thumbnails expire on the same schedule as everything else. A second rule would only be a second thing to keep in step. Asserted rather than added.

Pre-existing gap found and closed, worth flagging rather than burying: the bootstrap deploy policy had no iam:CreateRole, iam:PutRolePolicy or iam:PassRole at all, while the program has declared textract-publish-role and malware-scan-role since before this task. A fresh CI deploy would have failed halfway on those. This change needed role creation for the Lambdas execution role anyway, so the grant is in scope and incidentally repairs the older gap. It is safe because DenyModifyingOwnAccess already denies those actions on the deploy roles themselves and an explicit Deny beats the Allow; PassRole is additionally conditioned on the destination service.

The function is unconditional rather than gated on an environment setting. GuardDuty is created on every stack including local, nothing about rendering a page differs per environment, and a knob whose value is identical everywhere is one that will eventually differ by accident.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Added src/shared/documentScanHandler.ts -- the first and only compute resource in a stack that has had none since TASK-43.3 reduced it to a data plane. A scan-result EventBridge rule matching every GuardDuty verdict for the documents bucket invokes a Go function on provided.al2023/arm64, which writes thumbnails to thumbnails/ and publishes verdicts to a new scan-results queue for the application to drain.

Delivery is SQS plus a consumer rather than a webhook, matching the Textract and Transcribe plumbing: no new public endpoint, messages buffer while the application deploys, and the redrive-plus-alarm pattern already existed to copy. Both new dead-letter queues are alarmed, and they catch different failures -- one a verdict the application could not apply, the other an invocation that never happened.

The execution role reads tenants/ and writes thumbnails/ and nothing else, so a rendering bug cannot overwrite a clients original document. The application gains consume-and-read, deliberately not write: thumbnails are the Lambdas output.

Verified from the repository: 46 infra tests and 11 bootstrap tests passing, up from 34 and 11. New assertions cover the runtime, handler name and architecture agreeing, the queue URL reaching the function, the rule matching every status while malware-findings still matches only THREATS_FOUND, the delivery DLQ and its queue policy, the redrive count, both role policies scoped by prefix, the applications read-only thumbnail grant, and the lifecycle rule already covering thumbnails. Confirmed the suite passes with dist/function.zip absent, so the stack tests do not depend on the Go build -- only pulumi up does.

Not verified here: nothing has been applied. No AWS call was made, and the rule, function and IAM grants are declared and asserted rather than observed. Read the pulumi preview diff before applying -- pulumi up destroys anything the program stops declaring, and resourceName() output is a wire format.
<!-- SECTION:FINAL_SUMMARY:END -->
