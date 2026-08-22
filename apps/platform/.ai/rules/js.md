---
paths:
  - 'resources/js/**'
  - 'resources/js/**/*.{tsx,ts}'
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

## React Aria Select and Table collection keys
SelectItem is keyed by `id`, not `value` — `value` is ignored and the key falls back to the rendered text, so forms post labels like "Sander" / "Data export" instead of ids. Use `defaultSelectedKey` (not `defaultValue`; that prop type-checks via GlobalDOMAttributes and is inert). Table columns sit directly under TableHeader with no Row wrapper, need `aria-label` on Table, and exactly one column `isRowHeader`; otherwise React Aria throws "Cell count must match column count".

## Select always needs aria-label
React Aria Select ignores FieldLabel+htmlFor because LabelContext is nulled when htmlFor is set. Every Select must pass aria-label or aria-labelledby (same string as the visible FieldLabel is fine). Missing it floods the browser console with useLabel warnings, especially under Strict Mode / HMR.

## useForm for upload forms is about awaiting presigned PUTs, not multipart
Uploads no longer post multipart bodies — the browser PUTs each file straight to S3 with a presigned URL and the form submits opaque tickets (see `useDirectUpload` and TASK-43.1). The standing preference for Inertia `<Form>` + Wayfinder `.form()` still holds everywhere else, but `document-file-upload-form.tsx` and `audio-recording-upload-form.tsx` keep `useForm` for a different reason than the old "multipart uploads needing progress": their submit has to await N uploads and abandon itself if any one fails, and there is no declarative form of "await N side effects, then post".

Progress comes from the hook's XHR, not `form.progress` — `fetch()` still cannot report upload progress in any browser.
