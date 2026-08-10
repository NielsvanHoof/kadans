---
paths:
  - app/Platform/Models/DataSubjectRequest.php
---

# Platform Models

## DSR client relation includes trashed
DataSubjectRequest::client() uses withTrashed(). Soft-archived clients must still appear on the compliance list; without it fromModel gets a null client and TypeErrors on clientName.
