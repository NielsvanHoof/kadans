---
paths:
  - 'app/Platform/Http/SharedProps/**'
---

# Shared Props

## Key locale-dependent once props by locale, not by prop name
`translations` ships as `Inertia::once(...)->as("translations.{$locale}")`. The locale must stay in the key.

Keying it by prop name alone silently breaks every language switch: the client sends `X-Inertia-Except-Once-Props: translations`, the server skips resolving the prop, and the client keeps its first dictionary forever. `locale` next to it is a regular prop that keeps updating, so dates and React Aria follow the switch while every `t()` string stays frozen — the split looks like a frontend bug and is not one.

Do not reintroduce the old `->fresh(session()->pull('inertia.refresh.translations'))` workaround. It only covered the authenticated `LocaleController` path; portal guests (no auth endpoint), Accept-Language, and any bare cookie write had no way to set the flag. The locale-scoped key covers all of them with nothing to remember to flash.

Same rule for any once prop whose value depends on request state: put that state in the key.
