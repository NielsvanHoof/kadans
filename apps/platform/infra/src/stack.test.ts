import assert from "node:assert/strict";
import test from "node:test";
import * as pulumi from "@pulumi/pulumi";

test("the OCR stack provisions documents plumbing and a local IAM user", async () => {
  const resources: Array<{ type: string; name: string; inputs: Record<string, unknown> }> = [];
  // Captured so the assertions below can inspect the requested statements rather
  // than the mocked json, which is identical for every document by construction.
  const policyDocuments: Array<Record<string, unknown>> = [];

  pulumi.runtime.setAllConfig({
    "aws:region": "eu-west-1",
  }, []);

  await pulumi.runtime.setMocks({
    call: (args) => {
      if (args.token.includes("getCallerIdentity")) {
        return { accountId: "123456789012", arn: "arn:aws:iam::123456789012:root", userId: "root" };
      }

      if (args.token.includes("getPolicyDocument")) {
        policyDocuments.push(args.inputs as Record<string, unknown>);

        return { json: JSON.stringify({ Version: "2012-10-17", Statement: [] }) };
      }

      return args.inputs;
    },
    newResource: (args) => {
      const state: Record<string, unknown> = {
        ...args.inputs,
        arn: `arn:aws:test:eu-west-1:123456789012:${args.name}`,
        name: args.inputs.name ?? args.name,
        id: args.inputs.name ?? args.name,
        secret: "test-secret-access-key",
      };

      if (args.type === "aws:s3/bucketV2:BucketV2") {
        state.bucket = `${args.name}-bucket`;
      }

      if (args.type === "aws:sqs/queue:Queue") {
        state.url = `https://sqs.eu-west-1.amazonaws.com/123456789012/${args.name}`;
      }

      if (args.type === "aws:iam/accessKey:AccessKey") {
        state.id = "AKIATESTACCESSKEY1";
        state.secret = "test-secret-access-key";
      }

      resources.push({ type: args.type, name: args.name, inputs: args.inputs });

      return { id: `${args.name}-id`, state };
    },
  }, "qompose-platform", "production");

  await import("./index.js");
  await pulumi.runtime.disconnect();

  const resourceTypes = resources.map((resource) => resource.type);

  assert.equal(resourceTypes.includes("aws:ecs/cluster:Cluster"), false);
  assert.equal(resourceTypes.includes("aws:ecs/service:Service"), false);
  assert.equal(resourceTypes.includes("aws:rds/instance:Instance"), false);
  assert.equal(resourceTypes.includes("aws:ec2/vpc:Vpc"), false);

  assert.equal(resourceTypes.includes("aws:s3/bucketV2:BucketV2"), true);
  assert.equal(resourceTypes.includes("aws:sns/topic:Topic"), true);
  assert.equal(resourceTypes.filter((type) => type === "aws:sqs/queue:Queue").length, 2);
  assert.equal(resourceTypes.includes("aws:iam/user:User"), true);
  assert.equal(resourceTypes.includes("aws:iam/accessKey:AccessKey"), true);
  assert.equal(
    resources.some((resource) => resource.name.includes("textract-publish-role")),
    true,
  );
  assert.equal(
    resources.some((resource) => resource.name.includes("ocr-local-user")),
    true,
  );

  // The ECS task role exists ahead of any compute, so the permission set is
  // provisioned and reviewable before the service that will reference it.
  assert.equal(
    resources.some((resource) => resource.name.includes("app-task-role")),
    true,
  );

  // The local IAM user and the ECS task role must carry byte-identical
  // permissions. If they drift, OCR breaks in production only — the worst place
  // to discover it — so assert the shared document rather than trusting review.
  const applicationPolicies = policyDocuments.filter((document) =>
    JSON.stringify(document).includes("ListDocumentsBucket")
  );

  assert.equal(
    applicationPolicies.length,
    2,
    "expected the application policy to be built twice: local IAM user + ECS task role",
  );
  assert.deepEqual(
    applicationPolicies[0],
    applicationPolicies[1],
    "the local user and the task role must share one policy document",
  );

  // Bedrock is scoped to configured ARNs, never a bare wildcard.
  const bedrockStatement = applicationPolicies[0].statements as Array<Record<string, unknown>>;
  const bedrock = bedrockStatement.find(
    (statement) => statement.sid === "InvokeBedrockOverviewModels",
  );

  assert.ok(bedrock);
  assert.equal((bedrock.resources as string[]).includes("*"), false);
});
