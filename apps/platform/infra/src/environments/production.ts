import { LARAVEL_CLOUD_EU_WEST_1_IPV4_CIDRS } from "../shared/laravelCloudEgress.js";
import type { EnvironmentSettings } from "./settings.js";

/**
 * Production data plane: documents/OCR + public RDS for Laravel Cloud production.
 *
 * Multi-AZ Postgres and longer retention; application compute is on Laravel Cloud.
 */
export const productionSettings: EnvironmentSettings = {
  deletionProtection: true,
  documentNoncurrentVersionDays: 90,
  kmsDeletionWindowInDays: 30,

  databaseInstanceClass: "db.t4g.micro",
  databaseMultiAz: true,
  databaseBackupRetentionDays: 14,
  databaseAllocatedStorageGb: 20,
  // Queue instance modifications for the weekly maintenance window so they do
  // not cause an unscheduled production interruption.
  databaseApplyImmediately: false,

  dnsSubdomain: null,

  crossRegionSnapshotCopy: true,
  monthlyBudgetUsd: 200,

  applicationUserAccess: false,
  databaseEnabled: true,
  databasePubliclyAccessible: true,
  databaseIngressCidrs: LARAVEL_CLOUD_EU_WEST_1_IPV4_CIDRS,
  uploadAllowedOrigins: ["https://*.laravel.cloud"],
};
