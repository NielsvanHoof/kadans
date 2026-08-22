# Qompose platform infrastructure

One Pulumi program, one stack per environment. It provisions the whole deployed
platform: the documents/OCR plane that predates compute, and — since TASK-34.2 —
the network, data stores, edge and ECS services the Laravel application runs on.

```
src/
  shared/           components; the docker/base equivalent
  environments/     per-environment knobs, data only
  testing/          Pulumi mock harness for the stack tests
  index.ts          wires shared components with this stack's settings
```

The GitHub OIDC provider and the CI deploy roles live in a **separate** project —
see [`bootstrap/`](bootstrap/README.md). That separation is a security boundary, not
tidiness: the thing that grants CI its access must not itself be deployable by CI.

## Layout, and why it is shaped like `docker/`

`docker/` keeps one shared base image and a thin directory per environment. This
mirrors it. `src/shared/` builds every component; `src/environments/production.ts`,
`staging.ts`, and `local.ts` are pure data.

The rule that keeps it honest: **a component reads a knob from
`EnvironmentSettings`; it never branches on `environment.name`.** A name check
hides the difference inside the component, where nobody comparing two environments
will find it. The whole production/staging/local delta should be a settings-file diff.

| Knob | production | staging | local |
|---|---|---|---|
| `deletionProtection` | `true` | `false` | `false` |
| `databaseMultiAz` | `true` | `false` | n/a |
| `databaseBackupRetentionDays` | 14 | 3 | n/a |
| `cacheNodeCount` | 2 | 1 | n/a |
| `webDesiredCount` / `workerDesiredCount` | 2 / 2 | 1 / 1 | 0 / 0 |
| `logRetentionDays` | 90 | 14 | 14 |
| `dnsSubdomain` | `null` | `staging` | `local` |
| `edgeEnabled` (CloudFront + origin lockdown) | `true` | `false` | `false` |
| `wafEnabled` (overridable via config) | `true` | `false` | `false` |
| `crossRegionSnapshotCopy` (RDS backups to eu-central-1) | `true` | `false` | `false` |
| `monthlyBudgetUsd` | 200 | 75 | 25 |
| `computeEnabled` | `true` | `true` | `false` |
| `localOperatorAccess` | `false` | `false` | `true` |

`local` is documents/OCR/transcription only (no VPC/ALB/ECS). `localOperatorAccess`
is the correctness constraint: exactly one environment mints the long-lived IAM
user + access key Sail uses for real Textract/Transcribe demos (decision-3), and
`scripts/sync-ocr-credentials.sh` reads the `local` stack. Floci remains the
everyday local S3 path with mock OCR drivers.

## What the stack builds

```mermaid
flowchart TB
  Client[Browser] --> ALB[ALB · idle 3600s]
  ALB -->|default| Web[ECS web · Octane]
  ALB -->|/app/*| Reverb[ECS reverb]
  Web --> RDS[(RDS Postgres · Multi-AZ)]
  Web --> Valkey[(Valkey · TLS)]
  Web --> S3[Documents S3]
  Worker[ECS worker] --> Valkey
  Consumers[ECS textract/transcribe consumers] --> SQS[Textract + Transcribe SQS]
  EB[EventBridge · rate 1 minute] -->|RunTask| Sched[schedule:run]
  Web --> SES[SES]
  ECR[ECR · immutable tags] -.image.-> Web
```

**Network.** Two public subnets, no NAT gateway, no private subnets. This is the
biggest cost decision here and the one most likely to be "corrected" later, so:
the textbook layout needs a NAT per AZ (~€35/month each) or five VPC endpoints
(~€7/month each) purely so tasks can reach ECR, Secrets Manager and SES — more than
the database and cache combined, in a stack whose point is to be lean. Confinement
moves to security groups instead. Tasks carry public IPs, but the only thing that
may open a connection to port 8080 is the **load balancer's security group** — the
group, not a CIDR, so it stays true as addresses change. What is given up: a
misconfigured group is an exposure rather than an inconvenience, and egress is
unfiltered. If this ever holds something that must not be able to phone out, buy the
NAT.

