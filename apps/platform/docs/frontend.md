# Frontend conventions

How the Inertia React UI is organized and written. For the backend → frontend domain map, see [Architecture](architecture.md).

## Stack

| Piece | Role |
|-------|------|
| Inertia React 19 | SPA pages via `@inertiajs/react` |
| Vite+ | Build, dev, format (Oxfmt), lint (Oxlint) via `vp` |
| Tailwind 4 | Styling |
| Wayfinder | Generated `@/actions` and `@/routes` helpers |
| spatie/laravel-typescript-transformer | PHP Data → `resources/js/types/generated/` |
| shadcn/ui | Primitives in `components/ui` (see `components.json`) |
| Oxlint | Lint and import boundaries (`vite.lint.ts`) |
| Vite+ test | Co-located unit/component tests under `resources/js` (`vp test`; import from `vite-plus/test`) |
| Laravel Echo | Real-time (Reverb) where used |

## Directory map

```
resources/js/
├── pages/              # Thin Inertia entry points — compose features
├── features/<domain>/  # Domain UI, hooks, co-located types.ts
├── components/         # Shared only — never imports from features/
├── layouts/            # One folder per audience (app, auth, portal, …)
├── hooks/              # App-wide hooks only — nothing domain-aware
├── lib/                # Generic helpers only
├── types/              # Cross-cutting types (auth, navigation, pagination)
├── types/generated/    # Generated from PHP — do not edit
├── actions/            # Wayfinder-generated — do not edit
└── routes/             # Wayfinder-generated — do not edit
```

### `components/` role layers

| Group | Contents |
|-------|----------|
| `ui/` | shadcn primitives (vendored, Oxlint-ignored) |
| `primitives/` | Single-purpose building blocks (`heading`, `input-error`, `empty-state`, …) |
| `data/` | `data-table`, `index-list`, `index-query/`, `sortable/` |
| `forms/` | `file-dropzone`, `date-picker` |
| `feedback/` | `confirm-destroy-dialog` |
| `brand/` | Brand panel, `ledger-field`, `audit-mark` |
| `app-shell/` | Nav, sidebar, breadcrumbs |

### `features/` organization

Put domain UI under `features/<domain>/`. Organize by surface where it helps: `list/`, `show/`, `create/`, `edit/`, `manage/`. For questionnaires and document requests, split **staff** vs **portal** renderers (`features/document-requests/staff` vs `features/document-requests/portal`).

Co-locate domain hooks as `use-*.ts` next to the feature that owns them, or under `features/<domain>/hooks/` when a domain has several (for example pipelines). Do not put domain hooks in top-level `hooks/`.

## Import rules

Import boundaries are enforced by Oxlint — see [`vite.lint.ts`](../vite.lint.ts).

- `components/` must not import from `features/`.
- `pages/` must not be imported from elsewhere.
- `hooks/` and `lib/` must stay domain-agnostic.
- Do not put domain payload types in `@/types` — use `features/<domain>/types.ts`.

Portal pages and features have additional restrictions for document-request renderers; follow existing portal patterns under `features/document-requests/portal` and `pages/portal`.

## Pages vs features

**Domain CRUD** — keep pages thin: `setLayoutProps`, `Head`, wire server props into a feature component. Example pattern: `pages/dossiers/show.tsx` composes `features/dossiers/show`.

**Auth, settings, and platform** — pages may own `<Form>` markup inline when the surface is small and not shared. That is acceptable for those audiences; do not extract everything into features by default.

## Wayfinder

Do not hardcode URL path strings.

- Use `@/routes/...` for navigation and `href`s.
- Use `@/actions/...` or named route `.form()` for Inertia form targets and mutations.

Regenerate after route or controller changes:

```bash
vendor/bin/sail artisan wayfinder:generate
```

Wayfinder output is part of `npm run types:check`.

## Forms

Prefer Inertia `<Form>` with Wayfinder `.form()` for ordinary submits.

Use `useForm` only for multipart file uploads that need upload progress (for example document and audio upload forms).

## Types

Payload shapes come from PHP `*Data` objects. Generate TypeScript with:

```bash
vendor/bin/sail artisan typescript:transform
```

Alias generated types in `features/<domain>/types.ts`:

```ts
export type Dossier = App.Dossiers.Data.DossierShowData;
```

Add fields on the PHP Data object, then regenerate. Do not hand-edit `types/generated/`, `actions/`, or `routes/`. CI fails if committed generated output is stale.

Questionnaire item types are defined in PHP (`QuestionnaireItemType`), codegen'd to `features/document-requests/item-types/generated.ts`, and wired in staff and portal registries.

## Permissions in the UI

Gate UI with server-sent boolean props (`can_view`, `can_update`, …) on Inertia pages. The server decides what the user may do; the client only hides or disables controls.

- Use `usePermissions()` for shell and navigation checks (for example sidebar items).
- Use `DossierPermissionsProvider` / `useDossierCapabilities` inside dossier trees where capabilities depend on dossier context.

See [Permissions](permissions.md) for how abilities are defined on the backend.

## Localization

User-facing copy uses **JSON sentence keys** in `lang/en.json` and `lang/nl.json`. Consume with `useTranslation()` → `t('Full sentence')` on the frontend, or `__('Full sentence')` in PHP for toasts and notifications.

Keep `lang/{locale}/*.php` for framework bags only (validation, auth, passwords, pagination). Do not add new dotted short keys for UI copy.

Translations are loaded via `LoadFrontendTranslationsAction` and shared to Inertia.

## Layouts

`resources/js/app.tsx` picks a layout per page via `pageLayout()`:

- Portal and error pages may use `null` (no shell).
- Auth, onboarding, settings, and dossier builder each have dedicated layout folders under `layouts/`.

## Testing

- **Vitest** — tests live in a `tests/` subfolder next to what they cover, not beside the implementation file: `features/<domain>/tests/...`, `hooks/tests/...`, `lib/tests/...`, `components/<group>/tests/...`, mirroring the path of the file under test. Run with `npm test`.
- **Pest Feature tests** — assert HTTP/Inertia contracts server-side. See [Testing](testing.md).

The Pest browser plugin is installed but not used yet. Prefer Feature tests with `assertInertia` over browser automation unless you explicitly adopt Playwright.

## Codegen checklist

When you change routes, Data objects, permissions, or questionnaire types, run the checks that apply:

```bash
npm run types:check   # wayfinder + typescript:transform + permissions + questionnaire
```

Individual commands:

- `vendor/bin/sail artisan wayfinder:generate`
- `vendor/bin/sail artisan typescript:transform`
- `vendor/bin/sail artisan permissions:generate` (if applicable)
- `vendor/bin/sail artisan questionnaire:generate` (if applicable)

## Related

- [Architecture](architecture.md) — domain map and request flow
- [Adding a domain](adding-a-domain.md) — frontend checklist when adding a feature
- [Backend](backend.md) — Spatie Data objects that feed generated types
- [Testing](testing.md) — Pest vs Vitest split
