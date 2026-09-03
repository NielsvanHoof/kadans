---
id: TASK-51
title: Roll a dossier over to the next period
status: Done
assignee:
  - '@niels'
created_date: '2026-09-02 19:52'
updated_date: '2026-09-02 21:26'
labels: []
dependencies: []
priority: high
type: feature
ordinal: 109000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Accountancy work is annual. Today the only way to start next year is to create a dossier from scratch and re-apply a questionnaire template, which discards every stable answer the client already gave (legal form, shareholders, bank accounts, KYC facts). Competing Dutch platforms sell "doorrollen naar een volgende periode" as a headline capability and we have no equivalent.

Staff pick a completed or in-progress dossier, choose "Doorrollen", and get a new dossier for the next period with the same questionnaire structure, the same visibility rules, and the stable answers carried forward. Uploaded files never carry over because they belong to the period they were filed for.

This task covers the staff side end to end. Showing the carried-forward answer to the client in the portal as a confirmable suggestion is the follow-up task.

Key facts established while scoping:

- ApplyQuestionnaireTemplateToDossierAction (app/Dossiers/Actions/Builder/) is already the engine: transaction, DossierLocks, one fresh ULID per item, visibility-rule remapping, sort ordering, audit logging. Rollover is the same shape with a Dossier as source instead of a QuestionnaireTemplate.
- document_requests.field_key is globally unique, so keys must be re-minted and configuration.visibility.source_field_key remapped to the new keys.
- configuration copies verbatim, and choice option ids live inside configuration, so answer_option_ids stay valid with no remapping.
- dossiers.reference is unique on (tenant_id, reference), so the clone cannot copy it.
- dossiers has no period column at all. This task adds one so the feature name matches the data model.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A dossier can be rolled over from the dossier show page by a member holding the new dossiers.roll_over permission, and the button is hidden for members without it
- [x] #2 The new dossier belongs to the same client and tenant, links back to its source via rolled_from_dossier_id, and carries period_year incremented by one with period_label re-derived
- [x] #3 The rollover dialog prefills title, period and due date from the source dossier and requires a reference that is unique within the tenant
- [x] #4 Every document request on the source is recreated with a freshly minted field_key, the same type, title, instructions, configuration and sort order
- [x] #5 Visibility rules survive the clone: configuration.visibility.source_field_key on each new request points at the new field_key of the same sibling question, not the source dossier key
- [x] #6 Answers carry forward per type: answer_text for text, textarea, date and number; answer_boolean for boolean; answer_option_ids for choice and multi_select
- [x] #7 File-type requests never carry an answer and no uploaded document is cloned
- [x] #8 Every cloned request resets status to Pending and clears answered_at, reviewed_by, reviewed_at and rejection_reason, and records carried_forward_at when an answer was carried
- [x] #9 Nothing else is cloned: no pipelines, audio recordings, client access grants, dossier exports, builder lock fields or activity log entries
- [x] #10 Staff can opt out of carrying answers, producing a structure-only clone with every answer empty
- [x] #11 The rollover is written inside one transaction under DossierLocks in canonical order and writes an audit activity entry for the new dossier
- [x] #12 period_year and period_label are visible on the dossier show page and the dossier index, and period_label is searchable
- [x] #13 All new copy exists in both lang/en.json and lang/nl.json
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Migration add_period_and_rollover_to_dossiers_table: nullable period_year (unsignedSmallInteger), nullable period_label (string), and rolled_from_dossier_id via foreignId()->nullable()->constrained("dossiers")->nullOnDelete(). Per migrations.md use constrained() and never foreignIdFor. Add a (tenant_id, period_year) index only if EXPLAIN ANALYZE against seeded Postgres justifies it, and record the numbers in the docblock; skip it otherwise. Keep the migration portable to SQLite.

2. Migration add_carried_forward_at_to_document_requests_table: nullable timestamp. Deliberately no new DocumentRequestStatus case; cloned requests stay Pending so isAwaitingClient(), awaitingClientValues(), the dashboard follow-up queries and portal progress all keep working untouched. carried_forward_at is what tells the portal a prefilled answer is a suggestion rather than a submission.

