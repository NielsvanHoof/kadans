---
paths:
  - app/Integrations/Enums/OAuthProvider.php
---

# Enums

## Google OAuth needs prompt=consent for refresh tokens
Google authorize must send access_type=offline and prompt=consent (keep select_account too). Without consent Google omits refresh_token after the first grant, so calendar tokens die in about an hour with nothing to refresh. Do not gate consent on ?reconsent=1. Microsoft uses the offline_access scope instead.
