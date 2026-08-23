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
 *
 * Compute (ECS/ALB/edge/cache) was removed in TASK-43.3 — Laravel Cloud owns the
 * application runtime. This stack is the data plane plus public RDS.
 */
export interface EnvironmentSettings {
  /** RDS deletion protection. Off in staging/local so the stack can be torn down. */
  readonly deletionProtection: boolean;
  /** How long superseded document versions survive in the S3 bucket. */
  readonly documentNoncurrentVersionDays: number;
  /** KMS pending-deletion window; the floor AWS accepts is 7 days. */
  readonly kmsDeletionWindowInDays: number;

  readonly databaseInstanceClass: string;
  readonly databaseMultiAz: boolean;
  readonly databaseBackupRetentionDays: number;
  readonly databaseAllocatedStorageGb: number;

  /**
   * DNS label inserted before the apex domain, or null for the apex itself.
   *
   * Used for SES From-domain / hostname helpers. Production is `null`
   * (`qompose.nl`); staging is `"staging"`.
   */
  readonly dnsSubdomain: string | null;

  /** Cross-region RDS snapshot copies for cheap DR (TASK-34.4). */
  readonly crossRegionSnapshotCopy: boolean;
  /** Monthly AWS Budgets threshold in USD (TASK-34.4). */
  readonly monthlyBudgetUsd: number;

  /**
   * Whether this environment mints the long-lived IAM user + access key that
   * Sail and Laravel Cloud share for S3/OCR/Transcribe/SES.
   *
   * Exactly one environment may own it. `scripts/sync-ocr-credentials.sh` reads
   * the `local` stack's outputs, so a second key elsewhere would be an extra
   * standing credential that nothing consumes — the worst kind.
   */
  readonly applicationUserAccess: boolean;

  /**
   * Provision RDS (and the VPC it needs).
   *
   * On for every deployed environment. Public access + ingress CIDRs are how
   * Laravel Cloud reaches Postgres.
   */
  readonly databaseEnabled: boolean;

  /**
   * Give the RDS instance a public address and open 5432 to
   * `databaseIngressCidrs`.
   *
   * Required for Laravel Cloud, which sits outside our VPC.
   */
  readonly databasePubliclyAccessible: boolean;

  /**
   * CIDRs allowed to reach Postgres when `databasePubliclyAccessible` is true.
   * Empty is rejected at create time for public databases.
   */
  readonly databaseIngressCidrs: readonly string[];
}
