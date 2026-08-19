import * as aws from "@pulumi/aws";
import { PlatformConfig } from "../config.js";
import { DocumentInfrastructure } from "./documents.js";

export interface AuditTrail {
  trail: aws.cloudtrail.Trail;
  logBucket: aws.s3.BucketV2;
}

/**
 * CloudTrail data events for the documents bucket.
 *
 * Management events tell you who changed the bucket's configuration. **Data**
 * events tell you who read an object out of it, which is the question that
 * actually matters for client documents and the one CloudTrail does not answer by
 * default — they are opt-in, and billed per event, which is why they are scoped to
 * this one bucket rather than switched on account-wide.
 *
 * This complements the application's own audit log rather than duplicating it. The
 * activity log records that a staff member clicked download; CloudTrail records
 * that S3 served the bytes. They diverge exactly where it counts: a presigned URL
 * shared onward, or credentials used outside the application, appear here and
 * nowhere else.
 */
export function createAuditTrail(
  config: PlatformConfig,
  documents: DocumentInfrastructure,
): AuditTrail {
  const { environment } = config;
  const account = aws.getCallerIdentityOutput();

  const logBucket = new aws.s3.BucketV2(environment.resourceName("audit-logs"), {
    bucketPrefix: `${environment.resourceName("audit-logs")}-`,
    forceDestroy: !environment.settings.deletionProtection,
  });

  new aws.s3.BucketPublicAccessBlock(environment.resourceName("audit-logs-public-access"), {
    bucket: logBucket.id,
    blockPublicAcls: true,
    blockPublicPolicy: true,
    ignorePublicAcls: true,
    restrictPublicBuckets: true,
  });

  new aws.s3.BucketOwnershipControls(environment.resourceName("audit-logs-ownership"), {
    bucket: logBucket.id,
    rule: { objectOwnership: "BucketOwnerEnforced" },
  });

  /*
   * SSE-S3, not the platform CMK.
   *
   * A customer managed key would mean every reader of the trail needs a grant on
   * it, and — more to the point — an audit log encrypted with a key the audited
   * party controls is weaker evidence than one that is not. CloudTrail also
   * validates its own digest files, which is the integrity property that matters
   * here.
   */
  new aws.s3.BucketServerSideEncryptionConfigurationV2(
    environment.resourceName("audit-logs-encryption"),
    {
      bucket: logBucket.id,
      rules: [{
        applyServerSideEncryptionByDefault: { sseAlgorithm: "AES256" },
        bucketKeyEnabled: true,
      }],
    },
  );

  new aws.s3.BucketLifecycleConfigurationV2(
    environment.resourceName("audit-logs-lifecycle"),
    {
      bucket: logBucket.id,
      rules: [{
        id: "age-out-audit-logs",
        status: "Enabled",
        filter: {},
        // Data events are per-object-access, so this bucket grows with usage
        // rather than with time. Glacier at 90 days keeps a year affordable.
        transitions: [{ days: 90, storageClass: "GLACIER" }],
        expiration: { days: environment.settings.deletionProtection ? 365 : 30 },
        abortIncompleteMultipartUpload: { daysAfterInitiation: 7 },
      }],
    },
  );

  const trailName = environment.resourceName("trail");

  const logBucketPolicy = aws.iam.getPolicyDocumentOutput({
    statements: [{
      sid: "AllowCloudTrailAclCheck",
      effect: "Allow",
      principals: [{ type: "Service", identifiers: ["cloudtrail.amazonaws.com"] }],
      actions: ["s3:GetBucketAcl"],
      resources: [logBucket.arn],
      conditions: [{
        test: "StringEquals",
        variable: "aws:SourceArn",
        values: [
          account.accountId.apply((accountId) =>
            `arn:aws:cloudtrail:${config.region}:${accountId}:trail/${trailName}`
          ),
        ],
      }],
    }, {
      sid: "AllowCloudTrailWrite",
      effect: "Allow",
      principals: [{ type: "Service", identifiers: ["cloudtrail.amazonaws.com"] }],
      actions: ["s3:PutObject"],
      resources: [
        account.accountId.apply((accountId) =>
          logBucket.arn.apply((arn) => `${arn}/AWSLogs/${accountId}/*`)
        ),
      ],
      conditions: [{
        // Required by S3; CloudTrail sends this header and the write is rejected
        // without the matching condition.
        test: "StringEquals",
        variable: "s3:x-amz-acl",
        values: ["bucket-owner-full-control"],
      }],
    }, {
      sid: "DenyInsecureTransport",
      effect: "Deny",
      principals: [{ type: "*", identifiers: ["*"] }],
      actions: ["s3:*"],
      resources: [logBucket.arn, logBucket.arn.apply((arn) => `${arn}/*`)],
      conditions: [{ test: "Bool", variable: "aws:SecureTransport", values: ["false"] }],
    }],
  });

  const bucketPolicy = new aws.s3.BucketPolicy(
    environment.resourceName("audit-logs-policy"),
    { bucket: logBucket.id, policy: logBucketPolicy.json },
  );

  const trail = new aws.cloudtrail.Trail(environment.resourceName("trail"), {
    name: trailName,
    s3BucketName: logBucket.bucket,
    includeGlobalServiceEvents: true,
    isMultiRegionTrail: false,
    // Detects after-the-fact tampering with the delivered log files. The whole
    // value of an audit trail rests on it not being quietly editable.
    enableLogFileValidation: true,

    advancedEventSelectors: [{
      name: "Documents bucket object access",
      fieldSelectors: [{
        field: "eventCategory",
        equals: ["Data"],
      }, {
        field: "resources.type",
        equals: ["AWS::S3::Object"],
      }, {
        // Scoped to this bucket. Account-wide S3 data events would also capture
        // the audit log bucket itself, which makes every read of the trail
        // generate another trail entry.
        field: "resources.ARN",
        startsWiths: [documents.bucket.arn.apply((arn) => `${arn}/`)],
      }],
    }, {
      name: "Management events",
      fieldSelectors: [{ field: "eventCategory", equals: ["Management"] }],
    }],

    tags: { Name: trailName },
  }, {
    // CloudTrail validates it can write to the bucket at creation time.
    dependsOn: [bucketPolicy],
  });

  return { trail, logBucket };
}
