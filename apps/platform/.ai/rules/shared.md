---
paths:
  - 'infra/src/shared/**'
---

# Shared

The compute plane (ECS, ALB, CloudFront, WAF, ElastiCache, ECR, Secrets Manager) was
deleted in TASK-43.3 — see decision-9. Laravel Cloud runs the application; this
directory is the data plane plus the VPC that exists only to host RDS. Rules about
ALB idle timeouts, ARM64 image contracts, CloudFront certificates and task-role
credentials were removed with the resources they described.

## The application authenticates with a static key — keys must be PRESENT
Laravel Cloud is not something AWS can hand a role to, so `AWS_ACCESS_KEY_ID` and
`AWS_SECRET_ACCESS_KEY` **must be set** in the deployed environment. This inverts the
old ECS rule that they stay absent so the SDK falls through to a task role; there is
no task role. Today the deployed app uses the *same* key the `local` stack mints —
`applicationUserAccess` is `true` only there, and `production.ts`/`staging.ts` set it
`false` (stack tests assert neither mints a user). Recorded as decision-10; rotation
runbook in `infra/README.md`.

SES is attached to the IAM user as a *separate* managed policy (`ocr-local-mail`), not
folded into `policies.ts`. The parity rule for that shared policy exists so the
deployed app is never *less* capable than local — not a reason to put company-domain
mail credentials on a laptop that sends to Mailpit.

## Resource logical names are load-bearing — a rename destroys the database
Pulumi keys state by logical name, so renaming `vpc`, `igw`, `public-routes`,
`public-0`, `public-1`, `database-sg`, or the `ocr-local-*` resources reads as
delete-then-create. For the database that means destroying it; for the IAM user it
means rotating the key out from under every `.env` that holds it. Keep them
byte-identical.

## No NAT, no private subnets — the allowlist is the confinement
Two public subnets, no NAT. The database is publicly addressable because Laravel Cloud
is not in our VPC and there is nothing to peer with. Confinement is the security
group: `databaseIngress()` emits exactly one rule, 5432 from
`settings.databaseIngressCidrs`, and throws when `databasePubliclyAccessible` is false
or the allowlist is empty — so the allowlist cannot be silently emptied into an open
database. Two AZs is a floor, not a preference: an RDS subnet group spanning two is a
prerequisite for Multi-AZ.

## The egress allowlist is a live dependency on someone else's IP range
`laravelCloudEgress.ts` is a committed copy of `GET https://cloud.laravel.com/api/ip?region=eu-west-1`.
If Laravel Cloud rotates its published CIDRs and this constant does not follow,
production loses its database. `laravelCloudEgress.test.ts` diffs the two and skips
when the API is unreachable so local runs stay usable — it is only a guard if
something runs it on a schedule.

## Public RDS uses a Pulumi config password
`manageMasterUserPassword` keeps passwords out of Pulumi state, but a publicly
addressable database needs a password we can put into Laravel Cloud's env. Set the
Pulumi config secret `databasePassword` alongside `databasePubliclyAccessible: true`
and a non-empty `databaseIngressCidrs`. Do not export it as a stack output — read it
from config when filling Cloud env vars.

## Alerting: one topic, few alarms, and the DR region exception
**One SNS topic** (`ops.ts` → `alertsTopicArn`) for every alarm, the malware findings
rule, and the budget. A topic per subsystem is how an alarm ends up with no
subscribers. Its topic policy must name `cloudwatch`, `events`, `budgets` and `rds` —
only CloudWatch may publish by default, and the others match, fire, and silently fail
to deliver.

**Keep the alarm set small.** Every alarm that fires without needing action trains
someone to ignore the next one. Add one only when the answer to "what do I do about
this" is never "nothing".

`treatMissingData` decides whether an alarm is useful and the right value is not
uniform — for a storage alarm no data means the instance is gone, which is breaching,
not fine.

**RDS failover has no metric.** It is an `rds.EventSubscription` on the `failover`
category — the whole point of Multi-AZ is that the application survives it, so it is
invisible in CPU and connection graphs.

**eu-west-1 for everything, with one deliberate exception**: the cross-region
automated-backup replica, which defaults to eu-central-1 (EU data residency). A backup
in the same region as its database is a second copy of one failure domain, not
disaster recovery. The replication resource is created *in the destination region*, so
it needs its own provider, and the KMS key is left unset because keys are regional and
the source key cannot encrypt the replica. Set `crossRegionSnapshotCopy: false` to
keep a stack single-region (staging does).

## Test harness: one secret wraps the whole property
A single secret anywhere inside a property wraps the *entire* property in
`{[specialSig]: secretSig, value: …}`, so one secret nested in a structure turns its
containing array into an object. `testing/harness.ts` unwraps these; without it
assertions read `undefined` and blame themselves.
