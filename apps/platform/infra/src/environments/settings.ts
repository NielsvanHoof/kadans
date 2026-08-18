/**
 * Every knob that differs between deployed environments, in one place.
 *
 * This is the `docker/base` split applied to infrastructure: `src/shared/`
 * builds the components, and each file under `src/environments/` is *data only*
 * — no resources, no branching. A new environment is then a settings object, not
 * a fork of the program, and a reviewer can see the whole production/staging
 * delta by diffing two files.
 *
 * The rule that keeps this honest: if a component needs to behave differently per
 * environment, it reads a field from here. It must never branch on
 * `environment.name`, because that hides the difference inside the component
 * where nobody comparing environments will find it.
 */
export interface EnvironmentSettings {
  /** RDS / ALB deletion protection. Off in staging so the stack can be torn down. */
  readonly deletionProtection: boolean;
  /** How long superseded document versions survive in the S3 bucket. */
  readonly documentNoncurrentVersionDays: number;
  /** KMS pending-deletion window; the floor AWS accepts is 7 days. */
  readonly kmsDeletionWindowInDays: number;

  readonly databaseInstanceClass: string;
  readonly databaseMultiAz: boolean;
  readonly databaseBackupRetentionDays: number;
  readonly databaseAllocatedStorageGb: number;

  readonly cacheNodeType: string;
  /**
   * Valkey nodes in the replication group.
   *
   * Load-bearing beyond cost: ElastiCache rejects `automaticFailoverEnabled` and
   * `multiAzEnabled` on a single-node group, so `cache.ts` derives both from this
   * rather than exposing them as separate knobs that could be set inconsistently.
   */
  readonly cacheNodeCount: number;

  readonly webDesiredCount: number;
  readonly workerDesiredCount: number;
  readonly reverbDesiredCount: number;
  readonly consumerDesiredCount: number;
  readonly taskCpu: string;
  readonly taskMemory: string;

  readonly logRetentionDays: number;

  /**
   * DNS label inserted before the apex domain, or null for the apex itself.
   *
   * Production is `null` (`app.qompose.nl`, tenants at `*.qompose.nl`); staging is
   * `"staging"` (`app.staging.qompose.nl`, `*.staging.qompose.nl`). Keeping the two
   * wildcards on separate levels is what stops a staging certificate and a
   * production certificate from claiming the same names.
   */
  readonly dnsSubdomain: string | null;

  /**
   * CloudFront in front of the ALB (TASK-34.3).
   *
   * Also changes who may reach the load balancer: with the edge on, the ALB
   * accepts traffic only from CloudFront's origin-facing prefix list and only
   * with the shared origin secret, so the origin cannot be addressed directly.
   */
  readonly edgeEnabled: boolean;
  /**
   * AWS managed WAF rules on the distribution.
   *
   * Separate from `edgeEnabled` because the WAF is the part most likely to need
   * turning off in a hurry — a managed rule that starts refusing a legitimate
   * upload is an outage, and `pulumi config set wafEnabled false` overrides this
   * without a code change.
   */
  readonly wafEnabled: boolean;
  /** Cross-region RDS snapshot copies for cheap DR (TASK-34.4). */
  readonly crossRegionSnapshotCopy: boolean;
  /** Monthly AWS Budgets threshold in USD (TASK-34.4). */
  readonly monthlyBudgetUsd: number;

  /**
   * Whether this environment mints the long-lived IAM user + access key that Sail
   * uses locally (see `shared/operator.ts` and decision-3).
   *
   * Exactly one environment may own it. `scripts/sync-ocr-credentials.sh` reads
   * the production stack's outputs, so a second key in staging would be an extra
   * standing credential that nothing consumes — the worst kind.
   */
  readonly localOperatorAccess: boolean;
}
