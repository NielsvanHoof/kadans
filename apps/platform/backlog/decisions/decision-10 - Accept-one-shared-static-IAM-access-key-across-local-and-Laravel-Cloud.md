---
id: decision-10
title: Accept one shared static IAM access key across local and Laravel Cloud
date: '2026-08-25 21:06'
status: accepted
---
## Context

decision-3 accepted static IAM credentials **for local development**, on an
explicit promise: *"Residual risk is limited to developer machines and should not
appear in production once the ECS task role path is used."* Its reopen trigger was
"when application compute lands in Pulumi (decision-2)".

Both halves of that have now failed. The trigger fired and then the mitigation it
pointed at was deleted: decision-9 moved compute to Laravel Cloud, and there is no
ECS task role any more. `infra/src/shared/application.ts`, the file decision-3
named as the safe path, does not exist.

Laravel Cloud is not an ECS task, an EC2 instance or a Lambda — it is not
something AWS can hand a role to. So the deployed application authenticates the
same way a laptop does: with a long-lived IAM user access key. Today it is
*literally* the same key. `applicationUserAccess` is `true` only in the `local`
stack (`production.ts` and `staging.ts` both set it `false`, and the stack tests
assert neither mints a user), so the one user minted by the `local` stack serves
both Sail and Laravel Cloud — which is why it is tagged
`Purpose: Sail + Laravel Cloud Textract / Transcribe / Bedrock / SES`.

## Decision

Accept one shared long-lived IAM access key across local development and Laravel
Cloud for v1.0, and say so plainly rather than carrying decision-3's claim that
production holds no keys.

What bounds the risk is the policy, not the credential. Permissions live in one
narrow managed policy (`infra/src/shared/policies.ts`): the documents bucket, the
OCR and Transcribe queues, Bedrock, one KMS key, and — attached separately — SES
send. There is no IAM, no EC2, no ability to read another bucket. CI never uses
this key; it authenticates through GitHub OIDC.

Supersedes decision-3.

## Consequences

- **This is the one place the Laravel Cloud move is strictly worse than ECS.** A
  task role hands out temporary credentials that expire on their own. This key
  does not expire, and rotating it is a manual procedure — documented at
  [infra/README.md](../../infra/README.md#rotating-the-application-access-key).
  Rotation is the whole mitigation, so an undocumented rotation is the same as no
  mitigation.
- **The blast radius spans two environments.** A key leaked from a developer
  laptop is production's key. That is worse than either risk alone and is the
  reason this is a new decision rather than an edit to decision-3.
- The key's secret lives in Pulumi state for the `local` stack and in Laravel
  Cloud's secret store for the deployed environments. Two copies, two rotation
  steps — miss the second and the app keeps running on a credential believed
  revoked.
- App code still skips explicit credentials when `AWS_ACCESS_KEY_ID` /
  `AWS_SECRET_ACCESS_KEY` are empty, so nothing here prevents a future move back
  to role-based credentials.
- **Reopen trigger:** when staging and production get their own AWS stacks. Each
  Laravel Cloud environment should then carry a key minted by its matching stack
  (Cloud production → AWS production stack), so a laptop and production stop
  sharing one credential. Closing it entirely needs Laravel Cloud Private Cloud —
  see decision-9's reopen trigger.
