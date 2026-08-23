import { LARAVEL_CLOUD_EU_WEST_1_IPV4_CIDRS } from "../shared/laravelCloudEgress.js";
import { EnvironmentSettings } from "./settings.js";

/**
 * Staging data plane: documents/OCR + public RDS for Laravel Cloud staging.
 *
 * Application compute runs on Laravel Cloud. This stack only owns the AWS data
 * plane the app reaches over the public internet.
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

  dnsSubdomain: "staging",

  crossRegionSnapshotCopy: false,
  monthlyBudgetUsd: 75,

  // Credentials live on the `local` stack (decision-3); Cloud uses that key too.
  applicationUserAccess: false,
  databaseEnabled: true,
  databasePubliclyAccessible: true,
  databaseIngressCidrs: LARAVEL_CLOUD_EU_WEST_1_IPV4_CIDRS,
  uploadAllowedOrigins: ["https://*.laravel.cloud"],
};
