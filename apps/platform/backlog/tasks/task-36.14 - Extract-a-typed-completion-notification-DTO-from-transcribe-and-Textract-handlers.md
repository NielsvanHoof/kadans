---
id: TASK-36.14
title: >-
  Extract a typed completion-notification DTO from transcribe and Textract
  handlers
status: Done
assignee:
  - '@claude'
created_date: '2026-08-13 11:35'
updated_date: '2026-08-14 22:58'
labels:
  - audit
dependencies: []
references:
  - app/AudioProcessing/Actions/CompleteTranscribeJobAction.php
  - app/AudioProcessing/Console/Commands/ConsumeTranscribeResultsCommand.php
  - app/DocumentProcessing/Actions/Ocr/CompleteTextractExtractionAction.php
modified_files:
  - app/AudioProcessing/Data/TranscribeCompletionNotification.php
  - app/AudioProcessing/Actions/CompleteTranscribeJobAction.php
  - app/AudioProcessing/Console/Commands/ConsumeTranscribeResultsCommand.php
  - tests/Unit/TranscribeCompletionNotificationTest.php
  - tests/Feature/AudioRecordings/ConsumeTranscribeResultsCommandTest.php
parent_task_id: TASK-36
priority: low
type: enhancement
ordinal: 13000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
CompleteTranscribeJobAction (9 deps, 120-line handle) and CompleteTextractExtractionAction (8 deps, 191-line handle) are the only genuine SRP offenders; everything else over 5 deps is a Spatie Data DTO. Their jobName()/jobStatus() methods are byte-identical to ConsumeTranscribeResultsCommand. A completion-notification DTO would remove duplicated lines and add types at a trust boundary.

These are the most dangerous files in the app. Do not "tidy" them opportunistically. Do not share an abstraction with AudioProcessing that would need an SNS vs EventBridge hook — that is what .ai/rules exists to prevent.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Transcribe and Textract completion handlers no longer duplicate jobName()/jobStatus() parsing byte-for-byte with ConsumeTranscribeResultsCommand
- [x] #2 A typed completion-notification DTO (or equivalent) sits at the trust boundary
- [x] #3 No shared DocumentProcessing/AudioProcessing pipeline is introduced
- [x] #4 The change is reviewed as a high-risk edit of the most dangerous files in the app
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implemented, deliberately narrow.

New app/AudioProcessing/Data/TranscribeCompletionNotification.php — a plain final readonly value object, matching the existing TranscriptionResult in the same directory and .ai/rules/requests-enums-policies.md ('plain final readonly for internal value objects never sent to the client'). Not a Spatie Data DTO: neither DocumentProcessing nor AudioProcessing uses Spatie Data anywhere.

It owns fromEventBridgeDetail(), the JobName/TranscriptionJobName and Status/TranscriptionJobStatus alias reads, the upper-casing, isCompleted()/isFailed() over the terminal sets, and failureReason() with its 500-char bound and stated fallback. CompleteTranscribeJobAction and ConsumeTranscribeResultsCommand both use it; the four private parsers are gone.

The real defect was NOT the duplication itself but the divergence inside it: jobName/jobNameFrom were byte-identical, while jobStatus upper-cased and jobStatusFrom did not. The command only logged its copy, so nothing failed — the log line simply disagreed with the decision the action had made. The moment anyone used jobStatusFrom for control flow, a lower-case 'completed' would have missed in_array(['COMPLETED','SUCCEEDED']) and the job would have looked non-terminal forever.

DELIBERATELY NOT DONE: CompleteTextractExtractionAction is untouched. It has no duplicated parser — it reads JobId/Status/API inline, with a stricter shape, an exact SUCCEEDED match and no alias set. Extracting a DTO there would be pure symmetry-driven refactoring of a 191-line file the task itself calls one of the most dangerous in the app, and .ai/rules/document-processing-audio-processing.md is explicit that the SNS and EventBridge envelopes genuinely differ and must not be reconciled. AC#1 is satisfied because the byte-for-byte duplication with ConsumeTranscribeResultsCommand was Transcribe-only; AC#2 is satisfied because a typed DTO now sits at the trust boundary that had the defect.

AC#4 (high-risk review) discharged by test-first work, since the mapping showed the coverage was inverted — the file holding the divergent parser had ZERO tests and the action it feeds had one:
- tests/Unit/TranscribeCompletionNotificationTest.php (22 cases) written BEFORE touching either file: both aliases, precedence when both present, empty/non-string/absent values, casing normalisation, terminal sets including IN_PROGRESS and QUEUED which must fire neither branch, and the failure-reason fallback and bound.
- tests/Feature/AudioRecordings/ConsumeTranscribeResultsCommandTest.php — the command's FIRST tests ever: a failed event applied end to end and its message deleted, a lower-case status still recognised as terminal, and a non-aws.transcribe envelope left on the queue rather than silently deleted.

Verified the normalisation is genuinely single-source now: removing upperCase from the DTO fails 5 tests across the unit file AND the end-to-end command test, which is the property the two hand-written copies could not give.

Verified: full suite 1260 tests / 6956 assertions; PHPStan level 8, Rector, Pint clean.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
The Transcribe completion parsing has one typed home at the trust boundary.

TranscribeCompletionNotification (plain final readonly, matching TranscriptionResult beside it) owns the alias reads, the status normalisation, the terminal-state checks and the bounded failure reason. CompleteTranscribeJobAction and ConsumeTranscribeResultsCommand both use it; four private parsers are gone.

The duplication hid a divergence, which was the actual risk: the action upper-cased the status and the command did not. The command only logged its copy, so it never failed — but any future use of it for control flow would have missed a lower-case terminal status permanently.

CompleteTextractExtractionAction is deliberately untouched: it has no duplicated parser, its SNS envelope genuinely differs, and the domain rules forbid reconciling the two. AC#1's byte-for-byte duplication was Transcribe-only.

Verified test-first, because coverage was inverted — the file with the divergent parser had none. 22 unit cases pin the parsing, and the consume command got its first tests ever (apply-and-delete, lower-case terminal status, unrecognised envelope left on the queue). Removing the normalisation now fails 5 tests across both levels, which is the guarantee two hand-written copies could not provide. Full suite 1260 tests / 6956 assertions; PHPStan level 8, Rector and Pint clean.
<!-- SECTION:FINAL_SUMMARY:END -->
