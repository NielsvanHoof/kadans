---
id: TASK-2
title: Rotate the leaked production AWS key and APP_KEY
status: To Do
assignee: []
created_date: '2026-08-06 09:49'
updated_date: '2026-08-06 09:55'
labels: []
dependencies: []
references:
  - .env.prod.example
  - .env.example
documentation:
  - docs/security.md
priority: high
type: bug
ordinal: 7000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The `.env.prod.example` template in the working tree contains real production secrets: a base64 `APP_KEY`, a live `AKIA…` AWS access key ID and its secret, and the production documents bucket name. The local `.env` on the dev machine uses the same access key with `FILESYSTEM_DISK=s3` pointed at the production bucket.

The file is not git-tracked and has never been committed (verified with `git log --all` on the path), so this is not a public leak. It is still a long-lived IAM credential for the bucket holding every firm client's payslips, bank statements and ID documents, readable by anything with filesystem access to the checkout: a malicious composer/npm postinstall script, an editor extension, a CI cache artifact, a stolen laptop, or any AI agent pointed at the directory. The committed `APP_KEY` additionally decrypts any session, cookie or `ShouldBeEncrypted` queue payload produced under it. The `*.example` suffix invites someone committing it.

Rotation must happen in the AWS console and cannot be done by an agent.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 The AWS access key beginning AKIA3WVG is deactivated and deleted in IAM, and a replacement is issued
- [ ] #2 A new APP_KEY is generated for production and the old one is no longer valid anywhere
- [ ] #3 .env.prod.example contains only placeholder values - no real key material, no real bucket name
- [ ] #4 Local .env points at MinIO for object storage, matching .env.example, and no longer targets the production bucket
- [ ] #5 A grep of the repository working tree for AKIA and for base64: returns no real credentials
<!-- AC:END -->

## Comments

<!-- COMMENTS:BEGIN -->
author: Niels
created: 2026-08-06 09:55
---
No longer relevant, .env has been removed
---
<!-- COMMENTS:END -->
