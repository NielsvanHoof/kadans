---
paths:
  - 'resources/js/lib/**'
---

# Lib

## Echo configure lives in lib/echo, not app entry
configureEcho runs from resources/js/lib/echo.ts, side-effect-imported only by Echo consumers. Do not call configureEcho in app.tsx — that pulls pusher-js into the entry chunk.
