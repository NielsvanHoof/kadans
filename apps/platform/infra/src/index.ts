import { loadPlatformConfig } from "./config.js";
import { createOcrAlarms } from "./shared/alarms.js";
import { createAuditTrail } from "./shared/auditTrail.js";
import {
  createApplicationIdentity,
  createExecutionIdentity,
} from "./shared/application.js";
import { createCache } from "./shared/cache.js";
import { createCompute } from "./shared/compute.js";
import { createDatabase } from "./shared/database.js";
import { createPlatformDns } from "./shared/dns.js";
import { createDocumentInfrastructure } from "./shared/documents.js";
import {
  createOcrEncryption,
  createPlatformEncryption,
} from "./shared/encryption.js";
import { createEdge } from "./shared/edge.js";
import { createLoadBalancer } from "./shared/loadBalancer.js";
import { createServiceLogGroups } from "./shared/logs.js";
import { createMail } from "./shared/mail.js";
import { createMalwareProtection } from "./shared/malwareProtection.js";
import { createNetwork } from "./shared/network.js";
import {
  createBudget,
  createDisasterRecovery,
  createOperationsAlerting,
  createPlatformAlarms,
} from "./shared/ops.js";
import {
  createOcrOperatorAccess,
  exportOcrOperatorCredentials,
} from "./shared/operator.js";
import { createApplicationAccessManagedPolicy } from "./shared/policies.js";
import { createContainerRegistry } from "./shared/registry.js";
import { createSchedule } from "./shared/schedule.js";
import { createPlatformSecrets } from "./shared/secrets.js";

/*
 * The Qompose platform stack: one program, one stack per environment.
 *
 * Everything that differs between production, staging, and local lives in
 * src/environments/ as data; the components in src/shared/ read those knobs and
 * never branch on the environment name. See src/environments/settings.ts.
 */

const config = loadPlatformConfig();
const { environment } = config;
const computeEnabled = environment.settings.computeEnabled;

// --- Data plane: documents, OCR, transcription (predates compute) ---
const ocrEncryption = createOcrEncryption(config);
const documents = createDocumentInfrastructure(config, ocrEncryption.encryptionKey);
// One managed policy for Sail IAM user + ECS task role (inline user policies are too small).
const accessPolicy = createApplicationAccessManagedPolicy(
  config,
  documents,
  ocrEncryption.encryptionKey,
);
/*
 * One topic for everything that needs a human. Created before the things that
 * publish to it, and shared by the DLQ alarms, the malware findings rule, the
 * budget and the platform alarms — on-call needs a single place to subscribe.
 */
const alerting = createOperationsAlerting(config, ocrEncryption.encryptionKey);
createOcrAlarms(config, documents, alerting.topic);

/*
 * Client uploads are scanned before staff can open them, and every object read is
 * recorded. Both hang off the documents bucket and need no compute, which is why
 * they sit with the data plane rather than the platform below.
 */
const malwareProtection = createMalwareProtection(
  config,
  documents,
  ocrEncryption.encryptionKey,
  alerting.topic,
);
const auditTrail = createAuditTrail(config, documents);

/*
 * Long-lived Sail credentials belong to exactly one environment (decision-3), and
 * scripts/sync-ocr-credentials.sh reads the `local` stack. A second key elsewhere
 * would be a standing credential with nothing consuming it.
 */
const operator = environment.settings.localOperatorAccess
  ? createOcrOperatorAccess(config, accessPolicy)
  : undefined;
const credentials = operator === undefined
  ? undefined
  : exportOcrOperatorCredentials(operator);

/*
 * Application compute plane. Off for `local`, which exists only so Sail can hit
 * real Textract / Transcribe without standing up VPC/ALB/ECS/DNS.
 */
