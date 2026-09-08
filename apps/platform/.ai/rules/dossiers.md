---
paths:
  - 'app/Dossiers/**'
---

# Dossiers

## Dossiers is layer-then-concern
Inside Dossiers, nest by concern under the layer: `app/Dossiers/Actions/{Lifecycle,Reminders,Builder,DocumentRequests,Uploads,UploadedDocuments,AudioRecordings,Exports}/Create…` (same for Data, Queries, Http/Controllers, Http/Requests, Transitions, …). Do not put feature folders at the Dossiers root. Models, Policies, and Enums stay at `app/Dossiers/{Models,Policies,Enums}`. Cross-feature plumbing stays in `app/Dossiers/Support` (`StoredFileResponse`, `DossierLocks`); feature Support goes in `Support/{Concern}/`. The aggregate concern is Lifecycle, not a nested Dossiers folder. Features may import siblings; Pest layering stays at `App\Dossiers`.
