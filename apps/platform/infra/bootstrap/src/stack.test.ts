import assert from "node:assert/strict";
import test from "node:test";
import * as pulumi from "@pulumi/pulumi";

test("the bootstrap stack pins the trust policy and blocks self-escalation", async () => {
  const resources: Array<{ type: string; name: string; inputs: Record<string, unknown> }> = [];
  // Captured so we can assert on the *statements* rather than the mocked json.
  const policyDocuments: Array<Record<string, unknown>> = [];

  pulumi.runtime.setAllConfig({
    "aws:region": "eu-west-1",
    "qompose-bootstrap:githubRepository": "NielsvanHoof/qompose",
    "qompose-bootstrap:githubRepositoryOwnerId": "26738111",
    "qompose-bootstrap:githubRepositoryId": "1301716861",
  }, []);

  await pulumi.runtime.setMocks({
    call: (args) => {
      if (args.token.includes("getCallerIdentity")) {
        return {
          accountId: "123456789012",
          arn: "arn:aws:iam::123456789012:root",
          userId: "test",
        };
      }

      if (args.token.includes("getPolicyDocument")) {
        policyDocuments.push(args.inputs as Record<string, unknown>);

        return { json: JSON.stringify({ Version: "2012-10-17", Statement: [] }) };
      }

      return args.inputs;
    },
    newResource: (args) => {
      resources.push({ type: args.type, name: args.name, inputs: args.inputs });

      return {
        id: `${args.name}-id`,
        state: {
          ...args.inputs,
          arn: `arn:aws:iam::123456789012:role/qompose-bootstrap/${args.name}`,
        },
      };
    },
  }, "qompose-bootstrap", "bootstrap");

  await import("./index.js");
  await pulumi.runtime.disconnect();

  const types = resources.map((resource) => resource.type);

  assert.equal(types.includes("aws:iam/openIdConnectProvider:OpenIdConnectProvider"), true);
  assert.equal(types.includes("aws:iam/role:Role"), true);
  assert.equal(types.includes("aws:iam/rolePolicy:RolePolicy"), true);

  // Bootstrap must not provision application infrastructure — that is infra/src.
  assert.equal(types.includes("aws:s3/bucketV2:BucketV2"), false);
  assert.equal(types.includes("aws:iam/user:User"), false);
  assert.equal(types.includes("aws:iam/accessKey:AccessKey"), false);

  const serialized = JSON.stringify(policyDocuments);

  // The immutable repository prefix and environment suffix, exactly — see trust.ts.
  assert.match(
    serialized,
    /repo:NielsvanHoof@26738111\/qompose@1301716861:environment:production/,
  );
  // The ref form would silently fail to match deploy.yml's OIDC token.
  assert.equal(serialized.includes("ref:refs/heads"), false);
  // A wildcard subject would open the account to every repository on GitHub.
  assert.equal(serialized.includes('"repo:*"'), false);

  const statements = policyDocuments.flatMap(
    (document) => (document.statements ?? []) as Array<Record<string, unknown>>,
  );

  const subjectCondition = statements
    .flatMap((statement) => (statement.conditions ?? []) as Array<Record<string, unknown>>)
    .find((condition) => String(condition.variable).endsWith(":sub"));

  assert.ok(subjectCondition, "trust policy must constrain the sub claim");
  assert.equal(
    subjectCondition.test,
    "StringEquals",
    "sub must be matched exactly; StringLike would allow a wildcard subject",
  );

  const denyStatement = statements.find(
    (statement) => statement.sid === "DenyModifyingOwnAccess",
  );

  const identityStatement = statements.find(
    (statement) => statement.sid === "ManageQomposeIdentities",
  );

  assert.ok(identityStatement, "deploy policy must manage application identities");
  assert.ok(
    (identityStatement.resources as string[]).includes(
      "arn:aws:iam::123456789012:policy/qompose/*",
    ),
    "managed policies under the /qompose/ path must be creatable",
  );

  assert.ok(denyStatement, "deploy policy must deny self-modification");
  assert.equal(denyStatement.effect, "Deny");
  assert.equal(
    (denyStatement.actions as string[]).includes("iam:UpdateAssumeRolePolicy"),
    true,
    "the role must not be able to rewrite its own trust policy",
  );
  assert.equal(
    (denyStatement.actions as string[]).includes("iam:DeleteOpenIDConnectProvider"),
    true,
  );

  /*
   * The deploy policy has to keep pace with what infra/src actually creates.
   * Compute grants (ECS/ELB/edge/cache/secrets) were dropped in TASK-43.3 — assert
   * the data-plane surface here so mid-deploy permission failures stay rare.
   */
  const sids = statements.map((statement) => statement.sid);

  for (const sid of [
    "ManageEncryptionGrantsForAwsResources",
    "ManagePlatformNetwork",
    "ManagePlatformDataStores",
    "ManagePlatformDns",
    "ManagePlatformMail",
    "CreateServiceLinkedRoles",
    "ManageDocumentScanningAndAudit",
    "ManageOperationsAlerting",
  ]) {
    assert.ok(sids.includes(sid), `deploy policy is missing ${sid}`);
  }

  for (const removed of [
    "ManagePlatformLoadBalancing",
    "ManagePlatformCompute",
    "ManagePlatformSecrets",
    "ManagePlatformEdge",
    "ManagePlatformLogs",
  ]) {
    assert.equal(sids.includes(removed), false, `deploy policy must not retain ${removed}`);
  }

  // Service-linked roles are the one iam:* grant outside the qompose namespace, so
  // it must stay pinned to named services rather than becoming a bare wildcard.
  const encryptionKeys = statements.find(
    (statement) => statement.sid === "ManageEncryptionKeys",
  );

  assert.ok(encryptionKeys);
  for (const action of [
    "kms:Encrypt",
    "kms:Decrypt",
    "kms:ReEncrypt*",
    "kms:GenerateDataKey*",
  ]) {
    assert.ok(
      (encryptionKeys.actions as string[]).includes(action),
      `deploy policy is missing ${action} for encrypted service provisioning`,
    );
  }

  const encryptionGrants = statements.find(
    (statement) => statement.sid === "ManageEncryptionGrantsForAwsResources",
  );

  assert.ok(encryptionGrants);
  assert.deepEqual(encryptionGrants.actions, [
    "kms:CreateGrant",
    "kms:RetireGrant",
    "kms:RevokeGrant",
  ]);
  assert.deepEqual(encryptionGrants.conditions, [{
    test: "Bool",
    variable: "kms:GrantIsForAWSResource",
    values: ["true"],
  }]);

  const serviceLinked = statements.find(
    (statement) => statement.sid === "CreateServiceLinkedRoles",
  );

  assert.ok(serviceLinked);

  const serviceNames = (serviceLinked.conditions as Array<Record<string, unknown>>)
    .find((condition) => condition.variable === "iam:AWSServiceName");

  assert.ok(serviceNames, "iam:CreateServiceLinkedRole must name the services it covers");
  assert.equal((serviceNames.values as string[]).includes("*"), false);

  const read = statements.find((statement) => statement.sid === "ReadState");

  assert.ok(read);
  assert.equal((read.actions as string[]).includes("ecr:GetAuthorizationToken"), false);

  // ec2:* would also cover launching instances. This role builds a network, not a fleet.
  const network = statements.find((statement) => statement.sid === "ManagePlatformNetwork");

  assert.ok(network);
  assert.equal((network.actions as string[]).includes("ec2:*"), false);
  assert.equal((network.actions as string[]).includes("ec2:RunInstances"), false);
});