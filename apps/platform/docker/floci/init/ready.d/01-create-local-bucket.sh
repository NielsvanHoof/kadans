#!/bin/sh
# Create the default Sail document bucket once Floci is ready.
# Idempotent: mb fails harmlessly if the bucket already exists.
set -eu

BUCKET="${FLOCI_BOOTSTRAP_BUCKET:-local}"

aws s3 mb "s3://${BUCKET}" 2>/dev/null || true
aws s3 ls "s3://${BUCKET}" >/dev/null

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
