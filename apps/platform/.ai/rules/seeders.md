---
paths:
  - 'database/seeders/**'
---

# Seeders

## Production seed is create-only; LocalDatabaseSeeder is local-only
Default DatabaseSeeder (roles + SystemQuestionnaireTemplateSeeder) must stay safe to re-run on Cloud: create missing system templates only, never updateOrCreate+delete items. Never put users/tenants/demo data there. Local/demo seeders go in LocalDatabaseSeeder and must abort unless APP_ENV is local or testing. Owners/firms are provisioned with tenancy:create-owner (fully argued on Cloud — no TTY), not seeders.
