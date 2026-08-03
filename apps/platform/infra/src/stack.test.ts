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
    "qompose-platform:alarmEmailAddress": "ops@example.com",
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
  // Textract results + DLQ, Transcribe results + DLQ, EventBridge delivery DLQ.
  assert.equal(resourceTypes.filter((type) => type === "aws:sqs/queue:Queue").length, 5);
  assert.equal(resourceTypes.includes("aws:iam/user:User"), true);
  assert.equal(resourceTypes.includes("aws:iam/accessKey:AccessKey"), true);
  assert.equal(
    resources.some((resource) => resource.name.includes("textract-publish-role")),
    true,
  );
  assert.equal(
    resources.some((resource) => resource.name.includes("transcribe-complete")),
    true,
    "expected an EventBridge rule for Transcribe job completions",
  );
  assert.equal(
    resources.some((resource) => resource.type === "aws:cloudwatch/eventRule:EventRule"),
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

  // One managed policy is built once and attached to both the local IAM user and
  // the ECS task role. If those attachments drift, OCR/Transcribe breaks in
  // production only — so assert the shared document + both attachments.
  const applicationPolicies = policyDocuments.filter((document) =>
    JSON.stringify(document).includes("ListDocumentsBucket")
  );

  assert.equal(
    applicationPolicies.length,
    1,
    "expected the application policy document to be built once as a managed policy",
  );
  assert.equal(
    resources.some((resource) => resource.name.includes("app-access")),
    true,
    "expected a managed app-access policy",
  );
  assert.equal(
    resources.some((resource) =>
      resource.type === "aws:iam/userPolicyAttachment:UserPolicyAttachment"
      && resource.name.includes("ocr-local-policy")
    ),
    true,
  );
  assert.equal(
    resources.some((resource) =>
      resource.type === "aws:iam/rolePolicyAttachment:RolePolicyAttachment"
      && resource.name.includes("app-task-policy")
    ),
    true,
  );

  // Bedrock is scoped to configured ARNs, never a bare wildcard.
  const statements = applicationPolicies[0].statements as Array<Record<string, unknown>>;
  const bedrock = statements.find(
    (statement) => statement.sid === "InvokeBedrockOverviewModels",
  );

  assert.ok(bedrock);
  assert.equal((bedrock.resources as string[]).includes("*"), false);

  // Transcribe completions arrive via EventBridge → SQS.
  assert.ok(statements.find((statement) => statement.sid === "RunAmazonTranscribe"));
  assert.ok(statements.find((statement) => statement.sid === "ConsumeTranscribeResults"));

  // Textract publish role must pin SourceAccount + SourceArn (confused-deputy).
  const textractTrust = policyDocuments.find((document) => {
    const serialized = JSON.stringify(document);

    return serialized.includes("textract.amazonaws.com")
      && serialized.includes("AllowTextractAssumeWithAccountGuard");
  });

  assert.ok(textractTrust, "expected Textract publish-role trust policy document");
  const trustStatements = textractTrust.statements as Array<Record<string, unknown>>;
  const trust = trustStatements[0];
  const conditions = trust.conditions as Array<Record<string, unknown>>;

  assert.ok(
    conditions.some((condition) => condition.variable === "aws:SourceAccount"),
    "expected aws:SourceAccount on Textract assume-role trust",
  );
  assert.ok(
    conditions.some((condition) => condition.variable === "aws:SourceArn"),
    "expected aws:SourceArn on Textract assume-role trust",
  );

  // EventBridge target must use a delivery DLQ for FailedInvocations.
  const eventTarget = resources.find(
    (resource) => resource.type === "aws:cloudwatch/eventTarget:EventTarget",
  );

  assert.ok(eventTarget, "expected Transcribe EventBridge target");
  assert.ok(
    eventTarget.inputs.deadLetterConfig,
    "expected EventBridge target deadLetterConfig",
  );
  assert.equal(
    resources.some((resource) => resource.name.includes("transcribe-delivery-dlq")),
    true,
    "expected EventBridge delivery DLQ queue",
  );

  // Alarm SNS topic must use the OCR CMK (CloudWatch already has key grants).
  const alarmTopic = resources.find(
    (resource) =>
      resource.type === "aws:sns/topic:Topic"
      && resource.name.includes("ocr-alarms"),
  );

  assert.ok(alarmTopic, "expected ocr-alarms SNS topic when alarmEmailAddress is set");
  assert.ok(
    alarmTopic.inputs.kmsMasterKeyId,
    "expected KMS encryption on the alarm SNS topic",
  );
  assert.equal(
    resources.some((resource) => resource.name.includes("transcribe-delivery-dlq-alarm")),
    true,
    "expected CloudWatch alarm on the EventBridge delivery DLQ",
  );
});
