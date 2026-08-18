---
paths:
  - 'app/WorkspaceNotifications/Queries/**'
---

# Queries

## Unmapped bell types are omitted, not guessed
mapNotification must not default unknown data.type to questionnaire copy. Match the three DatabaseType constants and skip (return null, omit the bell item) on anything else. Do not add a presenter registry until there is a fifth type. PHPStan cannot exhaustiveness-check a match on class constants.
