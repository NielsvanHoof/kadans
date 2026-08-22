import { EnvironmentSettings } from "./settings.js";

/**
 * Local: documents / OCR / transcription only, for Sail demos against real AWS.
 *
 * Floci covers everyday local S3 with mock OCR drivers. This stack is the opt-in
 * path when you need real Textract and Transcribe completion (SNS→SQS /
 * EventBridge→SQS). It deliberately has no VPC, ALB, or ECS — those belong to
 * staging/production once DNS and the full platform are ready.
 *
 * `localOperatorAccess` lives here (and only here): sync-ocr-credentials.sh
 * reads this stack. Compute knobs below are unused while `computeEnabled` is
 * false; they exist so the settings shape stays uniform across environments.
 */
export const localSettings: EnvironmentSettings = {
  deletionProtection: false,
  documentNoncurrentVersionDays: 30,
  kmsDeletionWindowInDays: 7,

  databaseInstanceClass: "db.t4g.micro",
  databaseMultiAz: false,
  databaseBackupRetentionDays: 1,
  databaseAllocatedStorageGb: 20,

  cacheNodeType: "cache.t4g.micro",
  cacheNodeCount: 1,

  webDesiredCount: 0,
  workerDesiredCount: 0,
  reverbDesiredCount: 0,
  consumerDesiredCount: 0,
  taskCpu: "256",
  taskMemory: "512",

  logRetentionDays: 14,
  // Own DNS label so a future compute turn-on cannot collide with staging/prod certs.
  dnsSubdomain: "local",

  edgeEnabled: false,
  wafEnabled: false,
  crossRegionSnapshotCopy: false,
  monthlyBudgetUsd: 25,

  localOperatorAccess: true,
  computeEnabled: false,
};
