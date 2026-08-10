---
paths:
  - app/Platform/Models/DataSubjectRequest.php
---

# Platform Models

## DSR client relation includes trashed
DataSubjectRequest::client() removes SoftDeletingScope on the relation query via getQuery()->withoutGlobalScope() (not BelongsTo::withTrashed() — phpstan-strict-rules flags that as staticMethod.dynamicCall). Soft-archived clients must still appear on the compliance list. Hard-purged clients stay null; fromModel uses instanceof Client for the display name.
