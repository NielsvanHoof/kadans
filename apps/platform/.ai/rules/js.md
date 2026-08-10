---
paths:
  - 'resources/js/**'
---

# Js

## Payload types come from PHP; generated dirs are never hand-edited
`types/generated/`, `actions/`, `routes/`, and `features/document-requests/item-types/generated.ts` are codegen output. Add a field to the PHP `*Data` object or enum and regenerate — never edit the TypeScript. CI fails on stale committed output.

Alias generated types in `features/<domain>/types.ts` (`export type Dossier = App.Dossiers.Data.DossierShowData`). Domain payload types must not go in `@/types`.

Never hardcode URL strings — use `@/routes/...` for hrefs and `@/actions/...` or `.form()` for mutations.

Import boundaries (Biome-enforced): `components/` must not import from `features/`; `pages/` must not be imported from anywhere; `hooks/` and `lib/` stay domain-agnostic. Domain hooks live beside their feature or in `features/<domain>/hooks/`, never in top-level `hooks/`.

Forms: Inertia `<Form>` + Wayfinder `.form()` by default. `useForm` only for multipart uploads needing progress.

UI copy uses JSON sentence keys in `lang/en.json` / `lang/nl.json` via `t('Full sentence')`. `lang/{locale}/*.php` is for framework bags only — no new dotted short keys.

Permissions are gated by server-sent boolean props (`can_view`, `can_update`); the client only hides or disables.

Vitest tests live in a `tests/` subfolder mirroring the tested file's path, using the shared mocks in `resources/js/test/mocks.ts`.

After route/Data/permission/questionnaire changes run `npm run types:check`.
