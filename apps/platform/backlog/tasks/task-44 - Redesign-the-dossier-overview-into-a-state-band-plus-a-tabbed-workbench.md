---
id: TASK-44
title: Redesign the dossier overview into a state band plus a tabbed workbench
status: In Progress
assignee:
  - '@NielsvanHoof'
created_date: '2026-08-24 09:49'
updated_date: '2026-08-24 13:42'
labels: []
dependencies: []
ordinal: 102000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The staff dossier overview stacks seven equal-weight cards (header, follow-up, stage rail, exports, audio, client access, still waiting), so nothing signals priority and the two rarest actions (exports, audio upload) take roughly 40% of the page height. Invite is also named three times and wired together by an #dossier-invite anchor hack, and the audio section pops in after paint because its visibility depends on a deferred prop. Collapse the page into two landmarks: a state band (stage rail + follow-up facts) and a tabbed workbench (Waiting / Access / Recordings / Exports) whose tab labels carry live counts.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 The overview renders exactly two landmarks below the header: one state band and one tabset
- [ ] #2 Tab labels show counts for waiting items, valid access grants, recordings and exports
- [ ] #3 The active panel is deep-linkable via ?tab= and survives a page reload
- [ ] #4 A failed audio upload keeps the Recordings panel selected and paints its inline validation error (TASK-42 regression)
- [ ] #5 The header CTA and the stage rail's invite step select the Access panel instead of jumping to an anchor
- [ ] #6 Creating an access grant lands on the Access panel with the one-time portal link shown inline
- [ ] #7 Recordings and Exports tab visibility is correct on first paint, with no layout shift when the deferred props land
- [ ] #8 A viewer without download or audio permissions sees only the Waiting and Access tabs
- [ ] #9 The tab strip scrolls horizontally at 375px and is keyboard navigable with visible focus rings
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Backend: add exportsCount + audioRecordingsCount to DossierShowData and loadCount in FetchDossierShowQuery; regenerate TS types and the Inertia prop contract fixture.
2. Extract the URL-synced tab hook from features/questionnaires/use-template-library-tab.ts into a domain-agnostic hooks/use-url-tab.ts, and rewrite the questionnaire hook as a wrapper. The URL must be read only in the useState initialiser so a back() redirect cannot reset the panel (TASK-42).
3. Add features/dossiers/show/dossier-panel.ts with DOSSIER_PANELS, isDossierPanel and the shared waitingDocumentRequests filter.
4. Add dossier-state-band.tsx: one surface, two hairline-separated rows (stage rail on top, follow-up datum grid plus Remind below).
5. Add dossier-panels.tsx: Tabs variant=line with the four panels, owning the Deferred wiring from dossier-show-deferred-sections.tsx. Default panel access when a portal URL was flashed, exports when completed, otherwise waiting.
6. Rewrite dossier-show-content.tsx around header + state band + panels; drop the #dossier-invite section and anchor.
7. Strip Card chrome from waiting-items-summary, client-access-card, audio-recordings-card and dossier-export-card; PortalLinkBanner becomes an Alert.
8. Add lang keys Recordings and Exports to en.json and nl.json.
9. Tests: new dossier-panels.test.tsx carrying over the TASK-42 assertions, new use-url-tab.test.ts, extend DossierShowPageTest, regenerate the prop contract fixture.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implemented. Backend: DossierShowData gains exportsCount/audioRecordingsCount, filled by loadCount(['exports','audioRecordings']) in FetchDossierShowQuery::dossierPayload(); the exports/audio_recordings rows stay deferred. Prop contract fixture regenerated.

Frontend: extracted the URL-synced tab hook to hooks/use-url-tab.ts (domain-agnostic) and rewrote features/questionnaires/use-template-library-tab.ts as a wrapper over it. Added features/dossiers/show/{dossier-panel.ts,dossier-state-band.tsx,dossier-panels.tsx}; dossier-show-deferred-sections.tsx deleted (its Deferred wiring and its TASK-42 coverage both moved into dossier-panels). Stripped Card chrome from waiting-items-summary, client-access-card, audio-recordings-card and dossier-export-card; PortalLinkBanner is now an Alert inside the Access panel. The #dossier-invite anchor is gone: the header CTA and the rail's invite step call setPanel('access').

