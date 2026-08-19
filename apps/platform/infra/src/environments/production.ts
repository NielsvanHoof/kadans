import { EnvironmentSettings } from "./settings.js";

/**
 * Production: Multi-AZ where failover matters, long retention, protected from
 * deletion. The sizes are deliberately small — the lean stack is a t4g.micro
 * database and 0.25 vCPU tasks — because headroom here is a scaling decision,
 * not a correctness one, and idle capacity is the easiest money to waste.
 */
export const productionSettings: EnvironmentSettings = {
  deletionProtection: true,
  documentNoncurrentVersionDays: 90,
  kmsDeletionWindowInDays: 30,

  databaseInstanceClass: "db.t4g.micro",
  databaseMultiAz: true,
  databaseBackupRetentionDays: 14,
  databaseAllocatedStorageGb: 20,

  cacheNodeType: "cache.t4g.micro",
  cacheNodeCount: 2,

  webDesiredCount: 2,
  workerDesiredCount: 2,
  reverbDesiredCount: 1,
  consumerDesiredCount: 1,
  taskCpu: "256",
  taskMemory: "512",

  logRetentionDays: 90,
  dnsSubdomain: null,

  edgeEnabled: true,
  wafEnabled: true,
  crossRegionSnapshotCopy: true,
  monthlyBudgetUsd: 200,

  localOperatorAccess: true,
};
