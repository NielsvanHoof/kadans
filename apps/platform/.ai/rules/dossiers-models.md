---
paths:
  - 'app/Dossiers/Models/**'
---

# Dossiers Models

## Scout toSearchableArray must not include tenant_id or integer id
DatabaseEngine LIKEs every key in toSearchableArray(). Do not put tenant_id there — searching "1" would match tenant 12. TenantScope already constrains rows. Same trap as Activity (audit-models.md). Do not put integer `id` there either: Postgres has no bigint ILIKE, so a filename search 500s the palette (the frontend then shows an empty list). UploadedDocument indexes original_filename and searchable_text only.
