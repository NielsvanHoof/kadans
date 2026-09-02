---
paths:
  - 'resources/js/components/ui/**'
  - resources/js/components/ui/badge.tsx
---

# Ui

## Never run `shadcn add --overwrite` — these components diverge on purpose
Most files here differ from the shadcn registry deliberately: i18n on the dialog close button, `motion-reduce:` on animations, `MinusIcon` for the indeterminate checkbox, `CardTitle` as a real `<h2>` with a `level` prop, brand colours on the `link` variants, `validationBehavior="aria"` on Select (so Laravel stays the validation source of truth instead of a browser bubble), and the coral selected-row wash in Table.

`shadcn add <component> --overwrite` silently reverts all of it. To pull an upstream change: `npx shadcn@latest add <component> --dry-run`, then `--diff <file>`, and merge by hand.

Note the CLI's `-y` does NOT imply overwrite, but it also does not suppress the per-file overwrite prompt — use `yes n | npx shadcn@latest add <components>` to add new components while declining every existing-file overwrite.

A ~2-line diff is usually just the `"use client"` directive the CLI strips (`rsc: false`) and is not local work.

## Control density is desktop-first; mobile overrides are md:-gated
This design system sits one step below the shadcn registry on purpose (h-7 controls, text-xs). That is a mouse-calibrated choice — keep it, but never let it reach a phone unmodified.

Two hard rules:

1. Text inputs must render at 16px below `md:`. Mobile Safari force-zooms the viewport whenever a focused field is under 16px, so `text-sm` (14px) makes every form on the app jolt on an iPhone. Write `text-base md:text-xs/relaxed`, never `text-sm md:text-xs`. Applies to input.tsx, textarea.tsx and select.tsx.

2. Every density override is gated below `md:` and restores the desktop value at `md:` (`h-9 md:h-7`, `size-10 md:size-6`). That keeps the dense ledger look byte-for-byte identical on desktop — verified by measuring computed box/font at 1440x900 before and after.

`size="xs"` / `icon-xs` are deliberately left small: they are inline affordances inside table rows and chips, which WCAG 2.5.8 exempts.

When auditing touch targets, measure the *effective* hit area, not the element box. checkbox.tsx uses `after:absolute after:-inset-x-3 after:-inset-y-2` to turn a 16px box into a 40x32 target, and React Aria renders a visually-hidden native `<input>` that is never the real target — measuring raw `<input>` rects reports phantom 13x13 failures.

## Wrapping button triggers need md:h-auto
buttonVariants size sm/default/lg set a fixed height at md: (md:h-6, md:h-7, md:h-8). twMerge treats those as a different utility from h-auto, so a wrapping CollapsibleTrigger still clips at desktop width and the extra lines overflow onto the content below. Override with both h-auto and md:h-auto (and items-start) whenever the trigger label is allowed to wrap.

## Badge is a chip, not an icon pip
Badge is a status chip (h-6, rounded-md, wash destructive). Do not use it as a corner count on an icon button — the chip sits on the glyph. Use a small solid rounded-full span (h-4, bg-destructive, absolute -top-1 -right-1) and overflow-visible on the button.
