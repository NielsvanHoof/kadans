---
id: TASK-59
title: Validate configured Transcribe locales against the accepted set
status: Done
assignee:
  - '@NielsvanHoof'
created_date: '2026-09-13 10:48'
updated_date: '2026-09-13 10:53'
labels: []
dependencies: []
priority: medium
type: bug
ordinal: 126000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
composer types:check has been failing since the dependency refresh (71c80af1). The AWS SDK now types StartTranscriptionJob LanguageOptions as a literal union of the 117 locales Amazon Transcribe accepts, and AmazonTranscribeAudioTranscriber passes non-empty-list<non-empty-string> built from the TRANSCRIBE_LANGUAGE_OPTIONS env var.

PHPStan is reporting a real defect, not SDK noise. The driver only checks that each configured value is a non-empty string and silently drops anything else, so a typo like nl-NL1 reaches AWS and fails StartTranscriptionJob per recording, asynchronously, with an opaque provider error — after the upload has been accepted and the pipeline started. Nothing validates the value at configuration time.

The accepted set is available exactly, from the SDK own api-2.json LanguageCode enum, so this can be validated against the real contract without narrowing what a deployment is allowed to configure.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 composer types:check passes with no suppressions, baseline entries, casts or inline @var
- [x] #2 A locale Amazon Transcribe does not accept is rejected before the API call, naming the offending value
- [x] #3 Every locale Amazon Transcribe does accept is still configurable
- [x] #4 A test covers both the accepted and rejected paths
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Generate a SUPPORTED_LANGUAGES constant on AmazonTranscribeAudioTranscriber from the SDK LanguageCode enum (117 values), so the allowlist is the real API contract rather than an invented product policy.
2. Replace the is_string/non-empty array_filter with a per-value check that throws InvalidArgumentException naming the bad locale, matching the bucket and empty-options guards already in start().
3. Confirm PHPStan narrows through the check without an @var or a cast; fall back from throw_unless to an explicit if only if larastan does not specify types through the helper.
4. Cover both paths in tests/Feature/AudioRecordings/AmazonTranscribeDriverTest.php.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Root cause confirmed as a real defect, not SDK noise: the driver filtered configured locales to "non-empty string" and passed them through, so TRANSCRIBE_LANGUAGE_OPTIONS=nl-NL1 reached AWS and failed StartTranscriptionJob per recording, asynchronously, after the upload was accepted and the pipeline had started.

Took the SDK LanguageCode enum (117 values) rather than curating down to the locales this product ships in. A curated list would have been smaller but would be an invented product policy: it would stop a firm configuring de-DE or fr-FR, which works today. The allowlist is the provider contract, so nothing that used to work stops working.

Two PHPDoc traps, both of which silently defeated the fix while looking like documentation:
- @var list<string> on the constant widened PHPStan literal inference back to string, so the check narrowed nothing.
- @return list<string> on the helper did the same at the call site.
@return list<value-of<self::SUPPORTED_LANGUAGES>> keeps the union tied to the constant, so adding a locale updates the return type on its own. The constant now carries a comment saying why it has no @var, because adding one back looks like an improvement.

Test guards the constant against the SDK model (require the api-2.json.php, compare sorted) rather than pinning a count, matching how WorkerQueueCoverageTest and WorkerJobCoverageTest guard their lists. An SDK bump that adds a locale now names the missing one instead of failing on a magic number.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
AmazonTranscribeAudioTranscriber now validates every configured locale against SUPPORTED_LANGUAGES — the 117 values from the SDK own LanguageCode model — and throws InvalidArgumentException naming the offending value before the API call, instead of filtering to "non-empty string" and letting AWS reject it asynchronously per recording. No suppressions, baseline entries, casts or inline @var: the narrowing is real, carried by @return list<value-of<self::SUPPORTED_LANGUAGES>>.

Verified: composer types:check passes with 0 errors (was 1, failing since 71c80af1). Five new tests in AmazonTranscribeDriverTest cover four rejected values (typo, bare language, wrong separator, non-string) asserting startTranscriptionJob is never called, and the accepted path with de-DE/fr-FR proving non-shipped locales stay configurable. The allowlist is compared against the SDK model rather than a pinned count. Full suite 1835 passed / 14193 assertions; Pint and Rector clean.
<!-- SECTION:FINAL_SUMMARY:END -->
