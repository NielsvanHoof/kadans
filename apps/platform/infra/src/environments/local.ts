import type { EnvironmentSettings } from "./settings.js";

/**
 * Local: documents / OCR / transcription only — no RDS.
 *
 * Sail uses Docker Postgres from `compose.yaml`. This stack is the opt-in path
 * when you need real Textract and Transcribe completion (SNS→SQS /
 * EventBridge→SQS). Laravel Cloud staging uses the separate `staging` stack.
 *
 * `applicationUserAccess` mints the Sail IAM user; sync with
 * `PULUMI_STACK=local ./scripts/sync-ocr-credentials.sh` or `composer env:aws`.
 */
export const localSettings: EnvironmentSettings = {
  deletionProtection: false,
  documentNoncurrentVersionDays: 30,
  kmsDeletionWindowInDays: 7,

  // Unused while `databaseEnabled` is false; kept to satisfy EnvironmentSettings.
  databaseInstanceClass: "db.t4g.micro",
  databaseMultiAz: false,
  databaseBackupRetentionDays: 1,
  databaseAllocatedStorageGb: 20,
  databaseApplyImmediately: true,

  dnsSubdomain: "local",

  crossRegionSnapshotCopy: false,
  monthlyBudgetUsd: 25,

  applicationUserAccess: true,
  databaseEnabled: false,
  databasePubliclyAccessible: false,
  databaseIngressCidrs: [],
  // Sail local runs with `APP_URL=http://localhost` in `.env.example`, so
  // real AWS presigned-browser PUTs need an explicit non-HTTPS origin match.
  uploadAllowedOrigins: [
    "https://*.laravel.cloud",
    "http://localhost",
    "http://127.0.0.1",
  ],
};
