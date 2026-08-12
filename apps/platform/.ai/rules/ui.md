---
paths:
  - 'resources/js/components/ui/**'
---

# Ui

## Never run `shadcn add --overwrite` — these components diverge on purpose
Most files here differ from the shadcn registry deliberately: i18n on the dialog close button, `motion-reduce:` on animations, `MinusIcon` for the indeterminate checkbox, `CardTitle` as a real `<h2>` with a `level` prop, brand colours on the `link` variants, `validationBehavior="aria"` on Select (so Laravel stays the validation source of truth instead of a browser bubble), and the coral selected-row wash in Table.

`shadcn add <component> --overwrite` silently reverts all of it. To pull an upstream change: `npx shadcn@latest add <component> --dry-run`, then `--diff <file>`, and merge by hand.

Note the CLI's `-y` does NOT imply overwrite, but it also does not suppress the per-file overwrite prompt — use `yes n | npx shadcn@latest add <components>` to add new components while declining every existing-file overwrite.

A ~2-line diff is usually just the `"use client"` directive the CLI strips (`rsc: false`) and is not local work.
