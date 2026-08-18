import * as aws from "@pulumi/aws";
import * as pulumi from "@pulumi/pulumi";
import { PlatformConfig } from "../config.js";
import { DocumentInfrastructure } from "./documents.js";

/**
 * Everything the Qompose application needs from AWS at runtime.
 *
 * Deliberately defined once and attached to two principals via a managed policy:
 *
 *  - the local IAM user (`operator.ts`), used by Sail on a developer machine
 *  - the ECS task role (`application.ts`), used by the deployed container
 *
 * They must stay identical — if local and production diverge, OCR/Transcribe
 * breaks in production only, which is the worst place to find out. Sharing one
 * managed policy makes that impossible by construction rather than by discipline.
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
 * Customer managed policy shared by the local IAM user and the ECS task role.
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
    description: "Shared Sail + ECS access for S3, Textract, Transcribe, Bedrock, SQS, KMS",
    policy: applicationAccessPolicy(config, documents, encryptionKey),
  });
}
