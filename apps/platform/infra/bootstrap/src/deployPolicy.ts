import * as aws from "@pulumi/aws";
import * as pulumi from "@pulumi/pulumi";

/**
 * Permissions the GitHub Actions deploy role needs to run `pulumi up` against the
 * data-plane stack, plus the guard that stops it escalating its own privileges.
 *
 * Scope reflects what `infra/src/` actually creates after TASK-43.3: the documents
 * plane (S3, KMS, Textract SNS + Transcribe EventBridge → SQS), public RDS in a
 * small VPC, SES, optional Route53 DKIM records, budgets/alarms, and the
 * application IAM user on the `local` stack.
 *
 * Compute grants (ECS, ELB, CloudFront, ElastiCache, Secrets Manager, ECR, ACM
 * for ALB) were removed with the compute plane. A missing permission still fails
 * mid-deploy — some resources created, some not — so this list must stay ahead of
 * the program.
 *
 * Two shapes recur below and are deliberate:
 *
 *  - **Network actions cannot be ARN-scoped at creation time.** You are asking
 *    for a VPC id that does not exist yet. For a single-tenant deploy account the
 *    honest statement is `"*"` with the escalation guard below doing the work.
 *  - **Read actions are unscoped** because Pulumi refreshes the entire resource
 *    graph on every run.
 */
