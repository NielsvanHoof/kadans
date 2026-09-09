---
paths:
  - 'app/Platform/Services/Health/**'
---

# Health

## Health check copy is trans()d by Spatie
Spatie Health Result::getNotificationMessage() already calls trans() with the check meta. Pass the English key to ok()/failed() — placeholders must match meta names. Do not wrap in __(); that would look up the Dutch sentence as a key. Pin those keys in config/translations.php because there is no literal __() call site.
