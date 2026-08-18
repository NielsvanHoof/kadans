---
id: TASK-21
title: Fix the last-owner demotion test and the export job unique-lock window
status: Done
assignee:
  - cursor-agent
created_date: '2026-08-08 12:13'
updated_date: '2026-08-11 08:30'
labels: []
milestone: m-0
dependencies: []
modified_files:
  - tests/Feature/Tenancy/WorkspaceMemberManagementTest.php
priority: medium
type: bug
ordinal: 74000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Two defects surfaced while reviewing the pre-existing red tests on develop.

GenerateDossierExportJob declared an untyped, never-assigned 'public $timeout;' alongside #[Timeout(120)]. uniqueFor() multiplied that null by MAX_TRIES, so the unique lock auto-released after 100 seconds instead of 460. A second dispatch could therefore be accepted while the first attempt was still inside its 120-second timeout, allowing two workers to build the same export concurrently. Fixed by mirroring ProcessUploadedDocumentJob: a TIMEOUT_SECONDS constant feeding both the attribute and uniqueFor(). Verified: uniqueFor() now returns 460.

WorkspaceMemberManagementTest's 'the last owner cannot be demoted or removed' asserts assertSessionHasErrors('role') for an owner demoting themselves, but TenantMembershipPolicy::update() refuses every self-update, so the request is a 403 and validation never runs. The protection is intact — it is the assertion that names the wrong mechanism. The last-owner validator in UpdateTenantMemberRequest is separately reachable and correct: an Administrator with AssignMemberRoles demoting the sole Owner does produce 'The workspace must keep at least one owner.' Both behaviours confirmed empirically with pest --agent. This task is only about the test.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 GenerateDossierExportJob::uniqueFor() returns 460, derived from a TIMEOUT_SECONDS constant rather than a null property
- [x] #2 UniqueJobLockExpiryTest passes and reads each job's timeout from its actual source
- [x] #3 The self-demotion case asserts 403 rather than session errors
- [x] #4 A separate case covers an Administrator being blocked from demoting the sole Owner, so the last-owner validator stays covered
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
## Remaining work (AC #4)
Add a Feature test in `WorkspaceMemberManagementTest` after the self-demotion case:
1. `provisionWorkspace()` (sole owner) + `workspaceMember(..., Role::Administrator)`.
2. Admin `patch` on the owner's membership with a non-owner role (e.g. Administrator).
3. Assert session error on `role` with "The workspace must keep at least one owner." so the FormRequest validator stays covered (policy allows the request; validation refuses it).
4. Assert the owner's role is unchanged.
5. Run the focused Pest file / filter; check AC #3 already green; finalize.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
ACs 1 and 2 are done as part of clearing the PHPStan backlog: GenerateDossierExportJob now has a TIMEOUT_SECONDS constant feeding #[Timeout] and uniqueFor(), the null property is gone, and UniqueJobLockExpiryTest resolves each job's timeout from its real source (constant for OCR and export, the config-driven instance property for transcription, which is deliberate so Octane workers cannot cache it). Verified uniqueFor() returns 460 rather than 100.

ACs 3 and 4 are the test correction and are pending the user's call on whether self-demotion should stay a 403 or become a validation error.

Decision recorded 2026-08-08: self-demotion stays a 403.

`TenantMembershipPolicy::update()` refusing every self-update is the correct behaviour and stays as-is. The defect is only in the test, which asserts `assertSessionHasErrors(role)` and therefore names a mechanism the request never reaches. AC 3 is the assertion fix; AC 4 adds the separate case that keeps the last-owner validator in `UpdateTenantMemberRequest` covered via an Administrator with AssignMemberRoles demoting the sole Owner.

Rejected alternative: loosening the policy so a user reaches validation on their own membership and sees "The workspace must keep at least one owner." Better message, but it trades a blanket self-update refusal for a per-case validator, which is the weaker guarantee.

Resuming for AC #4 only. ACs #1–#3 already satisfied in tree; marking those when finalized.

Added administrator sole-owner demotion Feature test covering UpdateTenantMemberRequest last-owner validator. Verified self-demotion still asserts 403. UniqueJobLockExpiryTest still green.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Finished the remaining member-management test coverage for TASK-21.

- AC #1–#2 were already in tree (`GenerateDossierExportJob::TIMEOUT_SECONDS` → `uniqueFor()` = 460; `UniqueJobLockExpiryTest` reads real sources).
- AC #3 was already fixed: self-demotion/removal asserts `403` at the policy layer.
- AC #4 added `an administrator cannot demote the sole owner` in `WorkspaceMemberManagementTest`: an Administrator with `AssignMemberRoles` patches the sole owner's membership to `administrator`, gets `assertSessionHasErrors(['role' => 'The workspace must keep at least one owner.'])`, and the owner role stays put. That is the FormRequest path the self-update 403 never reaches.

## Tests
- `WorkspaceMemberManagementTest` filters for last-owner + administrator demotion — passed
- `UniqueJobLockExpiryTest` — passed (regression for AC #1–#2)
<!-- SECTION:FINAL_SUMMARY:END -->
