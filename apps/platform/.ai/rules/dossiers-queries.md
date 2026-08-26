---
paths:
  - 'app/Dossiers/Queries/**'
---

# Dossiers Queries

## Dossier show loads latestPipeline only
FetchDossierShowQuery eager-loads uploadedDocuments.latestPipeline.steps and audioRecordings.latestPipeline.steps (MorphOne latestOfMany). Do not eager-load pipelines.steps: Eloquent limit() on a nested HasMany is global, not per parent, and the DTOs only keep sortByDesc('id')->first(). Do not cap document requests or uploads — visibility/completion walks the full set. Access grants: all valid() plus the latest 20 otherwise.
