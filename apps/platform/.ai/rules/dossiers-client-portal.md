---
paths:
  - 'app/{Dossiers,ClientPortal}/**'
---

# Dossiers Client Portal

## Dossier row locks go through DossierLocks, in canonical order
One canonical row-lock order, parent before child: dossiers -> document_requests -> client_access_grants. Take dossier and document-request locks only via `App\Dossiers\Support\DossierLocks` (dossier, trashedDossier, documentRequest, documentRequestWithDossier, documentRequestsFor) — never `Dossier::query()->lockForUpdate()` inline. Grant locks stay in ClientPortal actions and always come last; Dossiers is L4 and cannot import ClientAccessGrant.

Why: staff completing a dossier locked dossier-then-item while a client submitting the last answer locked item-then-dossier, so the two deadlocked (SQLSTATE 40P01) and 500'd for whichever Postgres killed. Do NOT "fix" that with DB::transaction($cb, $attempts) — the upload closures dispatch pipelines and delete files, so replaying one is worse than never forming the cycle.

Two of the four original violators did not lock the dossier in their own file at all: they locked it inside the `$afterPersist` callback that UploadDocumentForRequestAction invokes after taking the item lock, so both files read as dossier-first in isolation. That callback now receives the already-locked Dossier — do not re-lock it there.

Enforced by tests/Unit/DossierLockOrderTest.php (source scan: canonical order per file, plus only DossierLocks locks those two tables) and tests/Feature/Dossiers/DossierLockOrderTest.php (DB::listen proves the emitted query order, which is the only check that sees through the callback). SQLite compiles lockForUpdate() to nothing, so a real 40P01 cannot be reproduced in the suite — assert query order, not SQL text.
