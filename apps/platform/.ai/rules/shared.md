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
