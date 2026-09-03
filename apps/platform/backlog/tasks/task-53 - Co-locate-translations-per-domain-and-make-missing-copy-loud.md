---
id: TASK-53
title: Co-locate translations per domain and make missing copy loud
status: In Progress
assignee:
  - '@niels'
created_date: '2026-09-02 22:07'
updated_date: '2026-09-03 09:30'
labels: []
dependencies: []
priority: high
type: chore
ordinal: 111000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
lang/en.json and lang/nl.json are one flat map of 1500 full-English-sentence keys each (103 KB / 110 KB). They grew from 1118 keys on 2026-08-03 to 1500 on 2026-09-02, and 8 of the last 40 commits touch them, so they are a standing merge-conflict surface and impractical to navigate.

File size is the least costly problem. Three failure modes are live today:

1. Copy edits silently orphan translations. With English sentences as keys, a typo fix changes the key, the Dutch value becomes an orphan, and the UI silently renders English. Coverage is one-way: used-but-missing fails, orphans never do.
2. t() and __() lie. dictionary[key] ?? key renders the English key, which in en is pixel-identical to working software. This is why __("All rights reserved.") in the vendor mail templates has been shipping English footers in Dutch email: it exists in neither locale file and is the only used-but-missing key in the repo.
3. t() interpolates only the first occurrence. use-translation.ts uses replace() with a string needle, so a key with :count twice substitutes once. Untestable today because mockUseTranslation drops the replacements argument.

Copy moves to app/<Domain>/lang/{en,nl}.json, one directory per app domain that carries copy (14 of them, with app/Shared/lang as the sink). Deleting or moving a domain takes its copy with it. Placement is derived from call sites by an artisan command, gated in CI. Keys stay full English sentences, so none of the 1479 t() calls or 540 __() calls change.

Baseline to preserve exactly (ksorted, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):
  en: 1500 keys sha256=f8b7a036ead1fe5fea636c41dc5bb78ba611160c494629cc66d79f2b65747ffa
  nl: 1500 keys sha256=bb21cbbb509c79cadfeda88ae569fe98a44522b7d5342f9d62712ed1c3b203eb

Full plan, including the four-commit sequencing and the verified framework constraints, is in the approved plan file.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Every domain that carries copy has app/<Domain>/lang/{en,nl}.json, and lang/en.json and lang/nl.json no longer exist
- [ ] #2 The merged dictionary is key-set and value identical to the recorded baseline for both locales, proven by a golden test against committed pre-split fixtures
- [ ] #3 php artisan lang:organize assigns each key to a domain from its call sites, is idempotent, and never deletes a key it cannot attribute
- [ ] #4 lang:organize --check fails when the files are stale, when lang/{locale}.json exists, or when a lang directory is not in the registered domain list, and runs in composer ci:quality
- [ ] #5 No key appears in more than one domain, every domain has identical key sets in en and nl, and every file is in canonical form with SORT_STRING key order
- [ ] #6 A duplicated raw key line is detected by scanning file text rather than the decoded array
- [ ] #7 Placeholder token multisets match between en and nl for every key
- [ ] #8 A missing or unreadable translation file throws rather than silently returning an empty dictionary, and a shipped environment fails to boot when copy is missing
- [ ] #9 The frontend dictionary equals the dictionary the Laravel translator resolves, asserted for both locales
- [ ] #10 The loader registers each domain exactly once, so Octane workers do not accumulate json paths
- [ ] #11 A missing key is reported in development by both t() and __() instead of silently rendering the English key
- [ ] #12 Repeated placeholders in one string are all substituted, covered by a unit test of the real useTranslation hook
- [ ] #13 The key "All rights reserved." exists in both locales, fixing the English footer in Dutch email
- [ ] #14 Rules and docs name the new layout, and .ai/rules/index.md maps the new glob
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implementation complete across seven commits. Verification: PHP 1673 passed / 13217 assertions, frontend 120 files / 649 tests, PHPStan 0 errors, Pint, Rector, vp check and types:check all clean, lang:organize --check green and idempotent.

Layout landed as 13 domains, not the 14 planned. DocumentProcessing turned out to own no copy of its own: its only four __() keys are the generic status words Pending, Failed, Completed and Processing, all shared with other domains, so all four resolve to Shared and the file came out empty. An empty file trips the loaders own throw, so app/DocumentProcessing was folded into Pipelines with a comment recording why.

Key counts: 1500 keys held exactly through commits 1-3 (merged sha256 matched the recorded baseline for both locales, 1500 lines so no duplicates), then 1416 after commit 4 purged 84 dead keys. That 84 matches the design reviews independent count of copy appearing nowhere in resources/js.

Two things caught during final verification that would have failed CI:

1. $this->app->isProduction() is a dynamic call to a static method and failed PHPStan. Replaced with $this->app->environment("production").
2. Rector wanted three files changed (a redundant string cast, Pest toHaveCount/toHaveSameSize idioms, FQCN-to-import). rector:check is in ci:quality so the branch was red until applied.

The mock interpolation fix had a much wider blast radius than the plan anticipated: 32 assertions across 15 component test files were asserting un-interpolated text. Several were vacuous rather than merely stale, e.g. queryByText(":count suggestions") in an absence check passes whether or not the text is shown, because the raw placeholder never renders. Those now assert something for the first time. Fixed by matching the interpolated shape with a regex wherever the value comes from a fixture, so a fixture change cannot break an unrelated assertion.

Also worth recording about the negative test: my first attempt deleted app/Dossiers/lang/en.json and then requested the nl dictionary. The loader correctly found every nl.json and returned 1416 keys, which looked like the guard had failed. It had not — the test was wrong. Re-run with a matching locale, it throws MissingTranslationsException naming the exact path. This is the same class of mistake the whole change exists to prevent: a check that reports a reassuring number while testing nothing.
<!-- SECTION:NOTES:END -->
