---
id: TASK-61
title: Give a client one portal link across all their dossiers
status: Done
assignee:
  - '@niels'
created_date: '2026-09-15 09:23'
updated_date: '2026-09-15 10:37'
labels: []
dependencies: []
priority: high
type: feature
ordinal: 128000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
A ClientAccessGrant is scoped to one dossier. A client with a VAT dossier and a year-end dossier open at the same time — the normal state for a firm — gets two unrelated magic links, two invite emails, two reminder streams, and no view anywhere of everything they owe. ClientPortalReminderDispatcher also mints a brand-new grant on every reminder, so that client accumulates fresh live tokens weekly.

A client-scoped grant fronts the whole book rather than one dossier, so a leaked link is worth more than today. decision-4 accepted re-exchangeable tokens for v1.0 with the reopen trigger "before the first real client document enters the system, reassess whether block should become the default, or whether tighter binding or shorter grant TTL is required". This feature is that trigger: client-scoped grants get their own shorter TTL and a decision record superseding it. New-device policy stays notify — block aborts 404 with no explanation, which on a client phone on a new network is this product worst failure mode.

Consolidating reminder emails across dossiers is deliberately out of scope; per-dossier reminders keep working and carry the client-level link.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A client-scoped grant reaches every non-draft, non-deleted dossier of its client, evaluated live, so a dossier shared after the link was sent becomes reachable by it
- [x] #2 A client-scoped grant returns 404 — never 403 — for another client dossier, a draft dossier, a soft-deleted dossier, and a dossier in another tenant, on every portal route including upload-ticket minting
- [ ] #3 Dossier-scoped grants behave exactly as today, and the existing portal test files pass unmodified
- [x] #4 Staff can share a dossier with a client without minting a token, and markAwaitingClient has exactly one call site afterwards
- [x] #5 Staff can issue and revoke a client-level link from the client page, and client-scoped grants covering a dossier are visible read-only on that dossier access panel
- [x] #6 A client with two or more reachable dossiers lands on a list of them; with one, straight on it; with none, on a calm empty state rather than an error
- [x] #7 GDPR erasure and export cover client-scoped grants, which have a null dossier_id
- [x] #8 Archiving one dossier does not revoke a client-scoped grant; archiving the client does
- [x] #9 Client-scoped grants use their own shorter TTL, and a decision record supersedes the decision-4 reopen trigger
- [x] #10 All new copy exists in both en.json and nl.json via lang:organize
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. ClientAccessGrantScope enum (dossier|client). DossierStatus::isSharedWithClient() + sharedWithClientValues() via a casesWhere helper copied from DocumentRequestStatus; refactor lockedValues() onto it. Draft is the security boundary and is durable — nothing transitions back to Draft.
2. Migration add_client_scope_to_client_access_grants_table: add client_id (nullable, constrained, cascadeOnDelete) + scope string(16) default dossier; backfill client_id from dossiers via correlated subquery with no deleted_at filter; throw if any NULL survives; then client_id nullable(false) and dossier_id nullable(); composite FK (tenant_id, client_id) -> clients(tenant_id, id) and index (tenant_id, client_id). No partial unique index — valid() is time-dependent and re-issuing after revocation is supported. down() must work; CI runs a rollback job.
3. Model: client() relation, scope cast, fillable, docblock. Factory client_id + clientScoped() state. DemoDataSeeder seeds one client-scoped grant.
4. The chokepoint — app/ClientPortal/Queries/Access/FetchReachableDossiersQuery (the only definition of reachability) and ResolveGrantedDossierQuery (implemented in terms of it, aborts 404, takes an int not a Dossier so a caller cannot hand in an already-trusted model). Does NOT consult canSubmit(); see-vs-submit are two questions with two chokepoints.
5. Rewire all 8 sites: SubmitPortalAnswerAction:54 and SubmitPortalUploadAction:70 (under the grant row lock, keep throwing ModelNotFoundException so the transaction unwinds), ClientPortalAnswerController:30, ClientPortalUploadController:42,155, StorePortalUploadedDocumentRequest:42, StorePortalQuestionnaireAnswerRequest:43 (both keep returning true on a miss so the controller emits the concealed 404 — authorize() false yields 403 and portal denials are never 403), ClientPortalUploadTicketController:35. Plus FetchClientPortalQuery:55 and RecordClientPortalAccessAction:31,40.
6. ShareDossierWithClientAction in ClientPortal (not Dossiers — it needs ClientAccessGrantPermission at L5 and Dossiers is L4). Refactor CreateClientAccessGrantAction to call it so markAwaitingClient returns to one call site.
7. Routing: GET /portal/ becomes a dispatcher (dossier-scoped -> redirect; client-scoped 1 -> redirect; >=2 -> portal/index; 0 -> empty state). GET /portal/dossiers/{dossier}. Submission routes stay flat. StorePortalUploadTicketRequest gains a required dossier_id run through the chokepoint — without it a client grant could mint a presigned PUT for any dossier.
8. Payload: PortalDossierData gains id; ClientPortalPageData gains otherDossiers; new PortalDossierSummaryData, PortalLandingPageData, FetchClientPortalLandingQuery (eager-load documentRequests on the reachable set so outstanding counts are N PHP passes over one query).
9. Frontend: pages/portal/index.tsx, portal-dossier-list, portal-dossier-switcher (plain Links, not a navigating Select — keyboard trap and must render under renderToString). Extend portal-ssr.test.tsx.
10. Staff side: Clients cannot import ClientPortal (tests/Pest.php:366-379, no ignoring), so invert via App\Clients\Contracts\ClientPortalAccess + ClientPortalLinkData, implemented by ClientPortal and bound in its provider. ClientPortalLinkController, IssueClientPortalLinkAction (no publishing), invite notification, destroy redirect branches on scope, card on the client show page. FetchDossierShowQuery::accessGrantRows also surfaces covering client grants read-only — without it staff revoke the dossier link and wrongly believe access is cut.
11. Edge cases: GDPR must-fix (AnonymizeDataSubjectAction:205,226 and GenerateDataSubjectExportAction:366 use whereIn dossier_id and would leave a live link to an erased person); RevokeClientPortalAccessOnClientArchived (otherwise the link resurrects on restore); explicit scope filter on RevokeClientPortalAccessOnDossierArchived; audit payloads emit client_id + nullable dossier_id; new-device alert names the client; dashboard/activity-log null-dossier assumptions.
12. portal.client_grant_ttl_days config + validation cap; decision record superseding decision-4.
13. Tests per the plan, then lang:organize, pint, types:check, npm test.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Chokepoint landed. ClientAccessGrantScope enum, DossierStatus::isSharedWithClient (Draft is the boundary; Completed stays reachable and read-only), migration (verified up/rollback/up on Postgres and the NOT NULL enforced on SQLite, both composite FKs and all indexes intact), model/factory, FetchReachableDossiersQuery + ResolveGrantedDossierQuery, all 8 comparison sites rewired, ShareDossierWithClientAction with CreateClientAccessGrantAction refactored onto it (markAwaitingClient back to one call site), portal.dossiers.show route, landing query and Data objects.

