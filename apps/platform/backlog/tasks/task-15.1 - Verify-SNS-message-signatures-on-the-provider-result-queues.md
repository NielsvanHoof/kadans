---
id: TASK-15.1
title: Verify SNS message signatures on the provider result queues
status: Done
assignee:
  - '@Cursor'
created_date: '2026-08-06 09:56'
updated_date: '2026-08-06 17:26'
labels: []
dependencies: []
references:
  - >-
    app/DocumentProcessing/Console/Commands/Ocr/ConsumeTextractResultsCommand.php
parent_task_id: TASK-15
priority: low
type: enhancement
ordinal: 55000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The Textract result consumer decodes the SNS envelope message field and trusts it without signature verification. Anyone with permission to send to the results queue can inject a succeeded status for a known job id and drive the completion action.

The completion action does correctly re-scope to the owning document tenant, which limits the blast radius. And it requires AWS credentials, so it is not externally reachable - except that the leaked-credential task exists precisely because that assumption was weaker than it should have been.

The AWS SDK ships a message validator for this. If the consume-command unification lands first, the shared envelope parser is the right place for the check.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 SNS envelopes are signature-verified before their contents are trusted
- [x] #2 An unsigned or tampered message is rejected and logged rather than processed
- [x] #3 A test covers rejection of an invalid signature
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add aws/aws-php-sns-message-validator (required by AC; not currently installed).
2. In ConsumeTextractResultsCommand::parseSnsEnvelope, validate SNS Message when Type indicates SNS (Notification/SubscriptionConfirmation) before unwrapping Message.
3. Reject unsigned/invalid signatures: throw, log via existing failure path, do not call CompleteTextractExtractionAction.
4. Extend ConsumeTextractResultsCommandTest with invalid-signature rejection; keep happy-path with a mockable validator or valid fixture.
5. Prefer injecting MessageValidator so tests can stub without network cert fetch.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Transcribe consumer is EventBridge→SQS (not SNS); signature check is Textract-only until unification (TASK-10.3).
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
SNS→SQS Textract envelopes are signature-verified before the inner JobId/Status payload is trusted.

- Added `aws/aws-php-sns-message-validator` and bound `MessageValidator` in DocumentProcessingServiceProvider.
- `ConsumeTextractResultsCommand` validates via `Message::fromJsonString` + `MessageValidator` for SNS-shaped bodies; invalid signatures are logged and the SQS message is deleted without calling completion.
- Tests: happy path mocks validator; new case rejects forged signature and leaves the document processing.

Evidence: `vendor/bin/sail artisan test --compact tests/Feature/DocumentProcessing/ConsumeTextractResultsCommandTest.php` — 3 passed.
<!-- SECTION:FINAL_SUMMARY:END -->
