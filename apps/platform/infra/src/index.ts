import { loadPlatformConfig } from "./config.js";
import { createOcrAlarms } from "./shared/alarms.js";
import { createAuditTrail } from "./shared/auditTrail.js";
import { createDatabase } from "./shared/database.js";
import { createPlatformDns } from "./shared/dns.js";
import { createDocumentInfrastructure } from "./shared/documents.js";
import {
  createOcrEncryption,
  createPlatformEncryption,
} from "./shared/encryption.js";
import { createMail } from "./shared/mail.js";
import { createMalwareProtection } from "./shared/malwareProtection.js";
import { createNetwork } from "./shared/network.js";
import {
  createBudget,
  createDatabaseAlarms,
  createDisasterRecovery,
  createOperationsAlerting,
} from "./shared/ops.js";
import {
  createOcrOperatorAccess,
  exportOcrOperatorCredentials,
} from "./shared/operator.js";
import { createApplicationAccessManagedPolicy } from "./shared/policies.js";

/*
 * The Qompose platform stack: one program, one stack per environment.
 *
 * Everything that differs between production, staging, and local lives in
 * src/environments/ as data; the components in src/shared/ read those knobs and
 * never branch on the environment name. See src/environments/settings.ts.
 *
 * Compute (ECS/ALB/edge/cache) was removed in TASK-43.3 — Laravel Cloud owns the
 * application runtime. This program is the data plane plus public RDS.
 */

const config = loadPlatformConfig();
const { environment } = config;
const { settings } = environment;

// --- Data plane: documents, OCR, transcription ---
const ocrEncryption = createOcrEncryption(config);
const documents = createDocumentInfrastructure(config, ocrEncryption.encryptionKey);
// One managed policy for the Sail / Laravel Cloud IAM user (inline user policies are too small).
const accessPolicy = createApplicationAccessManagedPolicy(
  config,
  documents,
  ocrEncryption.encryptionKey,
);
/*
 * One topic for everything that needs a human. Created before the things that
 * publish to it, and shared by the DLQ alarms, the malware findings rule, the
 * budget and the database alarms — on-call needs a single place to subscribe.
 */
const alerting = createOperationsAlerting(config, ocrEncryption.encryptionKey);
createOcrAlarms(config, documents, alerting.topic);

/*
 * Client uploads are scanned before staff can open them, and every object read is
 * recorded. Both hang off the documents bucket and need no compute.
 */
const malwareProtection = createMalwareProtection(
  config,
  documents,
  ocrEncryption.encryptionKey,
  alerting.topic,
);
const auditTrail = createAuditTrail(config, documents);

/*
 * SES is always on: Laravel Cloud sends mail with the application IAM user.
 * DKIM DNS is written when a hosted zone is configured, otherwise exported.
 */
const mail = createMail(config);
const dns = createPlatformDns(config, mail);

/*
 * Long-lived credentials belong to exactly one environment (decision-3), and
 * scripts/sync-ocr-credentials.sh reads the `local` stack. A second key elsewhere
 * would be a standing credential with nothing consuming it.
 */
const operator = settings.applicationUserAccess
  ? createOcrOperatorAccess(config, accessPolicy, mail.sendPolicy)
  : undefined;
const credentials = operator === undefined
  ? undefined
  : exportOcrOperatorCredentials(operator);

/*
 * VPC + public RDS when the database is on. Every deployed environment turns
 * this on so Laravel Cloud can reach Postgres over the internet.
 */
const dataStore = settings.databaseEnabled
  ? (() => {
    const platformEncryption = createPlatformEncryption(config);
    const network = createNetwork(config);
    const database = createDatabase(
      config,
      network,
      platformEncryption.encryptionKey,
    );

    createBudget(config, alerting.topic);
    createDatabaseAlarms(config, database, alerting.topic);
    createDisasterRecovery(config, database);

    return { platformEncryption, network, database };
  })()
  : undefined;

// --- Outputs ---
export const environmentName = environment.name;

// Documents / OCR / Transcribe
export const documentsBucketName = documents.bucket.bucket;
export const textractSnsTopicArn = documents.textractTopic.arn;
export const textractPublishRoleArn = documents.textractPublishRole.arn;
export const textractResultsQueueUrl = documents.resultsQueue.url;
export const textractResultsDeadLetterQueueUrl = documents.resultsDeadLetterQueue.url;
export const transcribeResultsQueueUrl = documents.transcribeResultsQueue.url;
export const transcribeResultsDeadLetterQueueUrl = documents.transcribeResultsDeadLetterQueue.url;
export const transcribeDeliveryDeadLetterQueueUrl = documents.transcribeDeliveryDeadLetterQueue.url;
export const transcribeCompletionRuleName = documents.transcribeCompletionRule.name;
export const ocrEncryptionKeyArn = ocrEncryption.encryptionKey.arn;
export const malwareProtectionPlanId = malwareProtection.plan.id;
export const malwareFindingsRuleName = malwareProtection.findingsRule.name;
export const auditTrailName = auditTrail.trail.name;
export const auditLogBucketName = auditTrail.logBucket.bucket;

// Identities
export const appAccessPolicyArn = accessPolicy.arn;
export const ocrLocalUserName = credentials?.ocrLocalUserName;
export const ocrAccessKeyId = credentials?.ocrAccessKeyId;
export const ocrSecretAccessKey = credentials?.ocrSecretAccessKey;

// Mail / DNS
export const mailDomain = mail.domainIdentity.domain;
export const dnsRecordsToCreate = dns.manualRecords;

// Ops / data-store
export const alertsTopicArn = alerting.topic?.arn;
export const appHostname = config.appHostname;
export const tenantHostnamePattern = config.tenantHostnamePattern;

export const platformEncryptionKeyArn = dataStore?.platformEncryption.encryptionKey.arn;
export const vpcId = dataStore?.network.vpc.id;
export const databaseEndpoint = dataStore?.database.instance.address;
export const databasePort = dataStore?.database.instance.port;
export const databaseName = dataStore?.database.databaseName;
export const databaseUsername = dataStore?.database.username;
// Public RDS password lives in Pulumi config `databasePassword` (secret); not exported.