Two refinements beyond the plan: the tab triggers carry text-sm (the strip is the page's main section switcher, and the component default text-xs read as a filter chip row), and the Waiting empty state is now status-aware, since promoting that panel to the page default made 'switch to Review when uploads arrive' wrong for a closed dossier. Removed three lang keys orphaned by the dropped card titles (Export dossier, Still waiting, Client access).

Verification run: biome clean, npm run types:check clean, 528/528 vitest, phpstan 0 errors, pint passed, DossierShowPageTest 15/15, LanguageCoverage 5/5, Dossier|InertiaPropContract|DomainStructure 221/221. Also confirmed the custom utilities survive a production Tailwind build (even:bg-ledger-stripe, divide-ledger-rule, border-ledger-rule, bg-brand-muted all present in app-*.css).

Full PHP suite: 1406/1409. The 3 failures are outside this diff and pre-existing — ProductionCaddyfileTest x2 needs docker/production and docker/staging Caddyfiles removed in commit d40ac18, and DashboardProcessingHealthTest counts 4 instead of 3 because of the uncommitted PipelineStatus change making Cancelled retryable.

Still needs a real browser before this can be finalised: the tab strip at 375px, keyboard focus rings, and the TASK-42 manual check (a rejected audio upload must keep the Recordings panel selected and paint its inline error). Pest browser could not run — Playwright 1.62.1 is installed but its browser binaries are not, and 'npx playwright install' was not run without approval.

Added dossier-state-band.test.tsx (6 cases) covering the piece the plan left untested: stage placement, the current-stage-only hint, aria-current=step, the invite step calling onSelectInvite rather than navigating, and the review stage being blocked until an item exists. Final JS run: 534/534 across 99 files, biome and types:check clean.

Design pass (second round, with screenshots).

Thesis: the page is a ledger sheet, not a stack of cards — grounded in app.css, which already declares --ledger-rule, --ledger-stripe, a ledger-rows utility 'lifted from ledger paper', and .font-data with 'tabular-nums slashed-zero' so counts line up like a ledger column and '0 and O stay apart in reference codes'. That vocabulary existed in exactly one file (data-table.tsx).

Changes: state band loses its card border and becomes a band between hairline rules, full-bleed on mobile. Stage rail is one line of equal-height cells with the current-stage hint on its own row beneath (inside a cell it made one column taller and the row stopped reading as a sequence); the arrow now appears only on stages that open another route, so it means navigation rather than decoration. Waiting list drops its rounded outline for ruled rows. dossier.reference becomes a font-data eyebrow above the title — it is shown on every other dossier surface and was missing from the dossier's own page. Coral went from 5+ appearances (status icon, three datum chips, stage number, CTA) down to one: the primary action.

Signature — the chase line (features/dossiers/follow-up/dossier-chase-line.tsx). The four dates were three unrelated stat cells with 'next reminder' buried as a subtitle. They are now one ruled line: Sent -> Opened -> Reminder -> Due, marks filled when reached and hollow when not, the deadline a terminal stop that picks up urgency colour only when earned. Evenly spaced, not to scale — gaps are irregular and any date can be null, so a proportional axis would lie. Verified in dark mode with an overdue dossier: sent filled, opened hollow, reminder hollow, crimson stop + '9 days overdue' icon — the chase state reads from the marks alone.

Backend: DossierShowData gains a signed daysRemaining (null without a due date), computed as (int) today()->diffInDays(due_date, false) — the same contract as FetchDashboardMyWorkQuery, whose comment explains the browser must not redo calendar-day maths across timezones. Reused the existing DaysRemainingLabel rather than reinventing overdue styling, and relocated it from features/dashboard to components/primitives (pure presentational, now shared by dashboard and dossiers).

Also removed the redundant 'Items the client still needs to submit or update.' lead — it sat under a tab labelled 'Waiting 3' above a list of waiting items.

Verified: screenshots at desktop, 375px mobile, and dark+overdue. No hardcoded colours in any changed file, so dark mode flips on tokens alone. biome clean, types:check clean, 541/541 vitest (100 files), phpstan 0, Dossier|InertiaPropContract|DomainStructure|AppArchitecture|LanguageCoverage 231/231. Prop contract fixture regenerated. New tests: dossier-chase-line.test.tsx (7 cases incl. all-null placeholders and the overdue path) and a PHP case covering signed days for future/today/past/none.

Deviation from the plan, stated plainly: the plan promised coral in exactly two places (CTA + active tab underline). The shared Tabs line variant underlines with --foreground, and overriding it only here would diverge from document-extraction-view's tabs, so coral appears once. One accent on one primary action is the more disciplined outcome; I did not change the shared component for it.

Still unverified by a real interaction (screenshots only): keyboard focus rings and the TASK-42 manual upload-failure check.
<!-- SECTION:NOTES:END -->
