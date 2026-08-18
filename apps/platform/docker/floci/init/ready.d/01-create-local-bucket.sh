#!/bin/sh
# Create the default Sail document bucket once Floci is ready.
# Idempotent: mb fails harmlessly if the bucket already exists.
set -eu

BUCKET="${FLOCI_BOOTSTRAP_BUCKET:-local}"

aws s3 mb "s3://${BUCKET}" 2>/dev/null || true
aws s3 ls "s3://${BUCKET}" >/dev/null
