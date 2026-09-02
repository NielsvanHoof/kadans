import * as aws from "@pulumi/aws";
import * as pulumi from "@pulumi/pulumi";
import type { PlatformConfig } from "../config.js";

export interface OcrOperatorAccess {
  user: aws.iam.User;
  accessKey: aws.iam.AccessKey;
}

/**
 * IAM user + long-lived access key for Sail and Laravel Cloud.
 *
 * These are static credentials. That is not how an in-VPC workload should
 * authenticate — but Laravel Cloud (and a PHP process on a laptop) are not
 * something AWS can hand a role to, so there is nothing to attach a role to.
 * A scoped IAM user is the honest option for both.
 *
 * The key is acceptable *because* the policy is narrow — documents bucket,
 * OCR/Transcribe queues, Bedrock, KMS, and (separately) SES send. Each stack
 * that sets `applicationUserAccess` mints its own user scoped to that stack's
 * resources. Sync credentials with `PULUMI_STACK=<stack> ./scripts/sync-ocr-credentials.sh`.
 *
 * Resource names (`ocr-local-user`, `ocr-local`, …) stay byte-identical per stack
 * so Pulumi does not recreate the user and rotate the key out from under env files.
 */
export function createOcrOperatorAccess(
  config: PlatformConfig,
  accessPolicy: aws.iam.Policy,
  mailSendPolicy?: aws.iam.Policy,
): OcrOperatorAccess {
  const { environment } = config;

  const user = new aws.iam.User(environment.resourceName("ocr-local-user"), {
    name: environment.resourceName("ocr-local"),
    path: "/qompose/",
    tags: {
      Purpose: `Application OCR / Transcribe / Bedrock / SES - ${environment.name}`,
    },
  });

  // Managed policy attachment — IAM user inline policies cap at 2048 bytes.
  new aws.iam.UserPolicyAttachment(environment.resourceName("ocr-local-policy"), {
    user: user.name,
    policyArn: accessPolicy.arn,
  });

  if (mailSendPolicy !== undefined) {
    new aws.iam.UserPolicyAttachment(environment.resourceName("ocr-local-mail"), {
      user: user.name,
      policyArn: mailSendPolicy.arn,
    });
  }

  // Access key secret lives in Pulumi state — sync into .env with the helper script.
  const accessKey = new aws.iam.AccessKey(environment.resourceName("ocr-local-access-key"), {
    user: user.name,
  });

  return { user, accessKey };
}

/** Secret stack outputs for local / Cloud credential sync. */
export function exportOcrOperatorCredentials(access: OcrOperatorAccess): {
  ocrLocalUserName: pulumi.Output<string>;
  ocrAccessKeyId: pulumi.Output<string>;
  ocrSecretAccessKey: pulumi.Output<string>;
} {
  return {
    ocrLocalUserName: access.user.name,
    ocrAccessKeyId: access.accessKey.id,
    ocrSecretAccessKey: pulumi.secret(access.accessKey.secret),
  };
}
