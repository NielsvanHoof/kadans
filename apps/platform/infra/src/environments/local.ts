import { LARAVEL_CLOUD_EU_WEST_1_IPV4_CIDRS } from "../shared/laravelCloudEgress.js";
import { EnvironmentSettings } from "./settings.js";

/**
 * Local: documents / OCR / transcription + a public RDS for Laravel Cloud.
 *
 * Floci covers everyday local S3 with mock OCR drivers. This stack is the opt-in
 * path when you need real Textract and Transcribe completion (SNS→SQS /
 * EventBridge→SQS), and the data plane Laravel Cloud staging points at.
 *
 * `applicationUserAccess` lives here (and only here): sync-ocr-credentials.sh
 * reads this stack.
 */
export const localSettings: EnvironmentSettings = {
  deletionProtection: false,
  documentNoncurrentVersionDays: 30,
  kmsDeletionWindowInDays: 7,

  databaseInstanceClass: "db.t4g.micro",
  databaseMultiAz: false,
  databaseBackupRetentionDays: 1,
  databaseAllocatedStorageGb: 20,

  dnsSubdomain: "local",

  crossRegionSnapshotCopy: false,
  monthlyBudgetUsd: 25,

  applicationUserAccess: true,
  databaseEnabled: true,
  databasePubliclyAccessible: true,
  databaseIngressCidrs: LARAVEL_CLOUD_EU_WEST_1_IPV4_CIDRS,
  // Sail local runs with `APP_URL=http://localhost` in `.env.example`, so
  // real AWS presigned-browser PUTs need an explicit non-HTTPS origin match.
  uploadAllowedOrigins: [
    "https://*.laravel.cloud",
    "http://localhost",
    "http://127.0.0.1",
  ],
};
