import * as aws from "@pulumi/aws";
import * as pulumi from "@pulumi/pulumi";
import { PlatformConfig } from "../config.js";

export interface ApplicationIdentity {
  taskRole: aws.iam.Role;
}

export interface ExecutionIdentity {
  executionRole: aws.iam.Role;
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
 *    exactly the permissions in the shared managed app-access policy.
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
  accessPolicy: aws.iam.Policy,
  additionalPolicies: readonly aws.iam.Policy[] = [],
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

  // Same managed policy as the local IAM user — see policies.ts.
  new aws.iam.RolePolicyAttachment(environment.resourceName("app-task-policy"), {
    role: taskRole.name,
    policyArn: accessPolicy.arn,
  });

  // Production-only capabilities that must NOT reach the local IAM user — SES
  // today. See mail.ts for why that is a deliberate break from the shared policy.
  additionalPolicies.forEach((policy, index) => {
    new aws.iam.RolePolicyAttachment(
      environment.resourceName(`app-task-extra-${index}`),
      { role: taskRole.name, policyArn: policy.arn },
    );
  });

  return { taskRole };
}

/**
 * The identity the ECS *agent* runs as, before the container exists.
 *
 * The counterpart to the task role above, and the half that was missing while the
 * stack had no compute. Keeping the split honest matters because the failure modes
 * are so different: a gap in the execution role means the task never starts (image
 * pull or secret resolution fails, and the service sits in PENDING), while a gap in
 * the task role means the application starts and then throws AccessDenied at
 * runtime. Debugging one while looking at the other is a bad afternoon.
 *
 * Scoped to exactly two secrets and one key rather than the usual `*`: the app
 * secret and the RDS-managed master secret are the only things ECS resolves here.
 */
export function createExecutionIdentity(
  config: PlatformConfig,
  secretArns: pulumi.Input<string>[],
  encryptionKey: aws.kms.Key,
): ExecutionIdentity {
  const { environment } = config;

  const executionRole = new aws.iam.Role(environment.resourceName("app-execution-role"), {
    name: environment.resourceName("app-execution"),
    path: "/qompose/",
    assumeRolePolicy: aws.iam.getPolicyDocumentOutput({
      statements: [{
        effect: "Allow",
        principals: [{ type: "Service", identifiers: ["ecs-tasks.amazonaws.com"] }],
        actions: ["sts:AssumeRole"],
      }],
    }).json,
    tags: { Purpose: "ECS execution role: image pull, secret resolution, log writes" },
  });

  // AWS-managed: ECR pull and CreateLogStream/PutLogEvents. Deliberately the
  // managed policy — AWS extends it when ECS gains capabilities, and a hand-rolled
  // copy silently rots.
  new aws.iam.RolePolicyAttachment(environment.resourceName("app-execution-managed"), {
    role: executionRole.name,
    policyArn: aws.iam.ManagedPolicy.AmazonECSTaskExecutionRolePolicy,
  });

  new aws.iam.RolePolicy(environment.resourceName("app-execution-secrets"), {
    role: executionRole.id,
    policy: aws.iam.getPolicyDocumentOutput({
      statements: [{
        sid: "ResolveTaskSecrets",
        effect: "Allow",
        actions: ["secretsmanager:GetSecretValue"],
        resources: secretArns,
      }, {
        sid: "DecryptTaskSecrets",
        effect: "Allow",
        actions: ["kms:Decrypt"],
        resources: [encryptionKey.arn],
      }],
    }).json,
  });

  return { executionRole };
}
