---
paths:
  - 'lang/**'
  - 'app/*/lang/**'
  - 'resources/js/**'
  - 'app/**/Enums/**'
  - 'app/**/Http/Controllers/**'
  - 'app/Audit/Enums/**'
  - 'resources/js/**/*.tsx'
---

# Customer-facing copy and comments

## Voice

UI strings are product copy for accounting/legal firms — not dev notes, scaffold text, or implementation explanations.

- **Outcome-first**: say what happened or what to do next.
- **Plain professional**: short sentences; no engineer-speak.
- **No stack leakage** in UI: avoid vendor names (AWS Textract, TOTP), queue mechanics (`queued`), infra terms (`payload`, `environment`), and security jargon (`privilege escalation`, `malware scanner`).

## Avoid these patterns

| Pattern | Bad | Better |
| --- | --- | --- |
| Meta-navigation | "Use a dedicated page without crowding this overview." | "See how many custom roles exist and which are assigned to members." |
| Scaffold empty states | "Items will appear here as they run." | "No processing jobs yet. Document scans and exports will show here when they run." |
| Backend toasts | "Client invite queued." | "Invitation sent. Copy the portal link now — you won't see it again." |
| Builder internals | "Drag components on the canvas." | "Build the client form by dragging fields here." |

## Translation keys

- Full English sentences as keys in `app/<Domain>/lang/{en,nl}.json` via `t('…')` / `__()`.
- Copy for a domain lives beside that domain. Run `php artisan lang:organize` rather than hand-placing a key — placement is derived from call sites.
- Never create `lang/{locale}.json`: FileLoader merges the base lang path last, so a retained monolith silently wins every collision and the co-located files become inert.
- Textual insertion into the JSON files is no longer legitimate — they are machine-written by `lang:organize`.
- A React string's copy lives in its PHP domain directory (see `config/translations.php` for the JS path → domain map).
- Change a string → update the call site and re-run `lang:organize` so both locales stay aligned.
- `lang/{locale}/*.php` is for framework bags only — no new dotted short keys.
- Remove orphan keys when copy is deleted; do not leave unused entries.

OCR/status helpers that return literals consumed by `t()` must have matching keys in both JSON files.

## Comments

Do **not** add:

- Page-level JSDoc that only restates the route or filename (`Dossiers index — list of…`).
- File headers starting with "Compact …" that describe obvious UI shape.
- Inline comments that restate the next line.

**Do** keep comments for non-obvious traps: React Aria quirks, Mobile Safari zoom, security gates for PII, Octane/state, measured layout fixes.

## Never recreate lang/{locale}.json beside domain files
FileLoader::loadJsonPaths merges array_merge($jsonPaths, $paths) — the base lang/ path is last and wins every collision. A leftover or recreated lang/en.json makes every app/*/lang file inert while tests still pass and Dutch pages still render. Delete the monolith on switchover; lang:organize --check fails if it returns. Framework bags stay in lang/{locale}/*.php only.
