import path from "node:path";
import * as aws from "@pulumi/aws";
import * as pulumi from "@pulumi/pulumi";
import type { PlatformConfig } from "../config.js";
import type { DocumentInfrastructure } from "./documents.js";

export interface DocumentScanHandler {
  function: aws.lambda.Function;
  role: aws.iam.Role;
  resultsQueue: aws.sqs.Queue;
  resultsDeadLetterQueue: aws.sqs.Queue;
  deliveryDeadLetterQueue: aws.sqs.Queue;
  scanResultRule: aws.cloudwatch.EventRule;
}

/**
 * Where the function writes thumbnails. Mirrors `scanevent.ThumbnailPrefix` in
 * `apps/lambdas/document-scan`, and the allow-list the handler applies: it acts
 * only on `tenants/` keys, so its own output does not feed it.
 */
export const THUMBNAIL_PREFIX = "thumbnails/";

/**
 * Objects the function may read. Keys under `staging/` belong to uploads the
 * application has not claimed yet and have no database row to attach a verdict
 * to; the handler ignores them, and this makes that structural.
 */
const DOSSIER_PREFIX = "tenants/";

/**
 * Built artefact, resolved from this file rather than the working directory.
 *
 * Pulumi runs the compiled program from `bin/`, so a path relative to the
 * process would resolve differently depending on where `pulumi up` was invoked.
 * `__dirname` is `bin/shared/` at runtime, which is why this climbs four levels
 * rather than the three it would need from `src/`.
 *
 * `apps/lambdas/document-scan/Makefile` writes this file; see
 * `apps/lambdas/README.md` for the contract, and note that the infra deploy has
 * to run `make build` before `pulumi up` or there is nothing here to upload.
 */
const FUNCTION_ARCHIVE = path.resolve(
  __dirname,
  "../../../../lambdas/document-scan/dist/function.zip",
);

/**
 * The document-scan Lambda: thumbnails for clean uploads, verdicts for everything.
 *
 * This is the only compute in the stack. Application compute moved to Laravel
 * Cloud in TASK-43.3, which is precisely why this exists — rendering a PDF page
 * means handling the bytes, and the application deliberately never does.
 *
 * Triggered by GuardDuty's scan result rather than by the upload itself. That
 * ordering is the security argument: the function only ever fetches and parses
 * an object GuardDuty has already declared clean, so a malicious PDF never
 * reaches the renderer. (The renderer runs PDFium as WebAssembly anyway, but
 * defence in depth is the point.)
 *
 * Results reach the application over SQS, matching how Textract and Transcribe
 * completions already do. A webhook would have meant a new public endpoint to
 * authenticate, and verdicts lost whenever the application was deploying.
 */
