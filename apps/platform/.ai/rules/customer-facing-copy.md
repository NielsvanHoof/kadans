---
paths:
  - 'lang/**'
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

- Full English sentences as keys in `lang/en.json` / `lang/nl.json` via `t('…')` / `__()`.
- Change a string → update the call site, `en.json`, and `nl.json` together.
- `lang/{locale}/*.php` is for framework bags only — no new dotted short keys.
- Remove orphan keys when copy is deleted; do not leave unused entries.

OCR/status helpers that return literals consumed by `t()` must have matching keys in both JSON files.

## Comments

Do **not** add:

- Page-level JSDoc that only restates the route or filename (`Dossiers index — list of…`).
- File headers starting with "Compact …" that describe obvious UI shape.
- Inline comments that restate the next line.

**Do** keep comments for non-obvious traps: React Aria quirks, Mobile Safari zoom, security gates for PII, Octane/state, measured layout fixes.