Two deviations from the plan, both deliberate:
1. GET /portal/ renders the single reachable dossier inline instead of redirecting. A redirect on every portal open costs a round trip on a phone and would have changed the behaviour of every link already in a client inbox. Legacy links now keep the same URL, page and props.
2. Submission redirects go to portal.dossiers.show, not portal.show, so a client on a multi-dossier link stays in the dossier they were working in. 9 redirect-target assertions across 4 test files updated; token-exchange and validation-error redirects still target portal.show and were left alone.

Found while rewiring: a cross-dossier ANSWER returned 403 and wrote a LogAccessDeniedAction audit row, while the equivalent UPLOAD returned 404 and wrote nothing. Verified both empirically before changing anything. The ClientPortal rules require 404 and no audit row for portal probes, so the answer path was the odd one out; both now defer to the controller 404 via the chokepoint.

tests/Feature/Portal + tests/Feature/ClientPortal: 102/102 green.

Feature complete; verification in flight.

Staff side: ClientPortalLinkController + StoreClientPortalLinkRequest (own TTL ceiling), IssueClientPortalLinkAction (shares nothing — that is the point), ClientPortalLinkInviteNotification, ShareDossierWithClientController, revoke redirect branches on scope. Clients cannot import ClientPortal (tests/Pest.php:386, no ignoring), so links and both permission booleans arrive through App\Clients\Contracts\ClientPortalAccess + ClientPortalLinkData, implemented by ClientPortalAccessProvider and bound in ClientPortalServiceProvider. Client-level grants also surface read-only on the dossier access panel via a new Dossier::clientLevelAccessGrants() relation keyed on client_id (the model carries the standing arch exception, the query does not).

