---
id: TASK-16
title: Rotate the leaked production AWS key and APP_KEY
status: Done
assignee: []
created_date: '2026-08-06 09:58'
updated_date: '2026-08-11 10:03'
labels: []
milestone: m-0
dependencies: []
references:
  - .env.prod.example
  - .env.example
documentation:
  - docs/security.md
priority: high
type: bug
ordinal: 82000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The `.env.prod.example` template in the working tree contains real production secrets: a base64 `APP_KEY`, a live AKIA AWS access key ID and its secret, and the production documents bucket name. The local `.env` on the dev machine uses the same access key with `FILESYSTEM_DISK=s3` pointed at the production bucket.

The file is not git-tracked and has never been committed (verified with `git log --all` on the path), so this is not a public leak. It is still a long-lived IAM credential for the bucket holding every firm client payslips, bank statements and ID documents, readable by anything with filesystem access to the checkout: a malicious composer or npm postinstall script, an editor extension, a CI cache artifact, a stolen laptop, or any AI agent pointed at the directory. The committed `APP_KEY` additionally decrypts any session, cookie or `ShouldBeEncrypted` queue payload produced under it. The example suffix invites someone committing it.

Rotation happens in the AWS console and cannot be done by an agent.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 The leaked AWS access key is deactivated and deleted in IAM, and a replacement is issued
- [ ] #2 A new APP_KEY is generated for production and the old one is no longer valid anywhere
- [ ] #3 .env.prod.example contains only placeholder values - no real key material, no real bucket name
- [ ] #4 Local .env points at MinIO for object storage, matching .env.example, and no longer targets the production bucket
- [ ] #5 A grep of the repository working tree for AKIA and for base64: returns no real credentials
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
v1.0 review, 2026-08-08 — verified the working-tree half of this task is already done, without touching the AWS side.

Evidence for ACs 3-5: `.env.prod.example` no longer exists anywhere in the checkout. The local `.env` points at Floci, not the production bucket — `AWS_ACCESS_KEY_ID` starts `sail`, `AWS_BUCKET` starts `local`, and `AWS_ENDPOINT`/`AWS_URL` are local HTTP endpoints. A repository-wide grep for `AKIA` (excluding vendor, node_modules, .git) returns exactly two hits, both benign: a Pulumi unit-test fixture (`infra/src/stack.test.ts:48`, the literal `AKIATESTACCESSKEY1`) and a grep pattern in `infra/scripts/sync-ocr-credentials.sh:70`. `.gitignore` covers `/.env`, `/.env.*`, and `/env/profiles/aws.env`.

AC 4 references MinIO, which TASK-18 replaced with Floci; the intent is satisfied by the Floci endpoints above.

What remains is ACs 1 and 2 only, and neither can be done or verified from the checkout: deactivating and deleting the exposed IAM access key with a replacement issued, and generating a new production APP_KEY so the old one no longer decrypts any session, cookie, or encrypted queue payload. Both are AWS console / operator actions.
<!-- SECTION:NOTES:END -->
