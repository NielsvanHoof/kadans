import { EnvironmentSettings } from "./settings.js";

/**
 * Staging: the same components, cheaper knobs.
 *
 * Single-AZ and single-node on purpose. Staging exists to rehearse the
 * *application* against real AWS services, not to rehearse a Multi-AZ failover —
 * and pretending otherwise doubles the bill for a drill nobody runs. What it does
 * keep identical is the parts that change application behaviour: TLS on the cache,
 * KMS everywhere, SES as the mailer, the same container image and entrypoint roles.
 *
 * `deletionProtection: false` is the deliberate difference in kind: staging must be
 * destroyable, or it becomes a second production nobody dares touch.
 */
export const stagingSettings: EnvironmentSettings = {
  deletionProtection: false,
  documentNoncurrentVersionDays: 30,
  kmsDeletionWindowInDays: 7,

  databaseInstanceClass: "db.t4g.micro",
  databaseMultiAz: false,
  databaseBackupRetentionDays: 3,
  databaseAllocatedStorageGb: 20,

  cacheNodeType: "cache.t4g.micro",
  cacheNodeCount: 1,

  webDesiredCount: 1,
  workerDesiredCount: 1,
  reverbDesiredCount: 1,
  consumerDesiredCount: 1,
  taskCpu: "256",
  taskMemory: "512",

  logRetentionDays: 14,
  dnsSubdomain: "staging",

  edgeEnabled: false,
  wafEnabled: false,
  crossRegionSnapshotCopy: false,
  monthlyBudgetUsd: 75,

  localOperatorAccess: false,
  computeEnabled: true,
};
