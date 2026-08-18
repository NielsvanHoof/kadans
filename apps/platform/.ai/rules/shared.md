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