3. Dossier model: add period_year, period_label to $fillable, cast period_year to integer, add rolledFromDossier() and rolledOverDossiers() relations, add period_label to toSearchableArray() and to auditableAttributes(). DocumentRequest model: add carried_forward_at to $fillable and cast to immutable_datetime.

4. QuestionnaireItemType: add carriesAnswerAcrossPeriods(): bool as an exhaustive match (File false, everything else true). Per requests-enums-policies.md status and capability sets live on the enum as an exhaustive match so PHPStan flags a new case, never as a literal array at the call site. The per-type carry target is then read off the existing storesAnswerText() and storesAnswerOptionIds().

5. DossierPermission: add case RollOver = "dossiers.roll_over" with label, description, group() and defaultRoles() (never Owner). Register in config/tenant-permissions.php or PermissionRegistry will not see it. Add rollOver() to DossierPolicy via AuthorizesTenantPermission. Re-run artisan tenancy:generate-permissions and tenancy:sync-tenant-roles.

6. App\Dossiers\Actions\RollOverDossierAction with handle(Dossier $source, RollOverDossierData $data): Dossier. Modelled on ApplyQuestionnaireTemplateToDossierAction. One DB::transaction. Lock via DossierLocks in canonical order, parent before child: DossierLocks::dossier($source) then DossierLocks::documentRequestsFor($source) — never inline lockForUpdate(), per dossiers-client-portal.md. Mint one fresh ULID per source request keyed by source request id (not by field_key, for the same reason the apply action documents). Build the sourceFieldKey => newFieldKey map from the source requests and reuse the same remapVisibility shape. Copy type, title, instructions, configuration, sort_order. Carry answers only when $data->carryAnswers and the type carriesAnswerAcrossPeriods(), setting carried_forward_at on those rows. Force status Pending and leave answered_at, reviewed_by, reviewed_at, rejection_reason null throughout. Clone nothing else. Log AuditEvent::DossierCreated for the new dossier via LogAuditActivityAction.

7. Add AuditEvent::DossierRolledOver if the audit enum needs a distinct verb; otherwise reuse DossierCreated and record the source id in the activity properties. Check app/Audit/Enums before adding a case.

8. RollOverDossierData (Spatie Data, final, TypeScript attribute) under app/Dossiers/Data. RollOverDossierRequest under app/Dossiers/Http/Requests using LocalizesValidationAttributes: required title, nullable period_year, nullable period_label, required reference unique on (tenant_id, reference) excluding soft-deleted rows, nullable due_date, boolean carry_answers. authorize() delegates to $this->user()->can().

9. Route POST dossiers/{dossier}/roll-over declared in routes/tenant/dossiers.php before any {param} catch-all, named dossiers.roll-over, integer-constrained on {dossier}. No RequirePassword: this streams no document bytes.

10. DossierController::rollOver(): authorize, call the action, redirect to the new dossier show route with a success flash. Three steps only, per controllers.md.

11. Frontend: add can_roll_over plus period fields to DossierShowData and the index row Data object, regenerate types with npm run types:check. Add a roll-over dialog in resources/js/features/dossiers using Inertia <Form> with the Wayfinder .form() helper from @/actions. Prefill title and period from props, default period_year to source + 1, leave reference empty and required. Every Select needs aria-label. Add the period column to the dossier index table. Gate the button on can_roll_over only.

12. Copy: add every new string to lang/en.json and lang/nl.json together as full-sentence keys. Outcome-first, no engineer-speak, no mention of field keys, clones or cascades.

