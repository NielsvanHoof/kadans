import { LARAVEL_CLOUD_EU_WEST_1_IPV4_CIDRS } from "../shared/laravelCloudEgress.js";
import type { EnvironmentSettings } from "./settings.js";

/**
 * Staging data plane: documents/OCR + public RDS for Laravel Cloud staging.
 *
 * Application compute runs on Laravel Cloud. This stack owns the AWS data
 * plane the staging app reaches — separate bucket, SNS/SQS queues, and IAM
 * user from the `local` stack so Sail and Cloud do not race on completions.
 *
 * `deletionProtection: false` so the stack stays destroyable.
 */
export const stagingSettings: EnvironmentSettings = {
  deletionProtection: false,
  documentNoncurrentVersionDays: 30,
  kmsDeletionWindowInDays: 7,

  databaseInstanceClass: "db.t4g.micro",
  databaseMultiAz: false,
  databaseBackupRetentionDays: 3,
  databaseAllocatedStorageGb: 20,
  databaseApplyImmediately: true,

  dnsSubdomain: "staging",

  crossRegionSnapshotCopy: false,
  monthlyBudgetUsd: 75,

  applicationUserAccess: true,
  databaseEnabled: true,
  databasePubliclyAccessible: true,
  databaseIngressCidrs: LARAVEL_CLOUD_EU_WEST_1_IPV4_CIDRS,
  uploadAllowedOrigins: ["https://*.laravel.cloud"],
};
