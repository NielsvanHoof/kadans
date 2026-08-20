import assert from "node:assert/strict";
import test, { before } from "node:test";
import { CapturedStack, runStack } from "./testing/harness.js";

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

test("local does not build application compute", () => {
  assert.equal(stack.hasType("aws:ec2/vpc:Vpc"), false);
  assert.equal(stack.hasType("aws:ecs/cluster:Cluster"), false);
  assert.equal(stack.hasType("aws:rds/instance:Instance"), false);
  assert.equal(stack.hasType("aws:elasticache/replicationGroup:ReplicationGroup"), false);
  assert.equal(stack.hasType("aws:lb/loadBalancer:LoadBalancer"), false);
  assert.equal(stack.hasType("aws:acm/certificate:Certificate"), false);
  assert.equal(stack.ofType("aws:ecs/service:Service").length, 0);
});

/*
 * decision-3: the long-lived Sail key lives on local so sync-ocr-credentials.sh
 * has exactly one stack to read.
 */
test("local mints the Sail OCR operator credentials", () => {
  assert.equal(stack.hasType("aws:iam/user:User"), true);
  assert.equal(stack.hasType("aws:iam/accessKey:AccessKey"), true);
  assert.ok(stack.named("ocr-local-user"));
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
