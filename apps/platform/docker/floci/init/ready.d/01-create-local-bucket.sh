#!/bin/sh
# Create the default Sail document bucket once Floci is ready.
# Idempotent: mb fails harmlessly if the bucket already exists.
set -eu

BUCKET="${FLOCI_BOOTSTRAP_BUCKET:-local}"
AUDIT_BUCKET="${FLOCI_BOOTSTRAP_AUDIT_BUCKET:-local-audit-logs}"
TRAIL="${FLOCI_BOOTSTRAP_TRAIL:-local-documents}"
SES_DOMAIN="${FLOCI_BOOTSTRAP_SES_DOMAIN:-qompose.test}"

aws s3 mb "s3://${BUCKET}" 2>/dev/null || true
aws s3 ls "s3://${BUCKET}" >/dev/null

aws s3 mb "s3://${AUDIT_BUCKET}" 2>/dev/null || true
aws s3 ls "s3://${AUDIT_BUCKET}" >/dev/null

# The browser PUTs straight to this bucket from the Sail origin
# (http://localhost → http://localhost:4566). That is cross-origin, so the
# bucket itself must answer the OPTIONS preflight — the Laravel app's CORS
# settings are never consulted. Without this rule Safari/Chrome return 403
# "Preflight response is not successful" on the signed upload URL.
#
# Keep this in lockstep with infra/src/environments/local.ts
# uploadAllowedOrigins and documents.ts (PUT + content-type only). A wildcard
# origin would let any page that got hold of a presigned URL spend it.
aws s3api put-bucket-cors --bucket "${BUCKET}" --cors-configuration '{
  "CORSRules": [
    {
      "AllowedOrigins": ["http://localhost", "http://127.0.0.1"],
      "AllowedMethods": ["PUT"],
      "AllowedHeaders": ["content-type"],
      "ExposeHeaders": ["ETag"],
      "MaxAgeSeconds": 3600
    }
  ]
}'

# Exercise the same SES API transport used in shipped environments. Floci relays
# accepted messages to Mailpit, while also retaining them at GET /_aws/ses.
aws ses verify-domain-identity --domain "${SES_DOMAIN}" >/dev/null

# Floci supports classic S3 data event selectors. Production uses advanced
# selectors for the equivalent scope; locally this records document object reads
# and writes into an AWS-shaped, gzipped CloudTrail log in AUDIT_BUCKET.
aws cloudtrail create-trail --name "${TRAIL}" --s3-bucket-name "${AUDIT_BUCKET}" >/dev/null 2>&1 || \
  aws cloudtrail update-trail --name "${TRAIL}" --s3-bucket-name "${AUDIT_BUCKET}" >/dev/null
aws cloudtrail put-event-selectors --trail-name "${TRAIL}" --event-selectors "[{
  \"ReadWriteType\": \"All\",
  \"IncludeManagementEvents\": false,
  \"DataResources\": [{
    \"Type\": \"AWS::S3::Object\",
    \"Values\": [\"arn:aws:s3:::${BUCKET}/\"]
  }]
}]" >/dev/null
aws cloudtrail start-logging --name "${TRAIL}" >/dev/null
