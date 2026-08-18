import * as aws from "@pulumi/aws";
import { PlatformConfig } from "./config.js";

export interface OcrEncryption {
  encryptionKey: aws.kms.Key;
}

/**
 * KMS key used by the documents bucket, Textract SNS topic, and SQS queues.
 *
 * Amazon Transcribe reads media and writes JSON transcripts into the same
 * CMK-encrypted bucket, so the service principal must be able to use this key.
 * EventBridge also needs the key to deliver job events into the encrypted queue.
 */
export function createOcrEncryption(config: PlatformConfig): OcrEncryption {
  const { environment } = config;
  const account = aws.getCallerIdentityOutput();

  const encryptionKey = new aws.kms.Key(environment.resourceName("ocr-key"), {
    description: `Qompose ${environment.name} OCR / Transcribe document encryption`,
    enableKeyRotation: true,
    deletionWindowInDays: environment.kmsDeletionWindowInDays,
    policy: account.accountId.apply((accountId) => JSON.stringify({
      Version: "2012-10-17",
      Statement: [{
        Sid: "EnableAccountPermissions",
        Effect: "Allow",
        Principal: { AWS: `arn:aws:iam::${accountId}:root` },
        Action: "kms:*",
        Resource: "*",
      }, {
        Sid: "AllowAwsServicesToPublishEncryptedMessages",
        Effect: "Allow",
        Principal: {
          Service: [
            "cloudwatch.amazonaws.com",
            "sns.amazonaws.com",
            // EventBridge delivers Transcribe job events into the encrypted SQS queue.
            "events.amazonaws.com",
          ],
        },
        Action: ["kms:Decrypt", "kms:GenerateDataKey"],
        Resource: "*",
        Condition: {
          StringEquals: {
            "aws:SourceAccount": accountId,
          },
        },
      }, {
        // Transcribe service-linked role decrypts media and encrypts transcript JSON.
        Sid: "AllowAmazonTranscribeToUseKey",
        Effect: "Allow",
        Principal: {
          Service: "transcribe.amazonaws.com",
        },
        Action: ["kms:Decrypt", "kms:GenerateDataKey", "kms:CreateGrant"],
        Resource: "*",
        Condition: {
          StringEquals: {
            "aws:SourceAccount": accountId,
          },
        },
      }],
    })),
  });

  new aws.kms.Alias(environment.resourceName("ocr-key-alias"), {
    name: `alias/${environment.resourceName("ocr")}`,
    targetKeyId: encryptionKey.keyId,
  });

  return { encryptionKey };
}
