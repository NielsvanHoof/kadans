import assert from "node:assert/strict";
import test from "node:test";
import {
  DeploymentEnvironment,
  deploymentEnvironmentFromStack,
} from "./environment.js";
import { localSettings } from "./local.js";
import { productionSettings } from "./production.js";

test("production keeps deletion protection, Multi-AZ, and long retention", () => {
  const environment = deploymentEnvironmentFromStack("production");

  assert.equal(environment.name, "production");
  assert.equal(environment.settings.deletionProtection, true);
  assert.equal(environment.settings.databaseMultiAz, true);
  assert.equal(environment.settings.documentNoncurrentVersionDays, 90);
  assert.equal(environment.settings.kmsDeletionWindowInDays, 30);
  assert.equal(environment.settings.databaseEnabled, true);
  assert.equal(environment.settings.databasePubliclyAccessible, true);
  assert.equal(environment.settings.applicationUserAccess, false);
});

test("staging is the cheap, destroyable public-RDS shape", () => {
  const environment = deploymentEnvironmentFromStack("staging");

  assert.equal(environment.name, "staging");
  assert.equal(environment.settings.deletionProtection, false);
  assert.equal(environment.settings.databaseMultiAz, false);
  assert.equal(environment.settings.crossRegionSnapshotCopy, false);
  assert.equal(environment.settings.databaseEnabled, true);
  assert.equal(environment.settings.databasePubliclyAccessible, true);
  assert.equal(environment.settings.applicationUserAccess, false);
  assert.equal(environment.settings.dnsSubdomain, "staging");
});

test("local is the OCR data plane plus public RDS, and owns the app IAM user", () => {
  const environment = deploymentEnvironmentFromStack("local");

  assert.equal(environment.name, "local");
  assert.equal(environment.settings.databaseEnabled, true);
  assert.equal(environment.settings.databasePubliclyAccessible, true);
  assert.equal(environment.settings.deletionProtection, false);
  assert.equal(environment.settings.applicationUserAccess, true);
  assert.equal(environment.settings.dnsSubdomain, "local");
  assert.ok(environment.settings.databaseIngressCidrs.length > 0);
});

// Exactly one environment may mint the long-lived Sail access key (decision-3);
// scripts/sync-ocr-credentials.sh reads the local stack.
test("only local mints the application IAM user", () => {
  assert.equal(
    deploymentEnvironmentFromStack("local").settings.applicationUserAccess,
    true,
  );
  assert.equal(
    deploymentEnvironmentFromStack("production").settings.applicationUserAccess,
    false,
  );
  assert.equal(
    deploymentEnvironmentFromStack("staging").settings.applicationUserAccess,
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
  const production = new DeploymentEnvironment("production", productionSettings);
  const local = new DeploymentEnvironment("local", localSettings);

  assert.equal(production.resourceName("documents"), "qompose-production-documents");
  assert.equal(local.resourceName("ocr-local-user"), "qompose-local-ocr-local-user");
});
