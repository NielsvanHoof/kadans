---
paths:
  - 'resources/js/lib/**'
  - resources/js/lib/interpolate-translation.ts
---

# Lib

## Echo configure lives in lib/echo, not app entry
configureEcho runs from resources/js/lib/echo.ts, side-effect-imported only by Echo consumers. Do not call configureEcho in app.tsx — that pulls pusher-js into the entry chunk.

## Interpolate :placeholders as whole tokens
t() must not use replaceAll(':' + name). :to is a prefix of :total, so naive replacement turns pagination copy into "1–15 van 15tal weergegeven". Use interpolateTranslation(), which matches :identifier tokens. Keep mockUseTranslation() on the same helper so component tests catch this.