export function deployRolePolicy(
  accountId: pulumi.Input<string>,
  protectedArns: pulumi.Input<string>[],
): pulumi.Output<string> {
  const iamResources = pulumi.output(accountId).apply((account) => [
    `arn:aws:iam::${account}:user/qompose/*`,
    `arn:aws:iam::${account}:role/qompose-*`,
    `arn:aws:iam::${account}:role/qompose/*`,
    // Both path and name-prefix forms — IAM wildcards treat `/` as a character.
    `arn:aws:iam::${account}:policy/qompose-*`,
    `arn:aws:iam::${account}:policy/qompose/*`,
  ]);

  return aws.iam.getPolicyDocumentOutput({
    statements: [{
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
        "ec2:Describe*",
        "rds:Describe*",
        "rds:ListTagsForResource",
        "route53:Get*",
        "route53:List*",
        "ses:Get*",
        "ses:List*",
        "ses:Describe*",
        "events:Describe*",
        "events:List*",
        "guardduty:Get*",
        "guardduty:List*",
        "guardduty:Describe*",
        "cloudtrail:Describe*",
        "cloudtrail:Get*",
        "cloudtrail:List*",
        "budgets:Describe*",
        "budgets:View*",
      ],
      resources: ["*"],
    }, {
      sid: "ManageDocumentStorage",
      effect: "Allow",
      actions: ["s3:*"],
      resources: ["arn:aws:s3:::qompose-*", "arn:aws:s3:::qompose-*/*"],
    }, {
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
        "kms:Encrypt",
        "kms:Decrypt",
        "kms:ReEncrypt*",
        "kms:GenerateDataKey*",
        "kms:TagResource",
        "kms:UntagResource",
      ],
      resources: ["*"],
    }, {
      sid: "ManageEncryptionGrantsForAwsResources",
      effect: "Allow",
      actions: ["kms:CreateGrant", "kms:RetireGrant", "kms:RevokeGrant"],
      resources: ["*"],
      conditions: [{
        test: "Bool",
        variable: "kms:GrantIsForAWSResource",
        values: ["true"],
      }],
    }, {
      sid: "ManageOcrMessaging",
      effect: "Allow",
      actions: ["sns:*", "sqs:*"],
      resources: [
        pulumi.output(accountId).apply((account) => `arn:aws:sns:*:${account}:qompose-*`),
        pulumi.output(accountId).apply((account) => `arn:aws:sqs:*:${account}:qompose-*`),
      ],
    }, {
      sid: "ManageTranscribeEventRules",
      effect: "Allow",
      actions: [
        "events:PutRule",
        "events:DeleteRule",
        "events:DescribeRule",
        "events:ListRules",
        "events:PutTargets",
        "events:RemoveTargets",
        "events:ListTargetsByRule",
        "events:TagResource",
        "events:UntagResource",
      ],
      resources: [
        pulumi.output(accountId).apply((account) => `arn:aws:events:*:${account}:rule/qompose-*`),
      ],
    }, {
      sid: "ManagePlatformNetwork",
      effect: "Allow",
      actions: [
        "ec2:CreateVpc",
        "ec2:DeleteVpc",
        "ec2:ModifyVpcAttribute",
        "ec2:CreateSubnet",
        "ec2:DeleteSubnet",
        "ec2:ModifySubnetAttribute",
        "ec2:CreateInternetGateway",
        "ec2:DeleteInternetGateway",
        "ec2:AttachInternetGateway",
        "ec2:DetachInternetGateway",
        "ec2:CreateRouteTable",
        "ec2:DeleteRouteTable",
        "ec2:CreateRoute",
        "ec2:DeleteRoute",
        "ec2:AssociateRouteTable",
        "ec2:DisassociateRouteTable",
        "ec2:CreateSecurityGroup",
        "ec2:DeleteSecurityGroup",
        "ec2:AuthorizeSecurityGroupIngress",
        "ec2:AuthorizeSecurityGroupEgress",
        "ec2:RevokeSecurityGroupIngress",
        "ec2:RevokeSecurityGroupEgress",
        "ec2:CreateTags",
        "ec2:DeleteTags",
      ],
      resources: ["*"],
    }, {
      sid: "ManagePlatformDataStores",
      effect: "Allow",
      actions: ["rds:*"],
      resources: ["*"],
    }, {
      // DKIM CNAMEs when the zone is in Route53. Unscoped because the zone id is
      // not always owned by this account's naming scheme.
      sid: "ManagePlatformDns",
      effect: "Allow",
      actions: [
        "route53:ChangeResourceRecordSets",
        "route53:ChangeTagsForResource",
      ],
      resources: ["*"],
    }, {
      sid: "ManagePlatformMail",
      effect: "Allow",
      actions: [
        "ses:VerifyDomainIdentity",
        "ses:VerifyDomainDkim",
        "ses:DeleteIdentity",
        "ses:SetIdentityMailFromDomain",
        "ses:TagResource",
        "ses:UntagResource",
      ],
      resources: ["*"],
    }, {
      // RDS creates an account-wide service-linked role on first use.
      sid: "CreateServiceLinkedRoles",
      effect: "Allow",
      actions: ["iam:CreateServiceLinkedRole"],
      resources: ["*"],
      conditions: [{
        test: "StringEquals",
        variable: "iam:AWSServiceName",
        values: ["rds.amazonaws.com"],
      }],
    }, {
      sid: "ManageDocumentScanningAndAudit",
      effect: "Allow",
      actions: [
        "guardduty:CreateMalwareProtectionPlan",
        "guardduty:UpdateMalwareProtectionPlan",
        "guardduty:DeleteMalwareProtectionPlan",
        "guardduty:TagResource",
        "guardduty:UntagResource",
        "cloudtrail:CreateTrail",
        "cloudtrail:UpdateTrail",
        "cloudtrail:DeleteTrail",
        "cloudtrail:StartLogging",
        "cloudtrail:StopLogging",
        "cloudtrail:PutEventSelectors",
        "cloudtrail:AddTags",
        "cloudtrail:RemoveTags",
      ],
      resources: ["*"],
    }, {
      /*
       * The document-scan Lambda (TASK-62.2) — the only compute in the stack.
       *
       * Scoped to the naming scheme like the queues and rules above. The zip is
       * uploaded inline by `pulumi up`, so there is no ECR or S3 artefact bucket
       * to grant on top of this.
       */
      sid: "ManageLambdaFunctions",
      effect: "Allow",
      actions: [
        "lambda:CreateFunction",
        "lambda:DeleteFunction",
        "lambda:GetFunction",
        "lambda:GetFunctionConfiguration",
        "lambda:GetFunctionCodeSigningConfig",
        "lambda:ListVersionsByFunction",
        "lambda:UpdateFunctionCode",
        "lambda:UpdateFunctionConfiguration",
        "lambda:AddPermission",
        "lambda:RemovePermission",
        "lambda:GetPolicy",
        "lambda:TagResource",
        "lambda:UntagResource",
        "lambda:ListTags",
      ],
      resources: [
        pulumi.output(accountId).apply((account) => `arn:aws:lambda:*:${account}:function:qompose-*`),
      ],
    }, {
      sid: "ManageOcrAlarms",
      effect: "Allow",
      actions: ["cloudwatch:PutMetricAlarm", "cloudwatch:DeleteAlarms", "cloudwatch:TagResource"],
      resources: ["*"],
    }, {
      sid: "ManageOperationsAlerting",
      effect: "Allow",
      actions: [
        "budgets:CreateBudget",
        "budgets:ModifyBudget",
        "budgets:DeleteBudget",
        "budgets:CreateNotification",
        "budgets:DeleteNotification",
        "budgets:CreateSubscriber",
        "budgets:DeleteSubscriber",
        "rds:CreateEventSubscription",
        "rds:ModifyEventSubscription",
        "rds:DeleteEventSubscription",
        "rds:AddSourceIdentifierToSubscription",
        "rds:RemoveSourceIdentifierFromSubscription",
        "rds:StartDBInstanceAutomatedBackupsReplication",
        "rds:StopDBInstanceAutomatedBackupsReplication",
        "rds:DescribeDBInstanceAutomatedBackups",
      ],
      resources: ["*"],
    }, {
      // Application IAM user (local stack) and shared managed access policy.
      sid: "ManageQomposeIdentities",
      effect: "Allow",
      actions: [
        "iam:CreateUser",
        "iam:DeleteUser",
        "iam:PutUserPolicy",
        "iam:DeleteUserPolicy",
        "iam:AttachUserPolicy",
        "iam:DetachUserPolicy",
        "iam:TagUser",
        "iam:UntagUser",
        "iam:CreateAccessKey",
        "iam:DeleteAccessKey",
        "iam:UpdateAccessKey",
        "iam:CreatePolicy",
        "iam:DeletePolicy",
        "iam:CreatePolicyVersion",
        "iam:DeletePolicyVersion",
        "iam:SetDefaultPolicyVersion",
        "iam:TagPolicy",
        "iam:UntagPolicy",
        /*
         * Service roles the stack owns: the Textract publish role, the GuardDuty
         * scan role, and the document-scan Lambda's execution role.
         *
         * The Deny below still wins for the deploy roles themselves — that is
         * the whole point of it being an explicit Deny, and it is why these can
         * be granted across `role/qompose-*` without opening a path for CI to
         * rewrite its own trust policy.
         */
        "iam:CreateRole",
        "iam:DeleteRole",
        "iam:UpdateAssumeRolePolicy",
        "iam:PutRolePolicy",
        "iam:DeleteRolePolicy",
        "iam:AttachRolePolicy",
        "iam:DetachRolePolicy",
        "iam:TagRole",
        "iam:UntagRole",
      ],
      resources: iamResources,
    }, {
      /*
       * Handing a role to the service that will assume it is a separate
       * permission from creating it, and it is the one that fails last — the
       * role exists, the policy is attached, and the resource still will not
       * create.
       *
       * Scoped by destination service so this cannot be used to pass a role
       * anywhere else.
       */
      sid: "PassServiceRoles",
      effect: "Allow",
      actions: ["iam:PassRole"],
      resources: iamResources,
      conditions: [{
        test: "StringEquals",
        variable: "iam:PassedToService",
        values: [
          "lambda.amazonaws.com",
          "textract.amazonaws.com",
          "malware-protection-plan.guardduty.amazonaws.com",
        ],
      }],
    }, {
      /*
       * Privilege-escalation guard — why bootstrap is a separate Pulumi project.
       *
       * The Allow above matches `role/qompose-*`, and IAM wildcards match `/`, so
       * without this the deploy role could rewrite its own trust policy. An
       * explicit Deny always beats an Allow. Changing deploy roles or the OIDC
       * provider requires running bootstrap locally with admin credentials.
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
