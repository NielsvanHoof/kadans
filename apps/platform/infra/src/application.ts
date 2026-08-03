import * as aws from "@pulumi/aws";
import { PlatformConfig } from "./config.js";
import { DocumentInfrastructure } from "./documents.js";
import { applicationAccessPolicy } from "./policies.js";

export interface ApplicationIdentity {
  taskRole: aws.iam.Role;
}

/**
 * The identity the deployed application runs as.
 *
 * This is the **task role**, not the execution role — a distinction worth keeping
 * straight because they are easy to confuse:
 *
 *  - *execution role* — assumed by the ECS agent before the container starts, to
 *    pull the image from ECR, resolve Secrets Manager values, and write logs. It
 *    belongs with the ECS service definition and does not exist yet.
 *  - *task role* — assumed by the application code itself. This one. It carries
 *    exactly the permissions in `applicationAccessPolicy`.
 *
 * Created ahead of any ECS resources on purpose. An unattached IAM role costs
 * nothing, and it means the permission set is provisioned and reviewable now
 * rather than being invented under time pressure alongside a VPC, an ALB, and a
 * database. When the service lands, it only has to reference `taskRole.arn`.
 *
 * No application code changes are needed to use it: both credential paths in the
 * app (`DocumentProcessingServiceProvider::awsClientConfig` and Laravel's
 * `FilesystemManager::formatS3Config`) omit explicit credentials when
 * AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY are empty, so the SDK falls through to
 * the container credential provider and picks this role up automatically.
 */
export function createApplicationIdentity(
  config: PlatformConfig,
  documents: DocumentInfrastructure,
  encryptionKey: aws.kms.Key,
): ApplicationIdentity {
  const { environment } = config;
  const account = aws.getCallerIdentityOutput();

  const assumeRolePolicy = aws.iam.getPolicyDocumentOutput({
    statements: [{
      effect: "Allow",
      principals: [{ type: "Service", identifiers: ["ecs-tasks.amazonaws.com"] }],
      actions: ["sts:AssumeRole"],
      // Confused-deputy guard: only ECS tasks in this account may assume the role,
      // so another account cannot trick ECS into handing out these permissions.
      conditions: [{
        test: "ArnLike",
        variable: "aws:SourceArn",
        values: [
          account.accountId.apply(
            (accountId) => `arn:aws:ecs:${config.region}:${accountId}:*`,
          ),
        ],
      }, {
        test: "StringEquals",
        variable: "aws:SourceAccount",
        values: [account.accountId],
      }],
    }],
  });

  const taskRole = new aws.iam.Role(environment.resourceName("app-task-role"), {
    name: environment.resourceName("app-task"),
    path: "/qompose/",
    assumeRolePolicy: assumeRolePolicy.json,
    tags: {
      Purpose: "ECS task role for the Qompose application container",
    },
  });

  new aws.iam.RolePolicy(environment.resourceName("app-task-policy"), {
    role: taskRole.id,
    // Same document as the local IAM user — see policies.ts.
    policy: applicationAccessPolicy(config, documents, encryptionKey),
  });

  return { taskRole };
}
