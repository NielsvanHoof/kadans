---
paths:
  - 'infra/src/shared/**'
---

# Shared

## Platform component traps: no NAT, ALB idle timeout, ARM, SES scope
**No NAT, no private subnets.** Two public subnets; tasks get `assignPublicIp: true` because without it they cannot pull their own image from ECR. Confinement is security-group based: task ingress on 8080 references the ALB's *security group*, never a CIDR — tasks have public IPs, so a CIDR rule is the difference between "the load balancer" and "the internet". Database and cache accept only from the task group. The trade is real (a misconfigured group is an exposure, egress is unfiltered); NAT is ~€35/mo per AZ and VPC endpoints ~€7/mo each, which is why it was not bought.

**ALB `idleTimeout: 3600`.** The 60s default silently kills Reverb — the socket opens and drops every minute. A WebSocket is idle by definition between messages.

**ARM64 is a contract with the build.** Task definitions are ARM64, so `docker buildx bake` must produce `linux/arm64` (`.github/workflows/platform.yml`). An amd64 image deploys green and fails with an exec-format error at first task start. `stack.production.test.ts` asserts it from this side.

**Task env must satisfy `ProductionConfigurationValidator`** (`.ai/rules/config.md`) or containers fail boot after a clean deploy: HTTPS `APP_URL`, Redis cache/queue/session, `REDIS_SCHEME=tls`, S3 filesystem, textract/transcribe drivers, SES mailer, non-empty Sentry DSN, `TRUSTED_PROXIES` = the VPC CIDR. `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` must stay absent so the SDK falls through to the task role.

**SES is attached to the task role only**, not to the shared managed policy in `policies.ts`. That policy's parity rule exists so production is never *less* capable than local — not a reason to put company-domain mail credentials on a laptop that sends to Mailpit.

**No password reaches Pulumi state.** RDS uses `manageMasterUserPassword`; ECS reads `${masterSecretArn}:password::`. App secrets are one JSON secret with per-key `valueFrom`.

Derive `automaticFailoverEnabled`/`multiAzEnabled` from `cacheNodeCount` — ElastiCache rejects both on a single node, so separate knobs could only be set to an invalid pair.

## CloudFront edge traps: us-east-1 certificate, cache scope, WAF exclusions
**The CloudFront certificate must be issued in us-east-1**, whatever region the distribution serves. `edge.ts` carries its own `aws.Provider` for that, so there are two certificates for the same names. Reusing the regional one fails at apply time with an error naming the certificate, not the region — the most common reason a CloudFront stack does not come up. The CLOUDFRONT-scoped `wafv2.WebAcl` has the same constraint.

**Cache only `/build/*`.** Every application response is per-user; a shared cache entry on the default behaviour hands one tenant's data to another. Default and `/app/*` (Reverb WebSocket) use `Managed-CachingDisabled` + `Managed-AllViewer`. `/build/*` is safe under `Managed-CachingOptimized` only because Vite emits content-hashed filenames, so no deploy-time invalidation is needed.

**`AWSManagedRulesCommonRuleSet` breaks this app unmodified.** `SizeRestrictions_BODY` rejects bodies over 8 KB (uploads go to 20 MB) and `CrossSiteScripting_BODY` trips on client correspondence pasted into notes. Both are overridden to `count`, not removed, so they still report. Symptom is an unexplained 403 from CloudFront. `wafEnabled` is a config override on the environment setting so it can be flipped without a code change.

**Origin lockdown needs both halves.** The `com.amazonaws.global.cloudfront.origin-facing` prefix list on the ALB security group narrows the origin to CloudFront — which covers *everyone's* distributions — so CloudFront also adds `ORIGIN_VERIFY_HEADER` and the listener forwards only to rules requiring it, with a fixed 403 default. ALB rules match conditions positively, so "reject without the header" must be written as "forward only with it".

`ORIGIN_VERIFY_HEADER` lives in `config.ts`, not `edge.ts`: `loadBalancer.ts` needs the value and `edge.ts` needs the `LoadBalancer` type, which is a require cycle that is inert only while TypeScript elides the type-only import.

**Test harness:** a single secret anywhere inside a property wraps the *whole* property in `{[specialSig]: secretSig, value: …}`, so one secret in a listener rule's conditions turns the conditions array into an object. `testing/harness.ts` unwraps these; without it assertions read `undefined` and blame themselves.

## Alerting: one topic, few alarms, and the DR region exception
**One SNS topic** (`ops.ts` → `alertsTopicArn`) for every alarm, the malware findings rule, and the budget. A topic per subsystem is how an alarm ends up with no subscribers. Its topic policy must name `cloudwatch`, `events`, `budgets` and `rds` — only CloudWatch may publish by default, and the others match, fire, and silently fail to deliver.

**Keep the alarm set small.** Every alarm that fires without needing action trains someone to ignore the next one. Add one only when the answer to "what do I do about this" is never "nothing".

`treatMissingData` is the field that decides whether an alarm is useful, and the right value is not uniform: `notBreaching` for the ALB 5xx alarm (no data = no traffic), `breaching` for `UnHealthyHostCount` (no data = no target is reporting at all).

**RDS failover has no metric.** It is an `rds.EventSubscription` on the `failover` category — the whole point of Multi-AZ is that the application survives it, so it is invisible in CPU and connection graphs.

**eu-west-1 is the region for everything, with one deliberate exception**: the cross-region automated-backup replica, which defaults to eu-central-1 (EU data residency). A backup in the same region as its database is a second copy of one failure domain, not disaster recovery. The replication resource is created *in the destination region*, so it needs its own provider, and the KMS key is left unset because keys are regional and the source key cannot encrypt the replica. CloudFront's certificate and WebACL are also forced into us-east-1 by AWS, but hold no data. Set `crossRegionSnapshotCopy: false` to keep a stack single-region (staging does).

## Valkey AUTH token is REDIS_PASSWORD
ElastiCache sets authToken from cacheAuthToken. Laravel reads REDIS_PASSWORD. Put that token in the app JSON secret as REDIS_PASSWORD and inject it via ECS valueFrom. Leaving it only on the replication group deploys green; /ready then fails AUTH.

## Scheduler RunTask IAM uses arnWithoutRevision
ecs:RunTask IAM must be task-definition/family:* or family:revision. Pulumi TaskDefinition.arn already includes the revision, so `${arn}:*` becomes family:1:* and matches nothing. Use arnWithoutRevision + :*.

## The WAF body-size override is no longer about upload size
`AWSManagedRulesCommonRuleSet`'s `SizeRestrictions_BODY` override in `edge.ts` was originally justified by 20 MB uploads passing through the app. Since TASK-43.1 the browser PUTs files straight to S3 and no request body reaching the origin is large, so that half of the justification is gone. `CrossSiteScripting_BODY` still trips on client correspondence pasted into notes, which is reason enough to keep both overrides at `count`.

Revisit rather than delete: the override is cheap and a managed rule that starts refusing a legitimate request is an outage.