**Compute.** One image, five long-running services (`web`, `worker`, `reverb`,
`textract-consumer`, `transcribe-consumer`) plus two task definitions with no
service (`migrate`, `schedule`). They differ only in the role passed as the
container command, which is what `docker/production/entrypoint.sh` dispatches on.
Migrations stay one-shot and are never run on web start — several web tasks boot
concurrently.

**ARM64 is a contract with the build.** `docker buildx bake` must produce
`linux/arm64` (`.github/workflows/platform.yml`). An amd64 image deploys perfectly
and then fails with an exec-format error at first task start, so
`stack.production.test.ts` asserts the architecture from this side.

**The ALB idle timeout is 3600, not 60.** The default silently kills Reverb: the
client sees an open WebSocket and every connection drops after a minute. A WebSocket
is idle by definition between messages.

**Edge (production only).** CloudFront fronts the ALB for both hostnames. See
[The edge](#the-edge) below — the certificate region and the WAF exclusions are
the two things that will bite.

**Malware scanning and audit.** GuardDuty Malware Protection scans every object
landing in the documents bucket and tags it with the verdict; the application reads
that tag at download and preview time and refuses anything not explicitly clean
(`docs/security.md`). Two details are easy to get wrong and are asserted by tests:
tagging is **DISABLED by default** in the GuardDuty API, and the scanner needs a
`kms:Decrypt` grant on the documents CMK — without it every object is tagged
`ACCESS_DENIED`, the gate correctly refuses all of them, and nothing in the
resulting outage points at the key policy. CloudTrail data events record who read
each object, into a separate log bucket with validation digests.

**Secrets.** `APP_KEY`, the Reverb credentials and the Sentry DSN live in one JSON
Secrets Manager secret; ECS pulls individual keys (`arn:APP_KEY::`). The database
password is deliberately *not* in there — RDS manages and rotates its own secret, and
a copy would go stale at the first rotation. Nothing puts a password in Pulumi state.

## The edge

CloudFront sits in front of the ALB in production (`edgeEnabled`). Staging runs
without it: the saving is real and staging does not need edge caching, but it does
mean staging's load balancer is its own public surface, which the stack tests
assert rather than leave implied.

**The certificate must be issued in us-east-1.** CloudFront reads its certificate
from that region and only that region, whatever region the distribution serves.
So there are two certificates for the same two names — a regional one for the ALB
and a us-east-1 one for the edge, created through a second provider in `edge.ts`.
Reusing the regional certificate fails at apply time with an error that names the
certificate rather than the region, and is the most common reason a CloudFront
stack does not come up.

**Only the hashed bundle is cached.** `/build/*` gets `Managed-CachingOptimized`;
everything else, including `/app/*` for Reverb's WebSocket, gets
`Managed-CachingDisabled` with `Managed-AllViewer`. That is not a missed
optimisation: an Inertia response carries the signed-in user's workspace, and a
shared cache entry would hand one firm's dossier list to another. Caching
`/build/*` is safe precisely because Vite emits content-hashed filenames — a
changed asset is a different path, so no deploy-time invalidation is needed.

**The origin is not addressable directly.** Two controls, neither sufficient
alone. The ALB security group accepts only AWS's `com.amazonaws.global.cloudfront
.origin-facing` prefix list, which narrows it to CloudFront — but that covers
*everyone's* distributions. So CloudFront also adds a shared secret header, and
the listener forwards only to rules that require it, with a fixed `403` as the
default action. The ALB grammar matches conditions positively, so "reject requests
without the header" has to be written as "forward only requests with it".

**WAF: two managed rules are counted, not blocked.** Enabling
`AWSManagedRulesCommonRuleSet` unmodified breaks this application, and the failure
looks like an unexplained 403 from CloudFront:

| Rule | Why it is overridden |
|---|---|
| `SizeRestrictions_BODY` | rejects bodies over 8 KB; document uploads go to 20 MB and audio recordings to 25 MB |
| `CrossSiteScripting_BODY` | trips on client correspondence pasted into notes and answers |

Both are set to `count` rather than removed, so they still record what they would
have blocked and the decision stays visible in CloudWatch instead of becoming a
silent hole. `wafEnabled` is a config override on top of the environment setting,
because this is the knob you reach for when a managed rule starts refusing a
legitimate upload: `pulumi config set wafEnabled false` beats a code change.

### DNS cutover

The `app` and wildcard alias records point at **CloudFront** when the edge is on
and at the ALB when it is not, and the stack never writes both. Going from one to
the other is a `pulumi up`, but the ordering matters:

1. Deploy with `edgeEnabled` **false** first if the hostnames are already live.
   The ALB is public, the records point at it, everything serves.
2. Set `edgeEnabled` true and deploy. In one apply the distribution is created,
   the ALB security group narrows to CloudFront, the listener starts requiring the
   origin header, and the alias records move.
3. There is a propagation gap: DNS still resolves to the ALB for up to the record
   TTL, and the ALB now refuses those requests with `403 Direct origin access is
   not permitted`. Lower the record TTL a day ahead, or accept a short window.
4. `pulumi stack output edgeDomainName` gives the distribution's own name, which
   works immediately and is the way to verify the edge before DNS catches up.

Rolling back is the same sequence in reverse, and the same gap applies.

## Hostnames

Production serves `app.qompose.nl` with `*.qompose.nl` on the same certificate;
staging serves `app.staging.qompose.nl` and `*.staging.qompose.nl`. Separate levels,
so the two certificates can never claim the same names.

**The marketing site is safe by construction, not by careful ordering.** A DNS
wildcard only answers a name that has **no explicit record of its own**. `www` has
one; so does the apex, and so will `mail`, `api` or any other reserved host the
moment someone creates it. Publishing `*.qompose.nl` cannot capture them. This stack
simply never writes those names, and there is no carve-out list to maintain.

**Tenant hostnames are provisioned but not yet routed.** Tenancy resolves from the
path today (`/workspaces/{tenant:slug}`), and `config/production.php` restricts
`allowed_hosts` to the exact `APP_URL` host — so a `{slug}.qompose.nl` request
reaches the ALB and is refused by the application until **TASK-34.5** lands. Having
the certificate ready means that task is an application change, not a 45-minute
certificate-validation wait.

`hostedZoneId` is optional. Without it the stack still builds the certificate and the
load balancer and exports `dnsRecordsToCreate` for manual entry; it skips
`acm.CertificateValidation`, which would otherwise block every `pulumi up` waiting
for a record nobody is going to write.

## Deploy

Prerequisites, once per environment:

1. A deploy role from [`bootstrap/`](bootstrap/README.md). It defaults to production
   only — add staging with `pulumi config set --path environments[1] staging` there
   before deploying a staging stack.
2. A GitHub Environment named `production` / `staging` carrying `AWS_DEPLOY_ROLE_ARN`.

```bash
cd apps/platform/infra
npm ci
pulumi login
pulumi stack select production   # or: pulumi stack init staging

# Secrets are pasted, not generated: a value only Pulumi state holds is a value you
# lose with the stack — and losing APP_KEY means every encrypted column and signed
# URL becomes unreadable.
# Set these values in your shell or paste them directly without committing them.
pulumi config set --secret appKey "$APP_KEY"
pulumi config set --secret reverbAppId qompose
pulumi config set --secret reverbAppSecret "$REVERB_APP_SECRET"
pulumi config set --secret sentryDsn "$SENTRY_DSN"
pulumi config set --secret cacheAuthToken "$CACHE_AUTH_TOKEN"
# Only needed when edgeEnabled is true; the ALB refuses anything without it.
pulumi config set --secret originVerifySecret "$ORIGIN_VERIFY_SECRET"

# Plain, not a secret: this one is compiled into the JavaScript bundle and served
# to every visitor. Storing it encrypted would mean decrypting a value in order to
# produce a public asset. `reverbAppSecret` above is the one that authenticates.
pulumi config set reverbPublicKey "$REVERB_PUBLIC_KEY"

# Also plain, and also inlined into the bundle: the browser Sentry DSN. This is a
# DIFFERENT DSN from the secret below — that one is the Laravel project, this one
# is the JavaScript project. Required, not optional: config/services.php reads it
# server-side too, and the app refuses to boot with it empty.
pulumi config set sentryBrowserDsn "$SENTRY_BROWSER_DSN"

pulumi config set hostedZoneId "$HOSTED_ZONE_ID"    # optional, see Hostnames
pulumi config set alarmEmailAddress "$ALARM_EMAIL_ADDRESS"   # required for any alerting
pulumi config set alarmSmsNumber "$ALARM_SMS_NUMBER"         # optional, page-worthy only

npm run preview
npm run up
```

`imageTag` is not set by hand — the release workflow sets it to the commit SHA it
just pushed. Rolling back is `pulumi config set imageTag <older-sha>` and a deploy,
which is a true statement only because the ECR repository is tag-immutable.

`npm run preview` / `npm run up` compile TypeScript to `bin/` first (Pulumi runs the
compiled JS, which avoids ts-node `.js` import issues). Both scripts `rm -rf bin`
before compiling — without it, a renamed or deleted source file leaves stale
JavaScript behind that keeps running and keeps passing.

## Releasing

Three workflows, and the split is deliberate:

| Workflow | Does | AWS credentials |
|---|---|---|
| `platform.yml` | CI: tests, builds an OCI tarball, Trivy-scans it, uploads an artifact | none |
| `deploy.yml` | Infrastructure only — a knob change, a new alarm, a resize | deploy role |
| `release.yml` | Build -> push to ECR -> migrate -> roll the services | deploy role |

`release.yml` runs `pulumi up` three times, which looks redundant and is not:

1. **Ensure infrastructure exists.** The ECR repository lives in this stack, so it
   has to exist before anything can push to it. On a first release this also creates
   services pointing at a tag that is not in the registry yet — harmless and
   self-healing, because Pulumi's ECS Service does not wait for steady state, so the
   deploy succeeds while the tasks retry their pull. Every later release is a no-op
   diff here.
2. **Stage the migration image** — `--target` on the migrate task definition alone.
   This is the ordering that bites: a plain `pulumi up` would update every task
   definition and roll the services in one step, putting new code in front of an
   un-migrated schema. Targeting the migrate task first means migrations land before
   any new-code task takes a request.
3. **Roll the services**, now that the schema is ahead of them.

Between 2 and 3, `aws ecs run-task` runs the migration and the workflow checks the
container's exit code — a stopped task is not a successful one, and without that
check a failed migration is invisible and the rollout proceeds anyway.

The image build reads `VITE_REVERB_HOST`, `REVERB_PUBLIC_KEY` and
`VITE_SENTRY_DSN` from **stack outputs**, not from repository variables. Those
values are compiled into the JavaScript bundle and cannot be changed afterwards,
so an image built against the wrong ones runs perfectly and silently fails — no
WebSocket, or no browser error reporting. Reading them off the stack being
released to is what makes that mismatch impossible rather than merely unlikely.

`tests/Feature/Platform/ContainerImageLayoutTest.php` scans `resources/js` for
every `import.meta.env.VITE_*` and fails if one is not a build argument, because a
missing one is invisible until it is expensive.

## Local `.env` (`local` stack only)

Sail defaults to Floci + mock OCR drivers. For real Textract/Transcribe against AWS,
bring up the documents-only `local` stack, then sync credentials on the **host**
(not via Sail — Pulumi is not in the container):

```bash
cd apps/platform/infra && pulumi up -s local
cd apps/platform && composer env:aws     # or: cd infra && ./scripts/sync-ocr-credentials.sh
composer env:floci   # back to Sail's local AWS; env:aws re-applies the cached profile
```

That writes gitignored `env/profiles/aws.env` and upserts `FILESYSTEM_DISK=s3`,
`OCR_DRIVER=textract`, `TRANSCRIPTION_DRIVER=transcribe`, the IAM keys, the bucket
and the queue URLs into `.env`, clearing the Floci endpoints.

The `local` stack creates that IAM user and access key **for local development only**.
IAM roles hand temporary credentials to something AWS already recognises — an ECS
task, an EC2 instance, a Lambda. A PHP process on your laptop is none of those. The
key is acceptable *because* the policy is narrow: one bucket, the OCR queues, one KMS
key. It is never how production runs — the ECS task role carries the identical
managed policy and the container gets credentials from the ECS agent, with no keys
anywhere.

## What is monitored, and what to do about it

Everything that needs a human publishes to **one** SNS topic
(`pulumi stack output alertsTopicArn`) — deliberately one rather than one per
subsystem, because on-call needs a single place to subscribe and a topic per
concern is how an alarm ends up with no subscribers. Email always; SMS only if
`alarmSmsNumber` is set, and only worth setting for someone who will act on it.

Confirm the email subscription after the first deploy or nothing is delivered.

The set is deliberately small. Every alarm that fires without needing action
trains someone to ignore the next one, so this is not a dashboard: it is the set
where the answer to "what do I do about this" is never "nothing".

| Alarm | Means | First move |
|---|---|---|
| `alb-5xx-alarm` | The load balancer could not get a response from a healthy target — the app is down or unreachable, not merely erroring | `aws ecs describe-services` for `web`; check the deployment circuit breaker did not roll back onto a broken image |
| `web-unhealthy-alarm` / `reverb-unhealthy-alarm` | Tasks are failing `/ready` | Read the task's log group; `/ready` hits the database and Redis, so this is often really one of those |
| `database-events` (failover) | Multi-AZ failover happened and succeeded | Nothing urgent — the app survived it. Find out *why* AWS failed it over |
| `database-events` (low storage) / `database-storage-alarm` | Under 2 GiB free | Raise `databaseAllocatedStorageGb` and deploy. Postgres stops accepting writes when it fills |
| `worker-stopped-alarm` | No queue worker tasks running | Jobs are piling up silently in Redis; nothing errors. Check the worker service and its log group |
| `textract-dlq-alarm` / `transcribe-dlq-alarm` | Provider results failed processing five times | Inspect the DLQ, fix, then redrive. Documents are stuck in processing until you do |
| `transcribe-delivery-dlq-alarm` | EventBridge could not deliver to the results queue at all | Usually the queue policy or the KMS grant — a permissions problem, not a load one |
| `malware-findings` | GuardDuty found malware in an upload | The object is already inert: nothing will serve it, because the download gate refuses anything not tagged clean. Preserve it, tell the firm |
| Budget at 80% actual | The money is already spent | Check for a runaway scheduler or a queue retry loop |
| Budget at 100% forecast | This month's run rate has changed | Fires early enough to act — usually a service that scaled and never came back down |

**Acknowledging** is by fixing: alarms carry `okActions`, so the same topic gets a
recovery notification and no state is kept anywhere else. Nothing needs muting.
**Escalating** means adding a subscriber to that one topic.

### Disaster recovery

RDS automated backups are retained for 14 days in production and replicated
continuously to **eu-central-1**. That is the one deliberate exception to
eu-west-1 holding everything, and the reason is not incidental: an automated
backup lives in the same region as the instance it came from, so a regional
failure takes the database and its recovery point together. A copy in the same
region is a second copy of one failure domain, not disaster recovery.

eu-central-1 keeps the data inside the EU. `disasterRecoveryRegion` moves it;
`crossRegionSnapshotCopy: false` in the environment settings turns it off and
makes the stack single-region, which is what staging does. It costs storage and
nothing else — no standby instance, no Aurora.

Recovering means restoring the replicated backup into a new instance in the
destination region and repointing `DB_HOST`. This has **not been rehearsed**;
treat the recovery time as unmeasured until it has been.

## Tests

```bash
npm test              # builds, then node --test "bin/**/*.test.js"
```

`stack.production.test.ts` and `stack.staging.test.ts` run the whole program under
Pulumi's mock runtime and assert what it declared. They are **separate files on
purpose**: `pulumi.runtime.setMocks` is process-global and `index.js` is
module-cached, so two stacks in one process would silently share the first one's
resources. `node --test` forks per file, which is the only isolation available.

They exist to catch the failures that a successful `pulumi up` does not: ARM vs amd64,
the 60-second idle timeout, an 8080 ingress written as a CIDR, static AWS credentials
leaking into a task definition, and the `ProductionConfigurationValidator` keys that
would let the stack deploy green and then refuse every request.

## Outputs

| Output | Purpose |
|--------|---------|
| `appHostname` / `tenantHostnamePattern` | Hostnames this stack serves |
| `loadBalancerDnsName` / `certificateArn` | ALB target and its regional certificate |
| `edgeDomainName` / `edgeDistributionId` | CloudFront distribution; the domain works before DNS moves |
| `edgeCertificateArn` / `edgeWebAclArn` | us-east-1 certificate and the WAF ACL |
| `dnsRecordsToCreate` | Records to add by hand when `hostedZoneId` is unset |
| `containerRepositoryUrl` | ECR repository to push the image to |
| `reverbPublicKey` / `sentryBrowserDsn` | Baked into the JS bundle at image build time |
| `clusterName` / `migrateTaskDefinitionArn` | `aws ecs run-task` for migrations |
| `publicSubnetIds` / `taskSecurityGroupId` | Network placement for `run-task` |
| `databaseEndpoint` / `databaseMasterSecretArn` | Postgres host and RDS-managed secret |
| `cacheEndpoint` | Valkey primary endpoint |
| `applicationSecretArn` | Secrets Manager secret ECS resolves |
| `vpcId` / `platformEncryptionKeyArn` | Network and data-at-rest key |
| `alertsTopicArn` | The one topic every alarm publishes to |
| `documentsBucketName` | S3 bucket for uploads + Transcribe JSON output |
| `malwareProtectionPlanId` / `malwareFindingsRuleName` | GuardDuty scanning and its findings rule |
| `auditTrailName` / `auditLogBucketName` | CloudTrail data events for the documents bucket |
| `textractSnsTopicArn` / `textractPublishRoleArn` | Textract notification topic and its role |
| `textractResultsQueueUrl` (+ `…DeadLetterQueueUrl`) | Textract consumer queue |
| `transcribeResultsQueueUrl` (+ `…DeadLetterQueueUrl`) | Transcribe consumer queue |
| `transcribeDeliveryDeadLetterQueueUrl` | EventBridge FailedInvocations |
| `appTaskRoleArn` / `appExecutionRoleArn` / `appAccessPolicyArn` | ECS identities |
| `ocrLocalUserName` / `ocrAccessKeyId` / `ocrSecretAccessKey` | Local Sail credentials (`local` stack only) |
| `ocrEncryptionKeyArn` | KMS key for the documents bucket and queues |

## Important

`pulumi up` **destroys** any resource the program no longer declares. Read the
preview diff, especially after a refactor: a moved file that changes a logical
resource name reads to Pulumi as delete-then-create, and for the database that is not
a rename.

This platform is a fresh instance — nothing is migrated into it from an earlier
deployment, so the first `pulumi up` starts from an empty account rather than
converging on existing resources.
