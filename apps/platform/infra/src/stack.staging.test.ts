import assert from "node:assert/strict";
import test, { before } from "node:test";
import type { CapturedStack} from "./testing/harness.js";
import { runStack } from "./testing/harness.js";

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
  assert.ok(stack.named("textract-publish-role"));
  assert.ok(stack.named("transcribe-complete"));
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

test("staging documents bucket allows Laravel Cloud browser uploads", () => {
  const cors = stack.named("documents-cors");
  assert.ok(cors);

  const rules = cors.inputs.corsRules as Array<{
    allowedMethods: string[];
    allowedOrigins: string[];
    allowedHeaders: string[];
  }>;

  assert.equal(rules.length, 1);
  assert.deepEqual(rules[0].allowedMethods, ["PUT"]);
  assert.ok(rules[0].allowedOrigins.includes("https://app.staging.qompose.nl"));
  assert.ok(rules[0].allowedOrigins.includes("https://*.staging.qompose.nl"));
  assert.ok(rules[0].allowedOrigins.includes("https://*.laravel.cloud"));
  assert.deepEqual(rules[0].allowedHeaders, ["content-type"]);
});

test("staging mints its own application IAM user for Laravel Cloud", () => {
  assert.equal(stack.hasType("aws:iam/user:User"), true);
  assert.equal(stack.hasType("aws:iam/accessKey:AccessKey"), true);
  assert.ok(stack.named("ocr-local-user"));
});

test("the staging operator user carries both the app-access and SES send policies", () => {
  const attachments = stack.ofType(
    "aws:iam/userPolicyAttachment:UserPolicyAttachment",
  );

  assert.equal(attachments.length, 2, "expected app-access and SES send attachments");
  assert.ok(stack.named("ocr-local-policy"), "app-access policy is not attached");
  assert.ok(stack.named("ocr-local-mail"), "SES send policy is not attached");

  const user = stack.named("ocr-local-user");
  assert.ok(user);

  const users = new Set(attachments.map((a) => String(a.inputs.user)));
  assert.equal(users.size, 1, "attachments are split across identities");
});

test("the OCR CMK grants Textract the same way it grants Transcribe", () => {
  const key = stack.named("ocr-key");

  assert.ok(key);

  const serialized = String(key.inputs.policy);

  assert.ok(serialized.includes("textract.amazonaws.com"));
  assert.ok(serialized.includes("AllowAmazonTextractToUseKey"));
});

/*
 * The document scan handler (TASK-62.2): the only compute in this stack, and the
 * thing that gives the application a queryable scan state instead of a tag it
 * can read only at download time.
 */

/** A named statement out of a captured policy document, or a failed assertion. */
function statement(
  document: Record<string, unknown>,
  sid: string,
): Record<string, any> {
  const statements = document.statements as Array<Record<string, any>>;
  const found = statements.find((candidate) => candidate.sid === sid);

  assert.ok(found, `policy document has no statement "${sid}"`);

  return found;
}

test("staging runs the document scan handler as a Go function on the OS-only runtime", () => {
  const scanFunction = stack.named("scan-handler");

  assert.ok(scanFunction);
  assert.equal(scanFunction.inputs.runtime, "provided.al2023");
  // provided.al2023 requires the executable in the zip to be named bootstrap,
  // and the binary is built for arm64 — the two have to agree or it will not start.
  assert.equal(scanFunction.inputs.handler, "bootstrap");
  assert.deepEqual(scanFunction.inputs.architectures, ["arm64"]);
  assert.equal(scanFunction.inputs.memorySize, 1_024);
  assert.equal(scanFunction.inputs.timeout, 60);
});

test("the scan handler is told where to publish verdicts", () => {
  const scanFunction = stack.named("scan-handler");

  assert.ok(scanFunction);

  const variables = (scanFunction.inputs.environment as { variables: Record<string, unknown> })
    .variables;

  // Without this the function refuses to start rather than silently dropping
  // every verdict — see build() in apps/lambdas/document-scan/main.go.
  assert.ok(variables.SCAN_RESULTS_QUEUE_URL);
});

