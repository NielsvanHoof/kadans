import assert from "node:assert/strict";
import test, { before } from "node:test";
import { CapturedStack, containerDefinitions, runStack } from "./testing/harness.js";

/*
 * Staging in its own file, not its own test in stack.production.test.ts:
 * pulumi.runtime.setMocks is process-global and index.js is module-cached, so two
 * stacks in one process would silently share the first one's resources. `node
 * --test` forks per file, which is the isolation this relies on.
 */

let stack!: CapturedStack;

before(async () => {
  stack = await runStack("staging");
});

test("staging builds the same components as production", () => {
  assert.equal(stack.hasType("aws:ec2/vpc:Vpc"), true);
  assert.equal(stack.hasType("aws:ecs/cluster:Cluster"), true);
  assert.equal(stack.hasType("aws:rds/instance:Instance"), true);
  assert.equal(stack.hasType("aws:elasticache/replicationGroup:ReplicationGroup"), true);
  assert.equal(stack.hasType("aws:lb/loadBalancer:LoadBalancer"), true);
  assert.equal(stack.ofType("aws:ecs/service:Service").length, 5);
});

test("staging is single-AZ and destroyable", () => {
  const database = stack.named("database");

  assert.ok(database);
  assert.equal(database.inputs.multiAz, false);
  assert.equal(database.inputs.deletionProtection, false);
  assert.equal(database.inputs.skipFinalSnapshot, true);

  const alb = stack.named("alb");

  assert.ok(alb);
  assert.equal(alb.inputs.enableDeletionProtection, false);
});

/*
 * ElastiCache rejects automaticFailoverEnabled and multiAzEnabled on a single-node
 * group, which is why cache.ts derives both from cacheNodeCount rather than
 * exposing them as knobs that could be set to an invalid pair.
 */
test("a single-node cache does not claim to fail over", () => {
  const cache = stack.named("cache");

  assert.ok(cache);
  assert.equal(cache.inputs.numCacheClusters, 1);
  assert.equal(cache.inputs.automaticFailoverEnabled, false);
  assert.equal(cache.inputs.multiAzEnabled, false);
});

/*
 * The parts that change application behaviour stay identical to production. A
 * staging environment that skipped these would be a weaker environment wearing
 * production's clothes — which is exactly why ProductionConfigurationValidator
 * guards staging too (.ai/rules/config.md).
 */
test("staging keeps every guarantee the config validator checks", () => {
  const cache = stack.named("cache");

  assert.ok(cache);
  assert.equal(cache.inputs.transitEncryptionEnabled, true);
  assert.equal(cache.inputs.atRestEncryptionEnabled, true);

  const database = stack.named("database");

  assert.ok(database);
  assert.equal(database.inputs.storageEncrypted, true);
  assert.equal(database.inputs.publiclyAccessible, false);

  const web = stack.ofType("aws:ecs/taskDefinition:TaskDefinition")
    .find((task) => task.inputs.family === "qompose-staging-web");

  assert.ok(web);

  const variables = Object.fromEntries(
    containerDefinitions(web)[0].environment.map((
      variable: { name: string; value: string },
    ) => [variable.name, variable.value]),
  );

  assert.equal(variables.APP_ENV, "staging");
  assert.equal(variables.APP_DEBUG, "false");
  assert.equal(variables.REDIS_SCHEME, "tls");
  assert.equal(variables.FILESYSTEM_DISK, "s3");
  assert.equal(variables.MAIL_MAILER, "ses");
});

/*
 * Staging owns its own DNS level so its wildcard cannot claim the names
 * production's certificate covers. See EnvironmentSettings.dnsSubdomain.
 */
test("staging hostnames sit under their own label", () => {
  const certificate = stack.named("certificate");

  assert.ok(certificate);
  assert.equal(certificate.inputs.domainName, "app.staging.qompose.nl");
  assert.deepEqual(certificate.inputs.subjectAlternativeNames, ["*.staging.qompose.nl"]);
});

/*
 * decision-3: exactly one environment mints the long-lived Sail access key, and
 * scripts/sync-ocr-credentials.sh reads production. A key here would be a standing
 * credential with no consumer — the worst kind.
 */
test("staging mints no long-lived IAM credentials", () => {
  assert.equal(stack.hasType("aws:iam/user:User"), false);
  assert.equal(stack.hasType("aws:iam/accessKey:AccessKey"), false);
});
