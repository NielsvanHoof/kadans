import assert from "node:assert/strict";
import test, { before } from "node:test";
import type { CapturedStack} from "./testing/harness.js";
import { runStack } from "./testing/harness.js";

/*
 * Staging in its own file: pulumi.runtime.setMocks is process-global and index.js
 * is module-cached, so two stacks in one process would silently share resources.
 */

let stack!: CapturedStack;

before(async () => {
  stack = await runStack("staging");
});

test("staging builds the data plane plus public RDS", () => {
  assert.equal(stack.hasType("aws:s3/bucketV2:BucketV2"), true);
  assert.equal(stack.hasType("aws:ec2/vpc:Vpc"), true);
  assert.equal(stack.hasType("aws:rds/instance:Instance"), true);
  assert.equal(stack.hasType("aws:ses/domainIdentity:DomainIdentity"), true);
  assert.ok(stack.named("textract-publish-role"));
  assert.ok(stack.named("transcribe-complete"));
});

test("staging has no application compute", () => {
  assert.equal(stack.hasType("aws:ecs/cluster:Cluster"), false);
  assert.equal(stack.hasType("aws:elasticache/replicationGroup:ReplicationGroup"), false);
  assert.equal(stack.hasType("aws:lb/loadBalancer:LoadBalancer"), false);
  assert.equal(stack.hasType("aws:cloudfront/distribution:Distribution"), false);
  assert.equal(stack.hasType("aws:ecr/repository:Repository"), false);
  assert.equal(stack.hasType("aws:secretsmanager/secret:Secret"), false);
  assert.equal(stack.ofType("aws:ecs/service:Service").length, 0);
});

test("staging RDS is public, single-AZ, and destroyable", () => {
  const database = stack.named("database");

  assert.ok(database);
  assert.equal(database.inputs.publiclyAccessible, true);
  assert.equal(database.inputs.multiAz, false);
  assert.equal(database.inputs.deletionProtection, false);
  assert.equal(database.inputs.skipFinalSnapshot, true);
});

test("staging documents bucket allows Laravel Cloud browser uploads", () => {
  const cors = stack.named("documents-cors");
  assert.ok(cors);

  const rules = cors.inputs.corsRules as Array<{
    allowedMethods: string[];
    allowedOrigins: string[];
    allowedHeaders: string[];
  }>;

  assert.equal(rules.length, 1);
  assert.deepEqual(rules[0].allowedMethods, ["PUT"]);
  assert.ok(rules[0].allowedOrigins.includes("https://app.staging.qompose.nl"));
  assert.ok(rules[0].allowedOrigins.includes("https://*.staging.qompose.nl"));
  assert.ok(rules[0].allowedOrigins.includes("https://*.laravel.cloud"));
  assert.deepEqual(rules[0].allowedHeaders, ["content-type"]);
});

test("staging mints its own application IAM user for Laravel Cloud", () => {
  assert.equal(stack.hasType("aws:iam/user:User"), true);
  assert.equal(stack.hasType("aws:iam/accessKey:AccessKey"), true);
  assert.ok(stack.named("ocr-local-user"));
});

test("the staging operator user carries both the app-access and SES send policies", () => {
  const attachments = stack.ofType(
    "aws:iam/userPolicyAttachment:UserPolicyAttachment",
  );

  assert.equal(attachments.length, 2, "expected app-access and SES send attachments");
  assert.ok(stack.named("ocr-local-policy"), "app-access policy is not attached");
  assert.ok(stack.named("ocr-local-mail"), "SES send policy is not attached");

  const user = stack.named("ocr-local-user");
  assert.ok(user);

  const users = new Set(attachments.map((a) => String(a.inputs.user)));
  assert.equal(users.size, 1, "attachments are split across identities");
});

test("the OCR CMK grants Textract the same way it grants Transcribe", () => {
  const key = stack.named("ocr-key");

  assert.ok(key);

  const serialized = String(key.inputs.policy);

  assert.ok(serialized.includes("textract.amazonaws.com"));
  assert.ok(serialized.includes("AllowAmazonTextractToUseKey"));
});
