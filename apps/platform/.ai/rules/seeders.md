---
paths:
  - 'database/seeders/**'
---

# Seeders

## Production seed is create-only; LocalDatabaseSeeder is local-only
Default DatabaseSeeder (roles + SystemQuestionnaireTemplateSeeder) must stay safe to re-run on Cloud: create missing system templates only, never updateOrCreate+delete items. Never put users/tenants/demo data there. Local/demo seeders go in LocalDatabaseSeeder and must abort unless APP_ENV is local or testing. Owners/firms are provisioned with tenancy:create-owner (fully argued on Cloud — no TTY), not seeders.

## Fake embeddings when seeding Searchable uploads
UploadedDocument implements toSearchableEmbedding(), so Eloquent create() calls Bedrock Titan. DemoDataSeeder (and any local seeder that inserts completed uploads) must Embeddings::fake() first — local seed has no reason to hit AWS, and a missing/empty provider response throws EmbeddingsCountMismatchException. Also write searchable_text on completed OCR rows so Cmd+K works on demo data. Do not WithoutModelEvents or withoutSyncingToSearch: the activity log still needs named actors.