13. Tests. tests/Feature/Dossiers/RollOverDossierActionTest.php with covers(RollOverDossierAction::class), using provisionWorkspace(), actingInWorkspace() and workspaceRoute() — never hand-rolled tenancy. A ->with([...]) dataset over QuestionnaireItemType for the carry matrix, named keys per type. Separate non-dataset tests for: visibility remap points at the new sibling key, file requests carry nothing and clone no uploads, pipelines and audio recordings and access grants and exports are not cloned, builder lock fields are not copied, structure-only mode when carry_answers is false, reference collision returns a validation error, and the audit entry is written. tests/Feature/Dossiers/DossierPolicyTest.php gets rollOver coverage across all three required cases: happy path, forbidden role, and owner vs read-only divergence. Extend tests/Unit/DossierLockOrderTest.php expectations to cover the new file, and confirm the Feature DossierLockOrderTest DB::listen ordering still passes.

14. Run vendor/bin/sail bin pint --dirty --format agent, npm run types:check, and the narrowest Pest selection covering the changed files. Then ask the user to run the full suite.

Corrections to steps 3, 5, 6 and 7, after verifying against the code:

3a. No separate audit step is needed for the new columns. LogsTenantAuditableActivity::auditableAttributes() returns getFillable() minus tenant_id, so adding period_year, period_label and rolled_from_dossier_id to $fillable audits them automatically. Do not add a hand-maintained attribute list.

5a. The role sync command is tenancy:sync-roles, not tenancy:sync-tenant-roles. The permission generator is tenancy:generate-permissions and takes --check, which CI uses to fail on a stale generated file.

6a. DossierLocks exposes dossier(), trashedDossier(), documentRequest(), documentRequestWithDossier() and documentRequestsFor(). Use dossier($source) then documentRequestsFor($source) — both exist and both are the only sanctioned way to lock those two tables.

7. Resolved: AuditEvent has no DossierCreated case. It has DossierViewed, DossierCompleted, DossierDeleted, DossierRestored, the export and reminder verbs, and DocumentRequestCreated. So add a new case DossierRolledOver = "dossier.rolled_over" to app/Audit/Enums/AuditEvent.php with its label, and record the source dossier id in the activity properties. Each cloned document request still logs DocumentRequestCreated, matching what ApplyQuestionnaireTemplateToDossierAction already does.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Backend complete and green (30 tests: 22 action, 8 endpoint, plus 8 in the extended DossierPolicyTest).

Shipped: two migrations (verified against Postgres with a rollback and re-apply), Dossier + DocumentRequest model changes, QuestionnaireItemType::carriesAnswerAcrossPeriods(), DossierPermission::RollOver, DossierPolicy::rollOver(), AuditEvent::DossierRolledOver, RollOverDossierData, RollOverDossierAction, RollOverDossierRequest, DossierRollOverController, and the POST dossiers/{dossier}/roll-over route.

Three deviations from the recorded plan, all following sibling convention over what the plan guessed:

- Step 10 said DossierController::rollOver(). Built DossierRollOverController::store() instead, matching DossierCompletionController, DossierReminderController and DossierExportController, which are all single-verb controllers alongside the fat DossierController.
- Step 8 said RollOverDossierData should be a Spatie Data object with a TypeScript attribute. It is a plain final readonly class instead: requests-enums-policies.md mandates Data for payloads *sent to the client*, and this one only travels controller to action. ReminderDraftContextData is the existing precedent and the only non-Data file in app/Dossiers/Data.
- Step 4 planned to mirror the new enum capability into the generated TypeScript. Left it out: GenerateQuestionnaireItemTypesCommand enumerates its three flags by hand rather than reflecting, so the generated file stays byte-identical (--check confirms), and no frontend registry branches per type since carrying answers is one staff decision for the whole dossier.

Two bugs caught before they shipped:

- carriedAnswer() first used blank() to skip unanswered questions. blank(false) is true, so a legitimate "No" on a yes/no question would have been silently dropped. Now tested per column with explicit null checks, and the carry matrix dataset covers "boolean no" specifically.
- DossierPolicy::rollOver() is deliberately NOT gated on ! $dossier->status->isLocked(), unlike every sibling write gate. Rolling over a completed period is the primary use case. Covered by its own named test rather than added to the existing locked-ability dataset, which would have asserted the opposite.

Also derived in the action rather than only in the dialog: period_year advances by one, and period_label has the source year substituted in place ("Jaarrekening 2025" to "Jaarrekening 2026") so a direct POST without a period still lands in the right one. A label with no recognisable source year is copied verbatim.

