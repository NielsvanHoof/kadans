import * as aws from "@pulumi/aws";
import * as pulumi from "@pulumi/pulumi";
import { PlatformConfig } from "./config.js";
import { DocumentInfrastructure } from "./documents.js";

/**
 * Everything the Qompose application needs from AWS at runtime.
 *
 * Deliberately defined once and shared by two principals:
 *
 *  - the local IAM user (`operator.ts`), used by Sail on a developer machine
 *  - the ECS task role (`application.ts`), used by the deployed container
 *
 * They must stay identical — if local and production diverge, OCR breaks in
 * production only, which is the worst place to find out. Sharing the document
 * makes that impossible by construction rather than by discipline.
 *
 * Two `"*"` resources are intentional, not oversights:
 *
 *  - **Textract** does not support resource-level permissions for
 *    StartDocumentAnalysis / GetDocumentAnalysis. `"*"` is the only value AWS
 *    accepts for these actions.
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
      sid: "UseDocumentEncryptionKey",
      actions: ["kms:Decrypt", "kms:Encrypt", "kms:GenerateDataKey"],
      resources: [encryptionKey.arn],
    }],
  }).json;
}
