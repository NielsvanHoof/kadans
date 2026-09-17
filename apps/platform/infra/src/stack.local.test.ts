import assert from "node:assert/strict";
import test, { before } from "node:test";
import type { CapturedStack} from "./testing/harness.js";
import { runStack } from "./testing/harness.js";

/*
 * Local in its own file: pulumi.runtime.setMocks is process-global and index.js
 * is module-cached, so two stacks in one process would silently share resources.
 */

let stack!: CapturedStack;

before(async () => {
  stack = await runStack("local");
});

test("local builds the documents and OCR plane", () => {
  assert.equal(stack.hasType("aws:s3/bucketV2:BucketV2"), true);
  assert.equal(stack.hasType("aws:guardduty/malwareProtectionPlan:MalwareProtectionPlan"), true);
  assert.ok(stack.named("textract-publish-role"));
  assert.ok(stack.named("transcribe-complete"));
  assert.ok(stack.named("malware-findings"));
});

test("local documents bucket allows Laravel Cloud browser uploads", () => {
  const cors = stack.named("documents-cors");
  assert.ok(cors);

  const rules = cors.inputs.corsRules as Array<{
    allowedMethods: string[];
    allowedOrigins: string[];
    allowedHeaders: string[];
  }>;

  assert.equal(rules.length, 1);
  assert.deepEqual(rules[0].allowedMethods, ["PUT"]);
  assert.ok(rules[0].allowedOrigins.includes("https://app.local.qompose.nl"));
  assert.ok(rules[0].allowedOrigins.includes("https://*.local.qompose.nl"));
  assert.ok(rules[0].allowedOrigins.includes("http://localhost"));
  assert.ok(rules[0].allowedOrigins.includes("http://127.0.0.1"));
  assert.ok(rules[0].allowedOrigins.includes("https://*.laravel.cloud"));
  assert.deepEqual(rules[0].allowedHeaders, ["content-type"]);
});

test("local has no RDS or VPC — Sail uses Docker Postgres", () => {
  assert.equal(stack.hasType("aws:ec2/vpc:Vpc"), false);
  assert.equal(stack.hasType("aws:rds/instance:Instance"), false);
  assert.equal(stack.hasType("aws:ecs/cluster:Cluster"), false);
  assert.equal(stack.hasType("aws:elasticache/replicationGroup:ReplicationGroup"), false);
  assert.equal(stack.hasType("aws:lb/loadBalancer:LoadBalancer"), false);
  assert.equal(stack.hasType("aws:acm/certificate:Certificate"), false);
  assert.equal(stack.hasType("aws:cloudfront/distribution:Distribution"), false);
  assert.equal(stack.hasType("aws:ecr/repository:Repository"), false);
  assert.equal(stack.hasType("aws:secretsmanager/secret:Secret"), false);
  assert.equal(stack.ofType("aws:ecs/service:Service").length, 0);
  assert.equal(stack.named("database"), undefined);
});

/*
 * Local mints the Sail OCR operator. Staging mints its own user for Laravel Cloud.
 * Each stack's queues are scoped by resourceName — never share TEXTRACT_RESULTS_QUEUE_URL.
 */
test("local mints the Sail OCR operator credentials", () => {
  assert.equal(stack.hasType("aws:iam/user:User"), true);
  assert.equal(stack.hasType("aws:iam/accessKey:AccessKey"), true);
  assert.ok(stack.named("ocr-local-user"));
});

/*
 * Local and staging each mint an IAM user; production does not yet.
 */
test("the operator user carries both the app-access and SES send policies", () => {
  const attachments = stack.ofType(
    "aws:iam/userPolicyAttachment:UserPolicyAttachment",
  );

  assert.equal(attachments.length, 2, "expected app-access and SES send attachments");
  assert.ok(stack.named("ocr-local-policy"), "app-access policy is not attached");
  assert.ok(stack.named("ocr-local-mail"), "SES send policy is not attached");

  const user = stack.named("ocr-local-user");
  assert.ok(user);

  // Both must land on the same user; attaching SES to a second identity would
  // read as "SES works" while the app still could not send.
  const users = new Set(attachments.map((a) => String(a.inputs.user)));
  assert.equal(users.size, 1, "attachments are split across identities");
});

test("local creates SES for application mail without compute", () => {
  assert.equal(stack.hasType("aws:ses/domainIdentity:DomainIdentity"), true);
  assert.equal(stack.hasType("aws:ses/domainDkim:DomainDkim"), true);
  assert.ok(stack.named("mail-send"));
});

test("local scanner policy includes GuardDuty EventBridge managed-rule access", () => {
  const document = stack.policyDocuments.find((policyDocument) =>
    JSON.stringify(policyDocument).includes("ManageGuardDutyEventRule")
  );

  assert.ok(document);

  const statements = document.statements as Array<Record<string, any>>;
  const sids = statements.map((statement) => statement.sid);

  assert.ok(sids.includes("ManageGuardDutyEventRule"));
  assert.ok(sids.includes("MonitorGuardDutyEventRule"));
  assert.ok(sids.includes("AllowPutValidationObject"));
});

test("the OCR CMK lets Textract decrypt documents and publish to encrypted SNS", () => {
  const key = stack.named("ocr-key");

  assert.ok(key);

  const policy = JSON.parse(String(key.inputs.policy)) as {
    Statement: Array<{
      Sid?: string;
      Principal?: { Service?: string | string[] };
      Action?: string | string[];
    }>;
  };

  const publish = policy.Statement.find(
    (statement) => statement.Sid === "AllowAwsServicesToPublishEncryptedMessages",
  );

  assert.ok(publish);
  const publishServices = [publish.Principal?.Service].flat();
  assert.ok(
    publishServices.includes("textract.amazonaws.com"),
    "encrypted SNS publish must name textract.amazonaws.com or completions never arrive",
  );

  const textract = policy.Statement.find(
    (statement) => statement.Sid === "AllowAmazonTextractToUseKey",
  );

  assert.ok(textract, "Textract needs its own key grant to read SSE-KMS PDFs");
  assert.equal(textract.Principal?.Service, "textract.amazonaws.com");
  const textractActions = [textract.Action].flat();
  assert.ok(textractActions.includes("kms:Decrypt"));
  assert.ok(textractActions.includes("kms:GenerateDataKey"));
  assert.ok(textractActions.includes("kms:CreateGrant"));
});

test("the Textract publish role can generate data keys for encrypted SNS", () => {
  const document = stack.policyDocuments.find((policyDocument) => {
    const serialized = JSON.stringify(policyDocument);

    return serialized.includes("sns:Publish") && serialized.includes("kms:GenerateDataKey*");
  });

  assert.ok(document, "textract publish policy must allow GenerateDataKey* for SNS SSE");
});

test("local builds the scan handler too, so Sail exercises the real path", () => {
  /*
   * GuardDuty is created on every stack, so the verdicts exist locally whether
   * or not anything consumes them. Building the function here as well keeps the
   * local stack a faithful rehearsal rather than a different shape.
   */
  assert.ok(stack.named("scan-handler"));
  assert.ok(stack.named("scan-results"));
  assert.ok(stack.named("scan-result"));
});