Cross-tenant access returns 404, not 403 — the tenant middleware refuses the route before the policy runs. Asserted as 404 with a comment saying why.

Remaining: frontend (props, dialog, index column) and the lang/en.json + lang/nl.json copy.

Frontend and copy complete. Full verification green:

- PHP suite: 1649 passed, 8833 assertions
- Frontend: 117 files, 632 tests passed (plus 5 new dialog tests)
- Pint, PHPStan (0 errors), vp check, npm run types:check all clean

Frontend shipped: DossierRollOverDialog, a "Start next period" item in DossierActionsMenu, canRollOver threaded through DossierPermissionsProvider (not prop-drilled), a Period column on the dossier index, and a "Rolled over from :period" backlink on the show header. 17 new copy keys plus "Reference" in both en.json and nl.json.

Notes for review:

- canRollOver goes through the capability context and is deliberately NOT narrowed by dossier status there, mirroring DossierPolicy::rollOver(). Documented on the type.
- The carry_answers checkbox is nameless and drives a sibling hidden input, per the pattern client-access-card.tsx documents: a React Aria Checkbox with a name posts "1" when ticked and nothing when unticked, which a required|boolean rule reads as missing rather than false. A test asserts the hidden input flips 1/0.
- FetchDossierIndexQuery::subject() has an explicit select(), so period_year/period_label had to be added there or every row would have rendered an empty column with nothing failing.
- No AllowedSort on period_year: it would be an unindexed sort path on a db.t4g.micro, and no acceptance criterion asks for it.
- The dialog test queries the hidden input off document, not the render container: React Aria portals the modal out of it.

Three incidental findings, none of them this task:

1. lang/en.json and lang/nl.json both contain "Scan confidence" TWICE (lines 899 and 1167, identical values). Any parse-and-rewrite of those files silently drops one. I inserted copy textually instead and left the duplicate alone. Worth its own cleanup task.
2. InertiaPropContractTest documents a rebuild command that does not work through Sail (the env var is not forwarded) and a --filter that matches nothing. Recorded the working invocation as a rule in .ai/rules/tests.md.
3. Running `backlog task create -s "In Progress"` moved the unrelated TASK-34.4 from In Progress to To Do. I reverted that file; TASK-34.4 is back In Progress. Looks like a single-active-task or WIP-limit behaviour in the CLI that should be checked before the next task is created that way.

Swapped the raw <Input type="date" /> for the project DatePicker component (resources/js/components/forms/date-picker.tsx), matching dossier-follow-up-fields.tsx and portal-document-answer.tsx.

Two things came with it rather than being a straight swap:

- DatePicker submits through its own hidden input, so it needed no wire-format change: it serialises CalendarDate to YYYY-MM-DD, which is exactly what the nullable|date rule takes, and empty string when cleared (ConvertEmptyStringsToNull turns that into null).
- Completed the due-date prefill the plan asked for and the first pass skipped. The dialog now suggests the source due date one year on, derived through CalendarDate.add({years: 1}) rather than string surgery on the year, so 2024-02-29 clamps to 2025-02-28 instead of producing a day that does not exist and that parseDate would reject. Required widening the dossier prop from Pick<... period_label> to include due_date, in both the dialog and DossierActionsMenu.

DatePicker formats its trigger label through useFormatters, which reads usePage(), so the dialog test needed mockUseFormatters alongside mockUseTranslation.

Dialog tests now 8 (was 5): added due-date suggestion, the leap-day clamp, and an empty due date when the source has none.

Re-verified: frontend 118 files / 640 tests passed, types:check and vp check clean, and LanguageCoverageTest + InertiaPropContractTest + tests/Feature/Dossiers still green (166 tests).

Period is now a bounded year select instead of a free number input, and the leaking validation copy is fixed.

The two halves of the problem:

