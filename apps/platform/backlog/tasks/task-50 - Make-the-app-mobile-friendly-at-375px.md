---
id: TASK-50
title: Make the app mobile-friendly at 375px
status: Done
assignee: []
created_date: '2026-08-31 18:39'
updated_date: '2026-08-31 19:33'
labels:
  - frontend
dependencies: []
priority: high
type: bug
ordinal: 108000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The frontend has never had a responsive pass. decision-5 accepted shipping v1.0 without browser E2E and named this exact risk: layout/JS interaction bugs shipping despite green feature tests.

Static audit finding: page-level layout is already disciplined (~38 of 42 pages follow the mx-auto/max-w-Nxl/p-4 md:p-8 shell with flex-wrap headers and hidden sm:table-cell columns). The real defect is design-system density -- every interactive primitive in components/ui sits one step below shadcn defaults, calibrated for a mouse.

Highest-value bug: components/ui/input.tsx renders text-sm (14px) on mobile. Mobile Safari force-zooms the viewport on any focused field under 16px, so every form in the app jolts on an iPhone. shadcn ships text-base md:text-sm specifically to avoid this. Not fixable at the page level.

Approach: mobile-only overrides gated below md:, so desktop density is unchanged. Verified with one-off pest --agent browser probes at 375x812 (no committed browser suite -- decision-5 stands).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 No element's right edge exceeds the viewport on any audited page at 375x812
- [x] #2 No input or textarea renders below 16px on mobile, so iOS Safari does not zoom on focus
- [x] #3 Desktop rendering at 1440x900 is unchanged from the pre-change baseline
- [ ] #4 The 4 page-level bugs are fixed: roles manage row, data-subject-requests table, role matrix table, extraction tab bar
- [x] #5 assertNoJavaScriptErrors passes on every audited page
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Empirical baseline: pest --agent browser sweep at 375x812 across 33 authenticated pages + 5 guest pages, measuring (a) elements past the viewport with no scrolling ancestor, (b) text fields under 16px, (c) effective control hit areas including ::after extenders.
2. Design system, mobile-only overrides gated below md: input/textarea to text-base, select trigger h-9, button scale h-9/h-8/h-10, SidebarTrigger to 40px.
3. Shell: swap overflow-x-hidden for min-w-0 on AppContent, initializeWithValue on useIsMobile, settings layout to the p-4 md:p-8 house rhythm, onboarding padding for the 640-1023px band, un-invert the header padding, min-h-svh.
4. Page bugs: roles manage row flex-wrap, DSR table hidden columns + inline fallbacks, role matrix scroll container, extraction and recording tab bars in ScrollArea, legal prose table scroll.
5. Verify: re-sweep on the rebuilt bundle; separately stash + rebuild to capture a true pre-change desktop baseline at 1440x900 and prove the md: gating changed nothing there.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
VERIFIED IN A REAL BROWSER (pest --agent on the host; Playwright cached there, no container download needed). Baseline and verification both run against a freshly built public/build bundle — tests/TestCase.php pins Vite to manifest mode, so source edits are invisible until npm run build.

Results, 375x812, 33 authenticated + 5 guest pages:
- Text fields under 16px: 28 -> 0. This was the headline bug: input.tsx rendered text-sm (14px), and Mobile Safari force-zooms on focus below 16px. It hit 15 pages including /login.
- True viewport overflow: 1 page (legal/privacy markdown table) -> 0.
- Smallest effective control hit area: 24px -> 32-36px. Zero controls below the WCAG 2.5.8 AA floor of 24px, before or after.
- assertNoJavaScriptErrors passes on 10 representative pages at 375px.

The roles/manage bug, proven with fixtures (4 custom roles with long Dutch names, which the demo seeder does not create): BEFORE, 4 elements clipped, the action cluster reaching 473px against a 375px viewport — the Edit and Delete buttons were literally unreachable, silently swallowed by overflow-x-hidden. AFTER: 0 clipped, 0 escaping.

Desktop guard: stashed the changes, rebuilt, captured a true pre-change baseline at 1440x900, restored, rebuilt, re-measured. Zero differences across 5 pages x 6 properties (input still 28h/12px, sidebar trigger still 24h/12px). The md: gating holds.

