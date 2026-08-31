---
paths:
  - 'resources/js/components/data/**'
---

# Data

## Index filters collapse below md: via a JS branch, not a breakpoint
IndexQueryToolbar renders two different trees. Below md: the first search filter stays visible and everything else folds into a Collapsible carrying a count of applied filters; from md: up it is the flat wrapping row. Seven index pages share this, so changes here are wide-reaching.

Why a JS branch and not CSS: React Aria's DisclosurePanel hides its content with the `hidden` attribute, which no Tailwind breakpoint can undo. A responsive class cannot "re-show" a collapsed panel — the desktop layout has to be a genuinely separate tree, keyed off useIsMobile().

Why it exists: six controls stacked one per row measured 338px on a 375px screen and pushed the first result to y=689 of an 812px viewport. The two `min-w-48 flex-1` search fields are what spread the selects across five rows — they expand to fill and force a break, even though three 160px selects would otherwise pair up.

The count badge is not decoration. Collapsing filters is only safe if an applied filter stays visible somewhere, otherwise a filtered list looks wrong with no discoverable cause. Keep it if you restructure this.

Testing the mobile branch: test/setup.ts stubs matchMedia to always report `matches: false`, so the desktop tree is all you get by default. Mock `@/hooks/use-mobile` through vi.hoisted to exercise the other branch.