Frontend: pages/portal/index.tsx, portal-dossier-list, portal-dossier-switcher (plain Links, no navigating Select), ClientPortalLinkCard on the client page, ShareDossierWithClientButton on the dossier access panel. RevokeAccessGrantButton exported and reused rather than duplicated. 26 new keys in en+nl via lang:organize.

Three things found while building, all fixed:
1. RegisterClientPortalDeviceAction resolved the grant dossier and returned early when there was none — a client-scoped link would have silently lost the new-device alert, which decision-4 names as a compensating control. Alert now builds from the client and links to the client page; notification dossier fields nullable.
2. The landing list counted a completed dossier as still needing the client. Staff can complete a dossier with items open, and canSubmit() refuses those writes, so it was telling clients to do something impossible. Locked dossiers now report nothing remaining.
3. LogClientPortalReminderSent/Failed did firstOrFail() on the grant dossier id. Unreachable today (only the per-dossier dispatcher mints grants that get there) but it would throw inside a queued listener; both now return early.

Tests: PortalClientGrantAccessTest (11 — every refusal asserted as 404 with no audit row), PortalDossierLandingTest (6, incl. legacy dossier-link parity), ClientLevelAccessGrantTest (7), ShareDossierWithClientTest (6), PortalGrantDossierScopeTest (source scan, verified it actually fails by injecting the old comparison and restoring), DossierStatusTest extended, portal-ssr.test.tsx extended. createPortalClientWithGrant() added to tests/Pest.php.

Green so far: tests/Feature/Portal + ClientPortal 119, tests/Unit + Localization 231, tests/Feature/Platform 263, tests/Feature/Clients + Architecture 42, frontend 701, npm run types:check exit 0, lang:organize --check up to date. Full backend suite and phpstan running.

AC #3 is deliberately left unchecked. The substantive half is proven — a dossier-scoped link renders the same page at the same URL with no switcher (PortalDossierLandingTest), still opens only its own dossier even when the same client has a sibling shared dossier (PortalClientGrantAccessTest), and all 119 portal tests pass. The literal half is false: I changed 9 assertions across 6 existing test files. All 9 assert a redirect TARGET, and the target genuinely changed — a submission now returns the client to the dossier they were working in (portal.dossiers.show) rather than portal.show, which is required once one link can open several dossiers. Token-exchange and validation-error redirects still target portal.show and were left alone. No behavioural assertion was modified. I wrote that AC before knowing the submission redirect had to move; it should have said "behave as today" without "unmodified".

Evidence for the rest: #1 PortalClientGrantAccessTest (opens both shared dossiers; a dossier shared after issue becomes reachable). #2 same file — 404 for another client, a draft, a soft-deleted and a cross-tenant dossier, across the page, answer and upload-ticket routes, each asserting the activity-row count is unchanged. #4 ShareDossierWithClientTest, plus `grep -rn markAwaitingClient app/` returning one caller. #5 ClientLevelAccessGrantTest incl. the client link showing read-only on the dossier page and absent from another client dossier. #6 PortalDossierLandingTest (N>=2 list, N=1 inline, N=0 empty state). #7/#8/#9 ClientLinkLifecycleTest (archive one dossier vs archive the client, erasure, export manifest, TTL default and ceiling). #10 lang:organize --check up to date plus LanguageCoverageTest.