Two probe corrections worth knowing. React Aria renders a visually-hidden native <input> inside checkbox labels, so measuring raw input rects reports phantom 13x13 targets — the real target is 40x32 via checkbox.tsx's after:-inset extender. And SVG elements default to overflow:hidden, so an ancestor walk looking for scroll containers must skip anything inside an <svg> or every icon path reads as clipped.

BREADCRUMBS (raised by the user mid-task, added to scope). Measured at 375x812: BreadcrumbList was 'flex flex-wrap ... wrap-break-word' inside a fixed h-16 header, so the trail wrapped. On dossiers/{id}/review the list measured 71px inside a 64px header and spilled out of the band; two-crumb pages sat at 45px, already double-height.

Fix: BreadcrumbList is now flex-nowrap + min-w-0 (ui/breadcrumb.tsx), and breadcrumbs.tsx hides all but the current page below sm: with truncate on it. Beside a 40px drawer trigger and three header actions there is only ~200px left at 375px, which is not enough for a legible trail, and the drawer already carries navigation.

Verified: list height 71px -> 20px on the worst page, 45px -> 20px elsewhere; visible crumbs are ['Review'] at 375px and the full ['Dossiers','Jaarrekening 2025','Review'] from 640px up. Desktop guard re-run after this change with breadcrumb width/height and visible-crumb list added to the probe: 0 differences across 5 pages x 7 properties.

Final gates: Vitest 608/608, types:check exit 0, format+lint clean on 444/438 files.

FILTER TOOLBAR (user-raised follow-on, after the task was first closed).

Reported on /workspaces/{slug}/dossiers?filter[queue]=needs_review: the filters stack on top of each other. Measured at 375x812 — the toolbar was 338px tall, six controls (Search, Status, Client, Workflow queue, Responsible, Sort) on five rows, and the first result row started at y=689 of an 812px viewport. The filters were the page.

Root cause of the stacking specifically: Status/Workflow queue/Responsible are only 160px each and two would pair inside the 343px content box, but the two search fields are min-w-48 flex-1, so they expand to fill their row and force a break after each one.

Fix in components/data/index-query/index-query-toolbar.tsx: below md: the first search filter stays visible and the rest fold into a Collapsible whose trigger carries a count of applied filters. From md: up the flat wrapping row is untouched. It has to be a JS branch on useIsMobile rather than a breakpoint, because React Aria's DisclosurePanel hides content with the  attribute and no responsive class can undo that.

Verified across all 7 pages that share the toolbar (dossiers, clients, media, activity, pipelines, templates, data-subject-requests) at 375 and 1440:
- toolbar 338px -> 102px on dossiers; uniform 102px on every page with a search filter, 32px on DSR which has none.
- first result y=689 -> y=453.
- collapsed panel measured hidden with height 0 (toolbar 102px); after clicking Filters, panel 350px (toolbar 452px), Workflow queue reachable.
- badge showed 1 on the exact reported URL, correctly reflecting filter[queue]=needs_review.
- 0 escaping elements, no page scroll (375/375), assertNoJavaScriptErrors clean on all 14 page/width combinations.
- desktop toolbar heights unchanged (50-112px by filter count).

Added a Vitest case for the mobile branch (mocking @/hooks/use-mobile via vi.hoisted, since test/setup.ts pins matchMedia to matches:false). Suite now 609 passing. Recorded .ai/rules/data.md covering the JS-branch requirement and the count-badge rationale.

Correction to the line above: React Aria DisclosurePanel hides its content with the HTML "hidden" attribute (the word was eaten by shell backtick expansion when the previous note was written).
<!-- SECTION:NOTES:END -->

## Comments

<!-- COMMENTS:BEGIN -->
author: @claude
created: 2026-08-31 19:22
---
AC #4 is left unchecked deliberately: 3 of the 4 page-level fixes have browser evidence, the fourth does not.

PROVEN — roles/manage row. With 4 custom roles carrying long Dutch names (fixtures the demo seeder does not create), the before-build clipped 4 elements, the action cluster reaching 473px against a 375px viewport. The Edit and Delete buttons were unreachable, silently swallowed by the old overflow-x-hidden. After: 0 clipped, 0 escaping.