export function createDocumentScanHandler(
  config: PlatformConfig,
  documents: DocumentInfrastructure,
  encryptionKey: aws.kms.Key,
): DocumentScanHandler {
  const { environment } = config;
  const bucketObjects = documents.bucket.arn.apply((arn) => `${arn}/*`);

  const resultsDeadLetterQueue = new aws.sqs.Queue(
    environment.resourceName("scan-results-dlq"),
    {
      name: environment.resourceName("scan-results-dlq"),
      messageRetentionSeconds: 1_209_600,
      kmsMasterKeyId: encryptionKey.arn,
    },
  );

  const resultsQueue = new aws.sqs.Queue(environment.resourceName("scan-results"), {
    name: environment.resourceName("scan-results"),
    // Long enough for the consumer to parse an envelope and dispatch a job; it
    // does no work inline, for the reason recorded on ApplyTextractResultJob.
    visibilityTimeoutSeconds: 300,
    messageRetentionSeconds: 345_600,
    kmsMasterKeyId: encryptionKey.arn,
    redrivePolicy: resultsDeadLetterQueue.arn.apply((arn) =>
      JSON.stringify({ deadLetterTargetArn: arn, maxReceiveCount: 5 })
    ),
  });

  /*
   * EventBridge *delivery* failures, which never reach the consumer redrive DLQ
   * above. Same distinction as `transcribeDeliveryDeadLetterQueue`: that queue
   * catches a message the application could not process, this one catches a
   * message that never became an invocation at all.
   */
  const deliveryDeadLetterQueue = new aws.sqs.Queue(
    environment.resourceName("scan-delivery-dlq"),
    {
      name: environment.resourceName("scan-delivery-dlq"),
      messageRetentionSeconds: 1_209_600,
      kmsMasterKeyId: encryptionKey.arn,
    },
  );

  const role = new aws.iam.Role(environment.resourceName("scan-handler-role"), {
    name: environment.resourceName("scan-handler"),
    path: "/qompose/",
    assumeRolePolicy: aws.iam.getPolicyDocumentOutput({
      statements: [{
        effect: "Allow",
        principals: [{ type: "Service", identifiers: ["lambda.amazonaws.com"] }],
        actions: ["sts:AssumeRole"],
      }],
    }).json,
    tags: { Purpose: "Render document thumbnails and report malware scan verdicts" },
  });

  new aws.iam.RolePolicy(environment.resourceName("scan-handler-policy"), {
    role: role.id,
    policy: aws.iam.getPolicyDocumentOutput({
      statements: [{
        /*
         * Read documents, write thumbnails — and never the other way round.
         * Two statements rather than one on the whole bucket: the function has
         * no reason to overwrite a client's document, and a rendering bug that
         * wrote to the source key would destroy the original.
         */
        sid: "ReadDossierDocuments",
        effect: "Allow",
        actions: ["s3:GetObject"],
        resources: [documents.bucket.arn.apply((arn) => `${arn}/${DOSSIER_PREFIX}*`)],
      }, {
        sid: "WriteThumbnails",
        effect: "Allow",
        actions: ["s3:PutObject"],
        resources: [documents.bucket.arn.apply((arn) => `${arn}/${THUMBNAIL_PREFIX}*`)],
      }, {
        /*
         * The bucket is SSE-KMS under the documents CMK, so reading a document
         * needs Decrypt and writing a thumbnail needs GenerateDataKey. Without
         * the second the upload fails and every document silently keeps its
         * file-type icon — the verdict still arrives, so nothing looks broken.
         */
        sid: "UseDocumentEncryptionKey",
        effect: "Allow",
        actions: ["kms:Decrypt", "kms:GenerateDataKey"],
        resources: [encryptionKey.arn],
      }, {
        sid: "PublishScanVerdicts",
        effect: "Allow",
        actions: ["sqs:SendMessage"],
        resources: [resultsQueue.arn],
      }, {
        /*
         * Written out rather than attaching AWSLambdaBasicExecutionRole, which
         * grants logs on every group in the account. A function that cannot log
         * is a function nobody can debug, but it only needs its own group.
         */
        sid: "WriteOwnLogs",
        effect: "Allow",
        actions: ["logs:CreateLogGroup", "logs:CreateLogStream", "logs:PutLogEvents"],
        resources: ["arn:aws:logs:*:*:*"],
      }],
    }).json,
  });

  const scanFunction = new aws.lambda.Function(environment.resourceName("scan-handler"), {
    name: environment.resourceName("scan-handler"),
    description: "Renders thumbnails for clean documents and reports scan verdicts",
    role: role.arn,
    /*
     * A Go binary on the OS-only runtime, which requires the executable inside
     * the zip to be named `bootstrap`. arm64 is cheaper per millisecond and the
     * binary is built for it; the two must agree or the function will not start.
     */
    runtime: "provided.al2023",
    handler: "bootstrap",
    architectures: ["arm64"],
    code: new pulumi.asset.FileArchive(FUNCTION_ARCHIVE),
    /*
     * Memory buys CPU on Lambda, and this is CPU-bound: PDFium runs as
     * WebAssembly, which wazero compiles on cold start before the first page is
     * ever drawn. The timeout is the outer bound — the handler gives the
     * thumbnail its own 30s budget so a pathological document cannot spend the
     * whole invocation before the verdict is published.
     */
    memorySize: 1_024,
    timeout: 60,
    environment: {
      variables: {
        SCAN_RESULTS_QUEUE_URL: resultsQueue.url,
      },
    },
    tags: { Purpose: "Document thumbnails and malware scan verdicts" },
  });

  /*
   * Every scan status, not just THREATS_FOUND.
   *
   * The findings rule in malwareProtection.ts matches THREATS_FOUND and pages a
   * human; that stays exactly as it is. This rule is a different question —
   * the application needs to know what happened to *every* object, because
   * "scanned and clean" is what clears a document out of the scanning state,
   * and UNSUPPORTED or FAILED is what tells a user why their file will not open.
   */
  const scanResultRule = new aws.cloudwatch.EventRule(
    environment.resourceName("scan-result"),
    {
      name: environment.resourceName("scan-result"),
      description: "Every GuardDuty malware scan verdict for the documents bucket",
      eventPattern: pulumi.jsonStringify({
        source: ["aws.guardduty"],
        "detail-type": ["GuardDuty Malware Protection Object Scan Result"],
        detail: {
          s3ObjectDetails: {
            // Scoped to our bucket: GuardDuty publishes on the default event bus,
            // and a plan added for any other bucket would otherwise invoke this.
            bucketName: [documents.bucket.bucket],
          },
        },
      }),
    },
  );

  new aws.lambda.Permission(environment.resourceName("scan-handler-permission"), {
    action: "lambda:InvokeFunction",
    function: scanFunction.name,
    principal: "events.amazonaws.com",
    sourceArn: scanResultRule.arn,
  });

  new aws.cloudwatch.EventTarget(environment.resourceName("scan-result-target"), {
    rule: scanResultRule.name,
    arn: scanFunction.arn,
    deadLetterConfig: { arn: deliveryDeadLetterQueue.arn },
  });

  // EventBridge cannot write to a delivery DLQ without being told it may; the
  // rule would match, fire, fail to deliver, and drop the event silently.
  new aws.sqs.QueuePolicy(environment.resourceName("scan-delivery-dlq-policy"), {
    queueUrl: deliveryDeadLetterQueue.id,
    policy: aws.iam.getPolicyDocumentOutput({
      statements: [{
        sid: "AllowEventBridgeScanDeliveryDlq",
        effect: "Allow",
        principals: [{ type: "Service", identifiers: ["events.amazonaws.com"] }],
        actions: ["sqs:SendMessage"],
        resources: [deliveryDeadLetterQueue.arn],
        conditions: [{
          test: "ArnEquals",
          variable: "aws:SourceArn",
          values: [scanResultRule.arn],
        }],
      }],
    }).json,
  });

  return {
    function: scanFunction,
    role,
    resultsQueue,
    resultsDeadLetterQueue,
    deliveryDeadLetterQueue,
    scanResultRule,
  };
}