const platform = computeEnabled
  ? (() => {
    const platformEncryption = createPlatformEncryption(config);
    const network = createNetwork(config);
    const registry = createContainerRegistry(config, platformEncryption.encryptionKey);
    const database = createDatabase(config, network, platformEncryption.encryptionKey);
    const cache = createCache(config, network, platformEncryption.encryptionKey);
    const secrets = createPlatformSecrets(config, platformEncryption.encryptionKey);
    const mail = createMail(config);
    const logGroups = createServiceLogGroups(config, platformEncryption.encryptionKey);
    const loadBalancer = createLoadBalancer(config, network);
    /*
     * CloudFront in front of the ALB. When it is on, the load balancer's security
     * group only accepts CloudFront's origin-facing ranges and its listener only
     * forwards requests carrying the shared origin header — so DNS must point at the
     * distribution, not the ALB, or the hostnames resolve to something that will
     * refuse them.
     */
    const edge = environment.settings.edgeEnabled
      ? createEdge(config, loadBalancer)
      : undefined;
    const dns = createPlatformDns(config, loadBalancer, mail, edge);

    // SES is attached to the task role only, never to the shared policy — see mail.ts.
    const application = createApplicationIdentity(config, accessPolicy, [mail.sendPolicy]);
    const execution = createExecutionIdentity(
      config,
      [secrets.secret.arn, database.masterSecretArn],
      platformEncryption.encryptionKey,
    );

    const compute = createCompute(config, {
      network,
      edge: loadBalancer,
      database,
      cache,
      secrets,
      documents,
      logGroups,
      taskRole: application.taskRole,
      executionRole: execution.executionRole,
      repository: registry.repository,
    });

    createSchedule(
      config,
      compute,
      network,
      application.taskRole,
      execution.executionRole,
    );

    // Spend, the alarms that mean production is not serving, and cheap DR.
    createBudget(config, alerting.topic);
    createPlatformAlarms(config, { loadBalancer, database, cache, compute }, alerting.topic);
    createDisasterRecovery(config, database);

    return {
      platformEncryption,
      network,
      registry,
      database,
      cache,
      secrets,
      mail,
      loadBalancer,
      edge,
      dns,
      application,
      execution,
      compute,
    };
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

// Platform (only when computeEnabled — avoid undefined stack-output warnings on `local`)
export const alertsTopicArn = alerting.topic?.arn;
export const appHostname = config.appHostname;
export const tenantHostnamePattern = config.tenantHostnamePattern;
export const reverbPublicKey = config.reverbPublicKey;
export const sentryBrowserDsn = config.sentryBrowserDsn;

export const appTaskRoleArn = platform?.application.taskRole.arn;
export const appExecutionRoleArn = platform?.execution.executionRole.arn;
export const platformEncryptionKeyArn = platform?.platformEncryption.encryptionKey.arn;
export const vpcId = platform?.network.vpc.id;
/**
 * Network placement for `aws ecs run-task` (migrations).
 *
 * Exported because a one-shot task is not a service: nothing in the stack holds its
 * network configuration, so the caller has to supply the same subnets and security
 * group the services use, or the task lands somewhere that cannot reach the database.
 */
export const publicSubnetIds = platform?.network.publicSubnetIds;
export const taskSecurityGroupId = platform?.network.taskSecurityGroup.id;
export const containerRepositoryUrl = platform?.registry.repository.repositoryUrl;
export const clusterName = platform?.compute.cluster.name;
export const databaseEndpoint = platform?.database.instance.address;
export const databaseMasterSecretArn = platform?.database.masterSecretArn;
export const cacheEndpoint = platform?.cache.replicationGroup.primaryEndpointAddress;
export const applicationSecretArn = platform?.secrets.secret.arn;
export const migrateTaskDefinitionArn = platform?.compute.migrateTaskDefinition.arn;
export const loadBalancerDnsName = platform?.loadBalancer.loadBalancer.dnsName;
export const edgeDomainName = platform?.edge?.distribution.domainName;
export const edgeDistributionId = platform?.edge?.distribution.id;
export const edgeWebAclArn = platform?.edge?.webAcl?.arn;
/**
 * Build inputs for the JavaScript bundle.
 *
 * `VITE_REVERB_*` is compiled in at image build time and cannot be repointed at
 * another host afterwards (.ai/rules/docker.md), so the release pipeline has to read
 * these off the stack it is deploying to. An image built against the wrong pair
 * runs perfectly and silently fails to open a WebSocket.
 */
export const certificateArn = platform?.loadBalancer.certificate.arn;
export const edgeCertificateArn = platform?.edge?.certificate.arn;
/**
 * Records to create by hand when the zone is not in Route53. Empty when
 * `hostedZoneId` is configured, because the stack wrote them itself. Undefined
 * when compute is off (no load balancer / mail to describe).
 */
export const dnsRecordsToCreate = platform?.dns.manualRecords;