1. The control. period_year was <Input type="number" />, so the only thing standing between a typo and a rejected form was the server-side min:1900/max:2200. Replaced with a React Aria Select offering seven years, anchored on the SOURCE period rather than on today (source - 1 through source + 5): a firm catching up on a late 2023 book year needs 2024 at the top of the list, not the current year. One year back is offered because the same source is sometimes rolled into a sibling deliverable for the period it already covers. The 1900-2200 rule stays as defence in depth but is now unreachable from the interface.

   Follows the dossier-follow-up-fields.tsx pattern for a nullable select: controlled Select with no name, an explicit __no_period__ sentinel option, and a sibling hidden input carrying the wire value. Keeps period_year nullable, which the server already allowed.

2. The copy. "period year moet minimaal 1900 zijn" came from min.numeric with :attribute falling back to the humanised column name, because LocalizesValidationAttributes had no period_year entry. Added an attributes() override on RollOverDossierRequest listing all six of its fields, matching how InviteTenantMemberRequest and UpdateTenantSettingsRequest do it.

   That override also fixes a contradiction the shared map would have produced here: the shared label is "Reference (optional)", but reference is REQUIRED on a roll-over, so a failure would have read "Reference (optional) is required". The roll-over request calls it "Reference".

   Only due_date was added to the shared trait map, since StoreDossierRequest and UpdateDossierRequest validate it too and had the same raw-column-name fallback.

A regression test asserts the messages, not just the rules: period_year errors must contain "Period" and not "period year"; reference errors must contain "Reference" and not "optional".

Also normalised the dialog file to single quotes. It had drifted to double quotes, which vp check accepts (there is no quote rule in vite.lint.ts or .editorconfig) but which no sibling file uses.

Verification after the change: PHP 1650 passed / 8841 assertions, PHPStan 0 errors, Pint clean, frontend 118 files / 642 tests (dialog tests now 10), types:check and vp check clean.

Closed the last two acceptance-criteria evidence gaps before finalizing, rather than checking them off from implementation intent:

- AC1 (button hidden without the permission) had server-side coverage only. Added resources/js/features/dossiers/tests/show/dossier-actions-menu.test.tsx: the item appears with the permission, is absent without it, the whole trigger disappears when a member holds none of the three abilities, and the menu item opens the dialog.
- AC12 (period_label searchable) had the column in toSearchableArray but nothing exercising it. Added a case to tests/Feature/IndexQuery/DossierIndexQueryTest.php searching filter[q]=Boekjaar+2025 and asserting the single matching row carries period_label and period_year.

Final verification: PHP 1651 passed / 8854 assertions, PHPStan 0 errors, Pint clean, frontend 119 files / 646 tests, vp check and types:check clean. Manually exercised in the browser by the user.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Staff can start the next period from an existing dossier, carrying the questionnaire and the answers that are still true a year later.

RollOverDossierAction clones the questionnaire structure with a freshly minted field_key per item and rewrites every visibility rule to the new sibling keys, so conditional questions survive the clone instead of pointing back at last period. Answers carry per item type (answer_text / answer_boolean / answer_option_ids); a File item always rolls over empty because a document is filed for the period it belongs to. Carried answers stay Pending and are stamped carried_forward_at, which keeps DocumentRequestStatus untouched — so every exhaustive match on it, the dashboard follow-up queries and portal progress all keep working — and gives TASK-52 the hook it needs to render them as suggestions to confirm. Uploads, pipelines, audio recordings, portal grants, exports, review verdicts and the builder lock are deliberately not cloned.

New: dossiers.period_year / period_label / rolled_from_dossier_id, document_requests.carried_forward_at, DossierPermission::RollOver, AuditEvent::DossierRolledOver, and POST dossiers/{dossier}/roll-over. The dialog prefills the period and due date one year on (via CalendarDate, so a leap day clamps rather than producing a date that does not exist) and offers a bounded year select, leaving the reference empty because dossiers are unique on (tenant_id, reference).

Verified with 1651 PHP tests / 8854 assertions and 646 frontend tests, PHPStan level 8 clean, Pint and vp check clean, the migrations applied and rolled back against Postgres, and a manual pass through the browser by the user.
<!-- SECTION:FINAL_SUMMARY:END -->
