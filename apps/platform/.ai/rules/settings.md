---
paths:
  - 'app/Tenancy/Support/Settings/**'
---

# Settings

## Firm settings: bounds declared once, stricter-only, clamped on read
Every per-firm setting is declared once in FirmSettingBounds. Never type a bound into a Form Request — ask the bound for rules(). The same object's resolve() is the read-time clamp, so validation and enforcement cannot drift; FirmSettingBoundsTest walks a value grid asserting "passes validation" iff "resolve() returns it unchanged".

A firm may only be STRICTER than the platform config, never weaker. That is what keeps ProductionConfigurationValidator honest: it refuses to boot production with 2FA off or a portal idle window >= session.lifetime, and those guarantees hold for every firm transitively only while overrides cannot loosen. Retention is the exception — shorter is stricter for privacy, longer for a statutory obligation — so it uses an explicit [min, max] band (ClampDirection::Banded) from config('retention.tenant_bounds').

A null column means "inherit the platform value", never zero. Empty form fields must persist as null.

The clamp is applied at the read side too, not only on save: a row edited straight in SQL must not be able to weaken a setting past what the form would have allowed.