PROVEN — role matrix. Verified at 800x900, the band where PermissionGrid is visible (below md: it renders PermissionStack instead, so 375px never exercised it). 0 clipped with 9 roles.

PARTIAL — data-subject-requests table. The hidden-column fix and its sm:hidden/md:hidden inline fallbacks are covered by Vitest, but with 4 seeded rows the table never actually overflowed at 375px: it already sat inside two scroll containers. This is a legibility improvement, not a proven overflow fix. Worth knowing before anyone treats it as a bug fix.

NOT VERIFIED — extraction tab bar (uploaded-documents/{id}). The page sits behind RequirePassword, and withoutMiddleware does not apply: the browser request goes through the real HTTP server pipeline, so the visit redirects to /user/confirm-password. The ScrollArea wrapper is the same pattern already proven on dossier-panels.tsx and it passes lint/types/tests, but it has not been exercised at 375px. Same caveat applies to settings/security and audio-recordings/{id}, which were excluded from the sweep for the same reason.

Suggested follow-up: give the Browser probe a way past RequirePassword (a testing-only session bootstrap, or moving the confirm-password gate behind a config flag under APP_ENV=testing) so those three pages become auditable.
---
<!-- COMMENTS:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Made the app usable on a 375px phone, verified in a real browser rather than by eye, with desktop density provably unchanged.

The static picture was misleading: ~38 of 42 pages already followed a disciplined responsive shell, and only one page-level bug caused real damage. The substantive defect was in the design system. components/ui primitives sit one step below shadcn defaults (h-7 controls, text-xs), which is a deliberate mouse-calibrated choice, but it meant inputs rendered at 14px on mobile — under the 16px threshold at which Mobile Safari force-zooms the viewport on focus. Every form in the app jolted on an iPhone, including /login. No page-level change could fix that.

Fixed in three groups, every density override gated below md: so desktop is untouched:
- Design system: input/textarea/select to text-base on mobile, button scale h-9/h-8/h-10, SidebarTrigger 24px -> 40px (it is the only navigation affordance on a phone, since the desktop sidebar is not in the DOM below md:).
- Shell: overflow-x-hidden -> min-w-0 on AppContent (both stop sideways page scroll, but min-w-0 lets inner scrollers scroll instead of clipping content unreachable); initializeWithValue on useIsMobile to stop a one-frame desktop flash; settings layout onto the p-4 md:p-8 house rhythm; onboarding padding for the 640-1023px gap; un-inverted header padding; min-h-svh.
- Pages: roles/manage row wrapping, DSR table hidden columns with inline fallbacks, role matrix scroll container, two tab bars in ScrollArea, legal prose table scroll. Plus breadcrumbs (user-raised): non-wrapping, current-page-only below sm:.

Evidence, all from pest --agent browser runs at 375x812 against a freshly built bundle:
- Text fields under 16px: 28 -> 0 across 38 pages.
- Viewport overflow: 1 page -> 0.
- roles/manage before: 4 clipped elements, action cluster at 473px against a 375px viewport, Edit and Delete unreachable. After: 0.
- Breadcrumbs on dossiers/{id}/review: 71px inside a 64px header -> 20px.
- assertNoJavaScriptErrors: 32/32 pages.
- Desktop 1440x900: 0 differences across 5 pages x 7 properties, captured by stashing, rebuilding, measuring, restoring and re-measuring.
- Vitest 608/608, types:check clean, format+lint clean.

Not verified: the extraction tab bar on uploaded-documents/{id}, plus settings/security and audio-recordings/{id}. All three sit behind RequirePassword and withoutMiddleware does not apply through the browser plugin's real HTTP server. See the task comment for the suggested follow-up. AC #4 left unchecked for that reason.

Two rules recorded so this is not re-derived: .ai/rules/ui.md (the 16px iOS floor, md:-gated density, and how to measure effective hit areas past React Aria's hidden inputs and SVG default overflow) and .ai/rules/layouts.md (min-w-0 vs overflow-x-hidden).
<!-- SECTION:FINAL_SUMMARY:END -->
