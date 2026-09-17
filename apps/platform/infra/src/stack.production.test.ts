import assert from "node:assert/strict";
import test, { before } from "node:test";
import type { CapturedStack} from "./testing/harness.js";
import { runStack } from "./testing/harness.js";
import { LARAVEL_CLOUD_EU_WEST_1_IPV4_CIDRS } from "./shared/laravelCloudEgress.js";

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

/*
 * The database is publicly addressable (decision-9: Laravel Cloud is not in our
 * VPC), so this security group is the only thing between production data and the
 * open internet. `publiclyAccessible: true` above is safe *because* of this rule
 * and for no other reason, which is why it is asserted rather than assumed.
 *
 * The 0.0.0.0/0 assertion is separate on purpose: a wildcard alongside the real
 * CIDRs would still satisfy "the allowlist is present" while opening the database
 * to everyone.
 */
test("the database security group admits 5432 only from Laravel Cloud egress", () => {
  const securityGroup = stack.named("database-sg");

  assert.ok(securityGroup);

  const ingress = securityGroup.inputs.ingress as Array<Record<string, unknown>>;

  assert.ok(Array.isArray(ingress));
  assert.equal(ingress.length, 1, "exactly one way in");

  const [rule] = ingress;

  assert.equal(rule.protocol, "tcp");
  assert.equal(rule.fromPort, 5432);
  assert.equal(rule.toPort, 5432);

  const cidrs = rule.cidrBlocks as string[];

  assert.ok(Array.isArray(cidrs) && cidrs.length > 0, "allowlist must not be empty");
  assert.deepEqual(
    [...cidrs].sort(),
    [...LARAVEL_CLOUD_EU_WEST_1_IPV4_CIDRS].sort(),
    "allowlist must be exactly the committed Laravel Cloud egress CIDRs",
  );
  assert.ok(
    !cidrs.includes("0.0.0.0/0"),
    "the database must never be open to the internet",
  );
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

test("the OCR CMK grants Textract the same way it grants Transcribe", () => {
  const key = stack.named("ocr-key");

  assert.ok(key);

  const serialized = String(key.inputs.policy);

  assert.ok(serialized.includes("textract.amazonaws.com"));
  assert.ok(serialized.includes("AllowAmazonTextractToUseKey"));
});

test("production runs the document scan handler on the same settings as staging", () => {
  const scanFunction = stack.named("scan-handler");

  assert.ok(scanFunction);
  assert.equal(scanFunction.inputs.runtime, "provided.al2023");
  assert.deepEqual(scanFunction.inputs.architectures, ["arm64"]);
  /*
   * Deliberately not an environment setting. Nothing about rendering a page
   * differs between environments, and a knob whose value is the same everywhere
   * is a knob that will eventually differ by accident.
   */
  assert.equal(scanFunction.inputs.memorySize, 1_024);
  assert.ok(stack.named("scan-results"));
  assert.ok(stack.named("scan-result"));
});

test("the scan handler is data-plane compute, not the application coming back", () => {
  // TASK-43.3 removed application compute; this function handles bytes next to
  // the bucket, which is the one thing Laravel Cloud cannot do.
  assert.equal(stack.ofType("aws:lambda/function:Function").length, 1);
  assert.equal(stack.hasType("aws:ecs/cluster:Cluster"), false);
  assert.equal(stack.hasType("aws:ecr/repository:Repository"), false);
});
