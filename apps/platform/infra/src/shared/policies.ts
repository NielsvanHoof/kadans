import * as aws from "@pulumi/aws";
import type * as pulumi from "@pulumi/pulumi";
import type { PlatformConfig } from "../config.js";
import type { DocumentInfrastructure } from "./documents.js";

/**
 * Everything the Qompose application needs from AWS at runtime.
 *
 * Defined once and attached to the long-lived IAM user Sail and Laravel Cloud
 * share (`operator.ts`). There is no ECS task role anymore — application compute
 * runs on Laravel Cloud (TASK-43.3).
 *
 * Managed (not inline) because IAM user inline policies cap at 2048 bytes; this
 * document exceeds that once Textract + Transcribe + Bedrock statements land.
 *
 * `"*"` resources are intentional, not oversights:
 *
 *  - **Textract** does not support resource-level permissions for
 *    StartDocumentAnalysis / GetDocumentAnalysis.
 *  - **Transcribe** StartTranscriptionJob / GetTranscriptionJob are the same.
 *  - **Bedrock** model ARNs are configurable; see `defaultBedrockModelArns`.
 */
export function applicationAccessPolicy(
  config: PlatformConfig,
  documents: DocumentInfrastructure,
  encryptionKey: aws.kms.Key,
): pulumi.Output<string> {
  const bucketObjects = documents.bucket.arn.apply((arn) => `${arn}/*`);

  return aws.iam.getPolicyDocumentOutput({
    statements: [{
      sid: "ListDocumentsBucket",
      actions: ["s3:ListBucket"],
      resources: [documents.bucket.arn],
    }, {
      sid: "ReadWriteDocuments",
      actions: ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"],
      resources: [bucketObjects],
    }, {
      /*
       * Separate from s3:GetObject, and required by the download gate:
       * ResolveMalwareScanStatusAction reads GuardDuty's verdict off the object's
       * tags before serving it. Without this the gate fails closed on every
       * request and nothing can be downloaded at all.
       *
       * Read only. Nothing in the application writes an object tag, and granting
       * PutObjectTagging would let the app overwrite the scanner's own verdict.
       */
      sid: "ReadDocumentScanStatus",
      actions: ["s3:GetObjectTagging"],
      resources: [bucketObjects],
    }, {
      // Textract has no resource-level permissions for these actions.
      sid: "RunTextractAnalysis",
      actions: [
        "textract:StartDocumentAnalysis",
        "textract:GetDocumentAnalysis",
      ],
      resources: ["*"],
    }, {
      // Transcribe job APIs are similarly not resource-scoped.
      sid: "RunAmazonTranscribe",
      actions: [
        "transcribe:StartTranscriptionJob",
        "transcribe:GetTranscriptionJob",
      ],
      resources: ["*"],
    }, {
      sid: "InvokeBedrockOverviewModels",
      actions: ["bedrock:InvokeModel", "bedrock:InvokeModelWithResponseStream"],
      resources: config.bedrockModelArns,
    }, {
      // Textract assumes this role to publish completion events to SNS.
      sid: "PassTextractPublishRole",
      actions: ["iam:PassRole"],
      resources: [documents.textractPublishRole.arn],
      conditions: [{
        test: "StringEquals",
        variable: "iam:PassedToService",
        values: ["textract.amazonaws.com"],
      }],
    }, {
      sid: "ConsumeTextractResults",
      actions: [
        "sqs:ReceiveMessage",
        "sqs:DeleteMessage",
        "sqs:GetQueueAttributes",
        "sqs:ChangeMessageVisibility",
      ],
      resources: [documents.resultsQueue.arn],
    }, {
      // Transcribe completions arrive via EventBridge → SQS (no PassRole needed).
      sid: "ConsumeTranscribeResults",
      actions: [
        "sqs:ReceiveMessage",
        "sqs:DeleteMessage",
        "sqs:GetQueueAttributes",
        "sqs:ChangeMessageVisibility",
      ],
      resources: [documents.transcribeResultsQueue.arn],
    }, {
      sid: "UseDocumentEncryptionKey",
      actions: ["kms:Decrypt", "kms:Encrypt", "kms:GenerateDataKey"],
      resources: [encryptionKey.arn],
    }],
  }).json;
}

/**
 * Customer managed policy for the Sail / Laravel Cloud IAM user.
 */
export function createApplicationAccessManagedPolicy(
  config: PlatformConfig,
  documents: DocumentInfrastructure,
  encryptionKey: aws.kms.Key,
): aws.iam.Policy {
  const { environment } = config;

  return new aws.iam.Policy(environment.resourceName("app-access"), {
    name: environment.resourceName("app-access"),
    path: "/qompose/",
    description: "Sail + Laravel Cloud access for S3, Textract, Transcribe, Bedrock, SQS, KMS",
    policy: applicationAccessPolicy(config, documents, encryptionKey),
  });
}
