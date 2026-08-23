import assert from "node:assert/strict";
import test, { before } from "node:test";
import { CapturedStack, runStack } from "./testing/harness.js";

/*
 * Production in its own file: pulumi.runtime.setMocks is process-global and
 * index.js is module-cached, so two stacks in one process would silently share
 * resources.
 */

let stack!: CapturedStack;

before(async () => {
  stack = await runStack("production");
});

test("production builds the data plane plus public RDS", () => {
  assert.equal(stack.hasType("aws:s3/bucketV2:BucketV2"), true);
  assert.equal(stack.hasType("aws:ec2/vpc:Vpc"), true);
  assert.equal(stack.hasType("aws:rds/instance:Instance"), true);
  assert.equal(stack.hasType("aws:ses/domainIdentity:DomainIdentity"), true);
  assert.equal(stack.hasType("aws:guardduty/malwareProtectionPlan:MalwareProtectionPlan"), true);
  assert.ok(stack.named("textract-publish-role"));
  assert.ok(stack.named("transcribe-complete"));
});

test("production has no application compute", () => {
  assert.equal(stack.hasType("aws:ecs/cluster:Cluster"), false);
  assert.equal(stack.hasType("aws:elasticache/replicationGroup:ReplicationGroup"), false);
  assert.equal(stack.hasType("aws:lb/loadBalancer:LoadBalancer"), false);
  assert.equal(stack.hasType("aws:cloudfront/distribution:Distribution"), false);
  assert.equal(stack.hasType("aws:wafv2/webAcl:WebAcl"), false);
  assert.equal(stack.hasType("aws:ecr/repository:Repository"), false);
  assert.equal(stack.hasType("aws:secretsmanager/secret:Secret"), false);
  assert.equal(stack.ofType("aws:ecs/service:Service").length, 0);
  assert.equal(stack.ofType("aws:acm/certificate:Certificate").length, 0);
});

test("production RDS is public, Multi-AZ, and protected", () => {
  const database = stack.named("database");

  assert.ok(database);
  assert.equal(database.inputs.publiclyAccessible, true);
  assert.equal(database.inputs.multiAz, true);
  assert.equal(database.inputs.deletionProtection, true);
  assert.equal(database.inputs.skipFinalSnapshot, false);
});

test("production keeps cross-region backup replication", () => {
  assert.equal(
    stack.hasType("aws:rds/instanceAutomatedBackupsReplication:InstanceAutomatedBackupsReplication"),
    true,
  );
});

/*
 * decision-3: credentials live on the local stack.
 */
test("production does not mint the application IAM user", () => {
  assert.equal(stack.hasType("aws:iam/user:User"), false);
  assert.equal(stack.hasType("aws:iam/accessKey:AccessKey"), false);
});

test("production scanner policy includes GuardDuty EventBridge managed-rule access", () => {
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