Checks: full backend suite 1891/1891, phpstan 0 errors, pint clean, rector clean on every file this task touched (three pre-existing offenders under Questionnaires/Tenancy left alone), lang:organize --check up to date, frontend 701/701, npm run check clean, types:check exit 0, production build exit 0. docs/security.md updated for scope, the separate TTL, and that the upload quota is per dossier rather than per link.

Note for review: .ai/rules/client-portal.md still opens with "a ClientAccessGrant scoped to one dossier", which is now incomplete. I did not edit it — recording or amending project rules is not something I do unprompted.

Verification totals: full backend suite 1893/1893 passed (run b4pfymzjz, after rector + phpstan fixes); a later run adds the 5 ClientLinkLifecycleTest cases. Frontend coverage 53.67% lines against the configured floor of 49 (vite.config.ts:198), so the gate holds with the new components in place.

One flake seen and chased down: DataSubjectExportTest "a second worker arriving mid build does not build a second archive" failed once in a mid-way full run and passed in isolation and in three consecutive tests/Feature/Retention runs, plus in both complete full runs. It simulates two workers racing for the same claim, so it is order-sensitive rather than related to this change.

Definitive full backend suite after everything: 1898/1898 passed (up from 1854 at the start of the task — 44 new tests).
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
A client now gets one portal link covering every dossier their firm has shared with them, instead of one link per dossier.

ClientAccessGrant gains a scope (dossier|client) and always carries client_id; dossier_id becomes nullable. The eight scattered `$documentRequest->dossier_id === $grant->dossier_id` checks — two of them the authoritative re-check under the grant row lock — collapse into FetchReachableDossiersQuery, reached through ResolveGrantedDossierQuery. Reach is "same client, not draft, not deleted", evaluated live, so a dossier shared next month opens behind a link sent today. Draft is the boundary and it is durable: nothing moves a dossier back to it.

Publishing became its own act. Issuing a per-dossier grant used to be the only thing that called markAwaitingClient, which is useless when the link already exists, so ShareDossierWithClientAction now owns it and CreateClientAccessGrantAction calls that — markAwaitingClient is back to one caller.

GET /portal/ renders the single reachable dossier inline rather than redirecting, which keeps every link already in a client inbox on the same URL with the same props; two or more get a landing list, none gets a calm empty page rather than an error.

Three defects found and fixed on the way, each verified before and after: a cross-dossier answer returned 403 and wrote an access-denied audit row where the upload path returned a concealed 404 and wrote nothing; the new-device alert returned early when a grant named no dossier, silently disabling a decision-4 compensating control for the widest scope; and erasure and export found grants with whereIn(dossier_id), which a null dossier_id falls straight through — that one would have left a working portal link to an erased person.

Verified: full backend suite 1893/1893, frontend 701/701 plus an extended SSR guard, phpstan 0 errors, pint and rector clean on every file touched, lang:organize --check up to date, types:check and production build exit 0, frontend coverage 53.67% against a floor of 49. 39 new backend tests, the sharpest being PortalClientGrantAccessTest, which asserts every refusal is a 404 and leaves the activity count unchanged, and PortalGrantDossierScopeTest, a source scan proven to fail by re-injecting the old comparison. decision-12 records the separate, shorter TTL for client-scoped links and answers the part of decision-4 that widening the scope forced.

AC #3 is left unchecked on purpose: the parity it asks for is proven, but its literal "existing portal test files pass unmodified" is not — 9 redirect-target assertions across 6 files changed, because a submission now returns the client to the dossier they were working in. No behavioural assertion moved.
<!-- SECTION:FINAL_SUMMARY:END -->