test("the scan rule matches every verdict and only this bucket", () => {
  const rule = stack.named("scan-result");

  assert.ok(rule);

  const pattern = JSON.parse(rule.inputs.eventPattern as string) as {
    "detail-type": string[];
    detail: Record<string, unknown>;
  };

  assert.deepEqual(pattern["detail-type"], ["GuardDuty Malware Protection Object Scan Result"]);
  /*
   * No scanResultStatus filter, unlike the malware-findings rule. The
   * application needs every verdict: NO_THREATS_FOUND is what clears a document
   * out of the scanning state, and UNSUPPORTED or FAILED is what explains to a
   * user why their file will not open.
   */
  assert.equal("scanResultDetails" in pattern.detail, false);
  assert.ok("s3ObjectDetails" in pattern.detail);
});

test("the findings rule that pages a human is left alone", () => {
  const findings = stack.named("malware-findings");

  assert.ok(findings);

  const pattern = JSON.parse(findings.inputs.eventPattern as string) as {
    detail: { scanResultDetails: { scanResultStatus: string[] } };
  };

  // Operator alerting and application state are separate concerns; widening this
  // rule would page on-call for every clean upload.
  assert.deepEqual(pattern.detail.scanResultDetails.scanResultStatus, ["THREATS_FOUND"]);
});

test("scan events that cannot be delivered land on a dead-letter queue", () => {
  const target = stack.named("scan-result-target");

  assert.ok(target);
  assert.ok((target.inputs.deadLetterConfig as { arn: unknown }).arn);
  // EventBridge cannot write to that queue without being told it may; the rule
  // would match, fire, and drop the event silently.
  assert.ok(stack.named("scan-delivery-dlq-policy"));
  assert.ok(stack.named("scan-handler-permission"));
});

test("scan verdicts the application cannot apply are redriven, not lost", () => {
  const queue = stack.named("scan-results");

  assert.ok(queue);

  const redrive = JSON.parse(queue.inputs.redrivePolicy as string) as { maxReceiveCount: number };

  assert.equal(redrive.maxReceiveCount, 5);
  assert.ok(stack.named("scan-results-dlq"));
  assert.ok(stack.named("scan-results-dlq-alarm"));
  assert.ok(stack.named("scan-delivery-dlq-alarm"));
});

test("the scan handler reads documents and writes thumbnails, never the reverse", () => {
  const document = stack.policyDocuments.find((policyDocument) =>
    JSON.stringify(policyDocument).includes("WriteThumbnails")
  );

  assert.ok(document);

  const read = statement(document, "ReadDossierDocuments");
  const write = statement(document, "WriteThumbnails");

  assert.deepEqual(read.actions, ["s3:GetObject"]);
  assert.deepEqual(write.actions, ["s3:PutObject"]);
  /*
   * A rendering bug that wrote to the source key would destroy a client's
   * original document, so the write grant stops at the thumbnail prefix.
   */
  assert.equal(JSON.stringify(write.resources).includes("thumbnails/"), true);
  assert.equal(JSON.stringify(read.resources).includes("tenants/"), true);
});

test("the application can consume verdicts and read thumbnails but not write them", () => {
  const document = stack.policyDocuments.find((policyDocument) =>
    JSON.stringify(policyDocument).includes("ReadDocumentThumbnails")
  );

  assert.ok(document);

  assert.ok(statement(document, "ConsumeScanResults").actions.includes("sqs:ReceiveMessage"));
  assert.deepEqual(statement(document, "ReadDocumentThumbnails").actions, ["s3:GetObject"]);

  // Thumbnails are the Lambda's output; the application serves them and nothing more.
  const readWrite = statement(document, "ReadWriteDocuments");
  assert.equal(JSON.stringify(readWrite.resources).includes("thumbnails/"), false);
});

test("thumbnails are covered by the bucket lifecycle without a rule of their own", () => {
  const lifecycle = stack.named("documents-lifecycle");

  assert.ok(lifecycle);

  const rules = lifecycle.inputs.rules as Array<{ id: string; filter: Record<string, unknown> }>;
  const noncurrent = rules.find((rule) => rule.id === "expire-noncurrent-versions");

  assert.ok(noncurrent);
  // An empty filter is bucket-wide, so superseded thumbnails expire on the same
  // schedule as everything else. A second rule would only be a second thing to
  // keep in step.
  assert.deepEqual(noncurrent.filter, {});
});
