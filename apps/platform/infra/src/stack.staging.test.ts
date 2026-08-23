import assert from "node:assert/strict";
import test, { before } from "node:test";
import { CapturedStack, runStack } from "./testing/harness.js";

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

/*
 * decision-3: credentials live on the local stack. A key here would be a standing
 * credential nothing consumes.
 */
test("staging does not mint the application IAM user", () => {
  assert.equal(stack.hasType("aws:iam/user:User"), false);
  assert.equal(stack.hasType("aws:iam/accessKey:AccessKey"), false);
});
