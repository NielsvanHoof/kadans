import { createOcrAlarms } from "./alarms.js";
import { createApplicationIdentity } from "./application.js";
import { loadPlatformConfig } from "./config.js";
import { createDocumentInfrastructure } from "./documents.js";
import { createOcrEncryption } from "./encryption.js";
import {
  createOcrOperatorAccess,
  exportOcrOperatorCredentials,
} from "./operator.js";
import { createApplicationAccessManagedPolicy } from "./policies.js";

const config = loadPlatformConfig();
const encryption = createOcrEncryption(config);
const documents = createDocumentInfrastructure(config, encryption.encryptionKey);
// One managed policy for Sail IAM user + ECS task role (inline user policies are too small).
const accessPolicy = createApplicationAccessManagedPolicy(
  config,
  documents,
  encryption.encryptionKey,
);
const operator = createOcrOperatorAccess(config, accessPolicy);
const application = createApplicationIdentity(config, accessPolicy);
createOcrAlarms(config, documents, encryption.encryptionKey);

const credentials = exportOcrOperatorCredentials(operator);

export const environment = config.environment.name;
export const documentsBucketName = documents.bucket.bucket;
export const textractSnsTopicArn = documents.textractTopic.arn;
export const textractPublishRoleArn = documents.textractPublishRole.arn;
export const textractResultsQueueUrl = documents.resultsQueue.url;
export const textractResultsDeadLetterQueueUrl = documents.resultsDeadLetterQueue.url;
export const transcribeResultsQueueUrl = documents.transcribeResultsQueue.url;
export const transcribeResultsDeadLetterQueueUrl = documents.transcribeResultsDeadLetterQueue.url;
export const transcribeDeliveryDeadLetterQueueUrl = documents.transcribeDeliveryDeadLetterQueue.url;
export const transcribeCompletionRuleName = documents.transcribeCompletionRule.name;
export const ocrEncryptionKeyArn = encryption.encryptionKey.arn;
export const ocrLocalUserName = credentials.ocrLocalUserName;
export const ocrAccessKeyId = credentials.ocrAccessKeyId;
export const ocrSecretAccessKey = credentials.ocrSecretAccessKey;
/** Attach to the ECS task definition as `taskRoleArn` when compute lands. */
export const appTaskRoleArn = application.taskRole.arn;
export const appAccessPolicyArn = accessPolicy.arn;
