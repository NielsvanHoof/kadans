import * as aws from "@pulumi/aws";
import * as pulumi from "@pulumi/pulumi";
import { PlatformConfig } from "./config.js";
import { DocumentInfrastructure } from "./documents.js";
import { applicationAccessPolicy } from "./policies.js";

export interface OcrOperatorAccess {
  user: aws.iam.User;
  accessKey: aws.iam.AccessKey;
}

/**
 * IAM user + long-lived access key for local Sail development.
 *
 * These are static credentials, which is not how production should work — but the
 * reason is worth stating accurately, because it is easy to draw the wrong lesson.
 *
 * IAM roles hand out temporary credentials to something AWS already recognises: an
 * ECS task, an EC2 instance, a Lambda. A PHP process on a developer's laptop is
 * none of those, so there is nothing to attach a role to. Until the stack grows
 * compute (see `application.ts` for the task role that is already waiting), a
 * scoped IAM user is the honest option for local work.
 *
 * The key is acceptable *because* the policy is narrow — it reaches one bucket, one
 * queue, and one KMS key, and nothing else. It is not acceptable in production, and
 * the deployed app must never use it: `application.ts` holds the same permissions
 * as a task role, and the container gets credentials from the ECS agent instead.
 *
 * (An earlier version of this comment said "root cannot assume roles". True, but
 * beside the point: the real fix is not using root at all — see infra/README.md.)
 */
export function createOcrOperatorAccess(
  config: PlatformConfig,
  documents: DocumentInfrastructure,
  encryptionKey: aws.kms.Key,
): OcrOperatorAccess {
  const { environment } = config;

  const user = new aws.iam.User(environment.resourceName("ocr-local-user"), {
    name: environment.resourceName("ocr-local"),
    path: "/qompose/",
    tags: {
      Purpose: "Local Sail Textract + Bedrock OCR",
    },
  });

  new aws.iam.UserPolicy(environment.resourceName("ocr-local-policy"), {
    user: user.name,
    // Shared with the ECS task role so local and production cannot drift.
    policy: applicationAccessPolicy(config, documents, encryptionKey),
  });

  // Access key secret is stored in Pulumi state — sync into .env with the helper script.
  const accessKey = new aws.iam.AccessKey(environment.resourceName("ocr-local-access-key"), {
    user: user.name,
  });

  return { user, accessKey };
}

/** Secret stack outputs for local .env sync. */
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
