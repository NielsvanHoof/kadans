import * as aws from "@pulumi/aws";
import * as pulumi from "@pulumi/pulumi";

/**
 * Permissions the GitHub Actions deploy role needs to run `pulumi up` against the
 * OCR stack, plus the guard that stops it escalating its own privileges.
 *
 * Scope reflects what `infra/src/` actually creates: an S3 bucket, a KMS key, an
 * SNS topic, two SQS queues, IAM identities under the `qompose` naming scheme, and
 * a CloudWatch alarm. **When the stack grows compute, this list has to grow with
 * it** (ecs:*, ec2:* for the VPC, elasticloadbalancing:*, rds:*, ecr:*) — a missing
 * permission surfaces as a Pulumi error mid-deploy, which fails safely but is
 * confusing if you are not expecting it.
 */
export function deployRolePolicy(
  accountId: pulumi.Input<string>,
  protectedArns: pulumi.Input<string>[],
): pulumi.Output<string> {
  const iamResources = pulumi.output(accountId).apply((account) => [
    `arn:aws:iam::${account}:user/qompose/*`,
    `arn:aws:iam::${account}:role/qompose-*`,
    `arn:aws:iam::${account}:role/qompose/*`,
    `arn:aws:iam::${account}:policy/qompose-*`,
  ]);

  return aws.iam.getPolicyDocumentOutput({
    statements: [{
      // Pulumi reads the whole resource graph on every refresh; these are all
      // read-only and do not support useful resource scoping.
      sid: "ReadState",
      effect: "Allow",
      actions: [
        "sts:GetCallerIdentity",
        "iam:Get*",
        "iam:List*",
        "s3:Get*",
        "s3:List*",
        "kms:Describe*",
        "kms:Get*",
        "kms:List*",
        "sns:Get*",
        "sns:List*",
        "sqs:Get*",
        "sqs:List*",
        "cloudwatch:Describe*",
        "cloudwatch:List*",
        "tag:Get*",
      ],
      resources: ["*"],
    }, {
      sid: "ManageDocumentStorage",
      effect: "Allow",
      actions: ["s3:*"],
      resources: ["arn:aws:s3:::qompose-*", "arn:aws:s3:::qompose-*/*"],
    }, {
      // KMS keys are referenced by generated id, so writes cannot be ARN-scoped at
      // creation time; the alias namespace keeps the blast radius readable.
      sid: "ManageEncryptionKeys",
      effect: "Allow",
      actions: [
        "kms:CreateKey",
        "kms:CreateAlias",
        "kms:DeleteAlias",
        "kms:ScheduleKeyDeletion",
        "kms:CancelKeyDeletion",
        "kms:EnableKeyRotation",
        "kms:DisableKeyRotation",
        "kms:PutKeyPolicy",
        "kms:TagResource",
        "kms:UntagResource",
      ],
      resources: ["*"],
    }, {
      sid: "ManageOcrMessaging",
      effect: "Allow",
      actions: ["sns:*", "sqs:*"],
      resources: [
        pulumi.output(accountId).apply((account) => `arn:aws:sns:*:${account}:qompose-*`),
        pulumi.output(accountId).apply((account) => `arn:aws:sqs:*:${account}:qompose-*`),
      ],
    }, {
      sid: "ManageOcrAlarms",
      effect: "Allow",
      actions: ["cloudwatch:PutMetricAlarm", "cloudwatch:DeleteAlarms", "cloudwatch:TagResource"],
      resources: ["*"],
    }, {
      // Needed because the stack provisions the local OCR IAM user and the ECS
      // task role. Scoped to the qompose naming scheme and paths.
      sid: "ManageQomposeIdentities",
      effect: "Allow",
      actions: [
        "iam:CreateUser",
        "iam:DeleteUser",
        "iam:PutUserPolicy",
        "iam:DeleteUserPolicy",
        "iam:TagUser",
        "iam:UntagUser",
        "iam:CreateAccessKey",
        "iam:DeleteAccessKey",
        "iam:UpdateAccessKey",
        "iam:CreateRole",
        "iam:DeleteRole",
        "iam:UpdateRole",
        "iam:UpdateAssumeRolePolicy",
        "iam:PutRolePolicy",
        "iam:DeleteRolePolicy",
        "iam:AttachRolePolicy",
        "iam:DetachRolePolicy",
        "iam:TagRole",
        "iam:UntagRole",
        "iam:PassRole",
      ],
      resources: iamResources,
    }, {
      /*
       * The privilege-escalation guard, and the reason bootstrap is a separate
       * Pulumi project rather than part of the main stack.
       *
       * The Allow above matches `role/qompose-*`, and IAM wildcards match `/`, so
       * without this the deploy role could rewrite its **own** trust policy — and
       * a workflow compromised via a malicious PR could then grant any GitHub
       * repository access to this account. An explicit Deny always beats an Allow,
       * so this closes that path permanently.
       *
       * Consequence by design: changing the deploy roles or the OIDC provider
       * requires running the bootstrap project locally with admin credentials.
       * CI cannot widen its own access.
       */
      sid: "DenyModifyingOwnAccess",
      effect: "Deny",
      actions: [
        "iam:UpdateAssumeRolePolicy",
        "iam:PutRolePolicy",
        "iam:DeleteRolePolicy",
        "iam:AttachRolePolicy",
        "iam:DetachRolePolicy",
        "iam:DeleteRole",
        "iam:UpdateRole",
        "iam:CreateOpenIDConnectProvider",
        "iam:DeleteOpenIDConnectProvider",
        "iam:UpdateOpenIDConnectProviderThumbprint",
        "iam:AddClientIDToOpenIDConnectProvider",
        "iam:RemoveClientIDFromOpenIDConnectProvider",
      ],
      resources: protectedArns,
    }],
  }).json;
}
