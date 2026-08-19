import * as aws from "@pulumi/aws";
import { PlatformConfig } from "../config.js";

export interface OcrEncryption {
  encryptionKey: aws.kms.Key;
}

/**
 * KMS key used by the documents bucket, Textract SNS topic, SQS queues, and the
 * operations alerts topic.
 *
 * Amazon Transcribe reads media and writes JSON transcripts into the same
 * CMK-encrypted bucket, so the service principal must be able to use this key.
 * EventBridge also needs the key to deliver job events into the encrypted queue.
 * CloudWatch, RDS, Budgets and EventBridge need it to publish onto the encrypted
 * alerts topic — a topic policy grant without these KMS actions is a silent no-op.
 */
export function createOcrEncryption(config: PlatformConfig): OcrEncryption {
  const { environment } = config;
  const account = aws.getCallerIdentityOutput();

  const encryptionKey = new aws.kms.Key(environment.resourceName("ocr-key"), {
    description: `Qompose ${environment.name} OCR / Transcribe document encryption`,
    enableKeyRotation: true,
    deletionWindowInDays: environment.settings.kmsDeletionWindowInDays,
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
            // EventBridge delivers Transcribe job events into the encrypted SQS
            // queue, and malware findings onto the alerts topic.
            "events.amazonaws.com",
            // The alerts topic is this CMK. Without these, RDS event
            // subscriptions and Budgets match, fire, and silently fail to
            // publish — the monitoring looks healthy because nothing arrives.
            "rds.amazonaws.com",
            "events.rds.amazonaws.com",
            "budgets.amazonaws.com",
          ],
        },
        Action: ["kms:Decrypt", "kms:GenerateDataKey*"],
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

export interface PlatformEncryption {
  encryptionKey: aws.kms.Key;
}

/**
 * Data-at-rest key for the database, the cache, Secrets Manager, ECR and logs.
 *
 * Deliberately a second key rather than reusing the OCR CMK above. That key's
 * policy grants `transcribe.amazonaws.com` decrypt so Transcribe can read media
 * out of the documents bucket — a perfectly good reason for a document key, and
 * no reason at all to let a transcription service near the database. Key policies
 * are the only place that distinction can be drawn, so it needs two keys.
 *
 * The extra CMK costs about a euro a month. The alternative is one key whose
 * policy is the union of every service that ever needed it, which is how a
 * "customer managed key" quietly becomes decorative.
 */
export function createPlatformEncryption(config: PlatformConfig): PlatformEncryption {
  const { environment, region } = config;
  const account = aws.getCallerIdentityOutput();

  const encryptionKey = new aws.kms.Key(environment.resourceName("platform-key"), {
    description: `Qompose ${environment.name} platform data at rest (RDS, Valkey, Secrets, ECR, logs)`,
    enableKeyRotation: true,
    deletionWindowInDays: environment.settings.kmsDeletionWindowInDays,
    policy: account.accountId.apply((accountId) => JSON.stringify({
      Version: "2012-10-17",
      Statement: [{
        Sid: "EnableAccountPermissions",
        Effect: "Allow",
        Principal: { AWS: `arn:aws:iam::${accountId}:root` },
        Action: "kms:*",
        Resource: "*",
      }, {
        // Without this an encrypted log group cannot be created at all: CloudWatch
        // validates the grant up front and fails the PutRetentionPolicy call.
        Sid: "AllowCloudWatchLogs",
        Effect: "Allow",
        Principal: { Service: `logs.${region}.amazonaws.com` },
        Action: [
          "kms:Encrypt",
          "kms:Decrypt",
          "kms:ReEncrypt*",
          "kms:GenerateDataKey*",
          "kms:Describe*",
        ],
        Resource: "*",
        Condition: {
          ArnLike: {
            "kms:EncryptionContext:aws:logs:arn":
              `arn:aws:logs:${region}:${accountId}:log-group:*`,
          },
        },
      }],
    })),
  });

  new aws.kms.Alias(environment.resourceName("platform-key-alias"), {
    name: `alias/${environment.resourceName("platform")}`,
    targetKeyId: encryptionKey.keyId,
  });

  return { encryptionKey };
}
