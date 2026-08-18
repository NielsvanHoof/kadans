import * as aws from "@pulumi/aws";
import * as pulumi from "@pulumi/pulumi";

/**
 * Permissions the GitHub Actions deploy role needs to run `pulumi up` against the
 * OCR stack, plus the guard that stops it escalating its own privileges.
 *
 * Scope reflects what `infra/src/` actually creates: the documents plane (S3, KMS,
 * Textract SNS + Transcribe EventBridge → SQS), and — since TASK-34.2 — the compute
 * platform: a VPC, an ALB, ECS Fargate services, RDS, ElastiCache, ECR, Secrets
 * Manager, ACM, Route53, SES and CloudWatch Logs.
 *
 * A missing permission surfaces as a Pulumi error mid-deploy. That fails safely,
 * but it fails *halfway* — some resources created, some not — so the list is worth
 * keeping ahead of the program rather than behind it.
 *
 * Two shapes recur below and are deliberate, not laziness:
 *
 *  - **Network and load-balancer actions cannot be ARN-scoped at creation time.**
 *    You are asking for a VPC id that does not exist yet. AWS's own guidance is to
 *    scope these by condition after the fact; for a single-tenant deploy account
 *    the honest statement is `"*"` with the escalation guard below doing the work.
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
        "ec2:Describe*",
        "ecs:Describe*",
        "ecs:List*",
        "ecr:Describe*",
        "ecr:List*",
        "ecr:GetLifecyclePolicy",
        "ecr:GetRepositoryPolicy",
        // Not repository-scopable: the token is account-wide by design, and it is
        // what `docker login` exchanges for a password. Omit it and the push fails
        // at authentication, before the repository ARN is ever consulted.
        "ecr:GetAuthorizationToken",
        "elasticloadbalancing:Describe*",
        "rds:Describe*",
        "rds:ListTagsForResource",
        "elasticache:Describe*",
        "elasticache:ListTagsForResource",
        "secretsmanager:Describe*",
        "secretsmanager:List*",
        "secretsmanager:GetResourcePolicy",
        "acm:Describe*",
        "acm:List*",
        "acm:GetCertificate",
        "route53:Get*",
        "route53:List*",
        "ses:Get*",
        "ses:List*",
        "ses:Describe*",
        "logs:Describe*",
        "logs:ListTagsForResource",
        "events:Describe*",
        "events:List*",
        "guardduty:Get*",
        "guardduty:List*",
        "guardduty:Describe*",
        "cloudtrail:Describe*",
        "cloudtrail:Get*",
        "cloudtrail:List*",
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
      // EventBridge rule that forwards Transcribe job state changes to SQS.
      sid: "ManageTranscribeEventRules",
      effect: "Allow",
      actions: [
        // The Laravel scheduler rule (rate(1 minute) → RunTask) also lands here.
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
      /*
       * Network. Unscoped because a VPC, subnet or security group has no ARN until
       * it exists — there is nothing to name in the policy at creation time.
       * `ec2:*` would also cover launching instances, so the actions are listed
       * explicitly: this deploy role can build a network, not a fleet.
       */
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
      // Load balancers, target groups and listeners are created before they can be
      // named; the ALB itself is confined by the security groups it is given.
      sid: "ManagePlatformLoadBalancing",
      effect: "Allow",
      actions: ["elasticloadbalancing:*"],
      resources: ["*"],
    }, {
      sid: "ManagePlatformCompute",
      effect: "Allow",
      actions: ["ecs:*", "ecr:*"],
      resources: [
        pulumi.output(accountId).apply((account) => `arn:aws:ecs:*:${account}:*/qompose-*`),
        pulumi.output(accountId).apply((account) => `arn:aws:ecr:*:${account}:repository/qompose-*`),
      ],
    }, {
      // ECS RunTask for the migrate role and the scheduler needs the cluster's own
      // ARN as well as the task definition's.
      sid: "RunPlatformTasks",
      effect: "Allow",
      actions: ["ecs:RunTask", "ecs:StopTask", "ecs:DescribeTasks"],
      resources: ["*"],
    }, {
      sid: "ManagePlatformDataStores",
      effect: "Allow",
      actions: ["rds:*", "elasticache:*"],
      resources: ["*"],
    }, {
      sid: "ManagePlatformSecrets",
      effect: "Allow",
      actions: [
        "secretsmanager:CreateSecret",
        "secretsmanager:DeleteSecret",
        "secretsmanager:UpdateSecret",
        "secretsmanager:PutSecretValue",
        "secretsmanager:GetSecretValue",
        "secretsmanager:TagResource",
        "secretsmanager:UntagResource",
        "secretsmanager:RestoreSecret",
      ],
      resources: [
        pulumi.output(accountId).apply((account) =>
          `arn:aws:secretsmanager:*:${account}:secret:qompose-*`
        ),
      ],
    }, {
      // Certificates and DNS. ACM certificates get a generated id, and the Route53
      // zone belongs to whoever owns the domain, so neither can be pinned here.
      sid: "ManagePlatformEdgeNames",
      effect: "Allow",
      actions: [
        "acm:RequestCertificate",
        "acm:DeleteCertificate",
        "acm:AddTagsToCertificate",
        "acm:RemoveTagsFromCertificate",
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
      sid: "ManagePlatformLogs",
      effect: "Allow",
      actions: [
        "logs:CreateLogGroup",
        "logs:DeleteLogGroup",
        "logs:PutRetentionPolicy",
        "logs:DeleteRetentionPolicy",
        "logs:AssociateKmsKey",
        "logs:DisassociateKmsKey",
        "logs:TagResource",
        "logs:UntagResource",
      ],
      resources: [
        pulumi.output(accountId).apply((account) =>
          `arn:aws:logs:*:${account}:log-group:/qompose/*`
        ),
      ],
    }, {
      /*
       * ECS, ElastiCache and RDS each create an account-wide service-linked role on
       * first use. Without this the very first deploy fails on a role the deploy
       * role was never asked to make — and the error names the service, not the
       * missing permission.
       */
      sid: "CreateServiceLinkedRoles",
      effect: "Allow",
      actions: ["iam:CreateServiceLinkedRole"],
      resources: ["*"],
      conditions: [{
        test: "StringEquals",
        variable: "iam:AWSServiceName",
        values: [
          "ecs.amazonaws.com",
          "elasticloadbalancing.amazonaws.com",
          "elasticache.amazonaws.com",
          "rds.amazonaws.com",
        ],
      }],
    }, {
      /*
       * GuardDuty Malware Protection on the documents bucket, and the CloudTrail
       * that records who reads out of it (TASK-34.1).
       *
       * Unscoped because neither resource has an ARN before it is created — a
       * malware protection plan is identified by a generated id, and the trail
       * ARN embeds the region and account rather than the qompose namespace.
       * `iam:PassRole` for the scanner role is already covered, and narrowly, by
       * ManageQomposeIdentities below.
       */
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
        "iam:AttachUserPolicy",
        "iam:DetachUserPolicy",
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
        // Shared managed app-access policy (Sail user + ECS task role).
        "iam:CreatePolicy",
        "iam:DeletePolicy",
        "iam:CreatePolicyVersion",
        "iam:DeletePolicyVersion",
        "iam:SetDefaultPolicyVersion",
        "iam:TagPolicy",
        "iam:UntagPolicy",
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
