import assert from "node:assert/strict";
import test from "node:test";
import {
  DeploymentEnvironment,
  deploymentEnvironmentFromStack,
} from "./environment.js";
import { productionSettings } from "./production.js";

test("production keeps deletion protection, Multi-AZ, and long retention", () => {
  const environment = deploymentEnvironmentFromStack("production");

  assert.equal(environment.name, "production");
  assert.equal(environment.settings.deletionProtection, true);
  assert.equal(environment.settings.databaseMultiAz, true);
  assert.equal(environment.settings.documentNoncurrentVersionDays, 90);
  assert.equal(environment.settings.kmsDeletionWindowInDays, 30);
});

test("staging is the cheap, destroyable shape", () => {
  const environment = deploymentEnvironmentFromStack("staging");

  assert.equal(environment.name, "staging");
  assert.equal(environment.settings.deletionProtection, false);
  assert.equal(environment.settings.databaseMultiAz, false);
  assert.equal(environment.settings.cacheNodeCount, 1);
  assert.equal(environment.settings.edgeEnabled, false);
  assert.equal(environment.settings.crossRegionSnapshotCopy, false);
});

// Exactly one environment may mint the long-lived Sail access key (decision-3);
// scripts/sync-ocr-credentials.sh reads the production stack. A second key in
// staging would be a standing credential with no consumer.
test("only production mints the local Sail IAM user", () => {
  assert.equal(
    deploymentEnvironmentFromStack("production").settings.localOperatorAccess,
    true,
  );
  assert.equal(
    deploymentEnvironmentFromStack("staging").settings.localOperatorAccess,
    false,
  );
});

test("unknown stacks are rejected by name", () => {
  assert.throws(
    () => deploymentEnvironmentFromStack("developer"),
    /Unsupported stack "developer"/,
  );
});

// Pulumi keys state by these strings; a format change reads as delete-and-recreate.
test("resource names are stable and environment scoped", () => {
  const environment = new DeploymentEnvironment("production", productionSettings);

  assert.equal(environment.resourceName("documents"), "qompose-production-documents");
  assert.equal(environment.resourceName("ocr-operator"), "qompose-production-ocr-operator");
});
