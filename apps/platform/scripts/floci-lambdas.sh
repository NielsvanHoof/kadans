#!/usr/bin/env bash
# Build every function in apps/lambdas and deploy it into the local Floci stack.
#
# Floci emulates Lambda by spawning a container per function over the Docker
# socket, attached to the Sail network (FLOCI_SERVICES_DOCKER_NETWORK in
# compose.yaml), so the function reaches S3 and SQS at http://floci:4566 exactly
# as it would reach real AWS.
#
# What this does NOT emulate is GuardDuty. Floci answers the GuardDuty API, but
# nothing there actually scans an object or emits a verdict, so the one piece of
# the production chain that cannot run locally is the trigger. Everything after
# it is real: the EventBridge rule below carries the same event pattern the
# Pulumi program declares, so `scripts/floci-scan-event.sh` feeds the real rule
# and the rest of the chain behaves as it will in AWS.
#
# Run from the host after `sail up` — building needs the Go toolchain, which the
# containers do not carry. Idempotent: safe to re-run after every rebuild.
set -euo pipefail

PLATFORM_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LAMBDAS_DIR="$(cd "${PLATFORM_DIR}/../lambdas" && pwd)"

ENDPOINT="${FLOCI_ENDPOINT:-http://localhost:4566}"
BUCKET="${FLOCI_BOOTSTRAP_BUCKET:-local}"
ACCOUNT_ID="000000000000"

export AWS_ACCESS_KEY_ID="${AWS_ACCESS_KEY_ID:-sail}"
export AWS_SECRET_ACCESS_KEY="${AWS_SECRET_ACCESS_KEY:-password}"
export AWS_DEFAULT_REGION="${AWS_DEFAULT_REGION:-eu-west-1}"
export AWS_PAGER=""

aws() { command aws --endpoint-url "${ENDPOINT}" "$@"; }

if ! curl -sf "${ENDPOINT}/_localstack/health" >/dev/null; then
  echo "Floci is not reachable at ${ENDPOINT}. Start it with: vendor/bin/sail up -d" >&2
  exit 1
fi

# --- document-scan -----------------------------------------------------------

FUNCTION="local-scan-handler"
ROLE="local-scan-handler"
QUEUE="local-scan-results"
RULE="local-scan-result"

echo "==> Building apps/lambdas/document-scan"
make -C "${LAMBDAS_DIR}/document-scan" build >/dev/null

QUEUE_URL="$(aws sqs create-queue --queue-name "${QUEUE}" --query 'QueueUrl' --output text)"
echo "==> Queue ${QUEUE_URL}"

# Floci does not enforce IAM, so this role exists to satisfy the create-function
# API rather than to grant anything. The real permissions live in
# infra/src/shared/documentScanHandler.ts.
aws iam create-role --role-name "${ROLE}" \
  --assume-role-policy-document '{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Principal":{"Service":"lambda.amazonaws.com"},"Action":"sts:AssumeRole"}]}' \
  >/dev/null 2>&1 || true

ZIP="fileb://${LAMBDAS_DIR}/document-scan/dist/function.zip"
# AWS_ENDPOINT_URL is read natively by the AWS SDK for Go v2, so the function
# needs no local-only code path to talk to Floci instead of AWS.
ENV_VARS="Variables={SCAN_RESULTS_QUEUE_URL=http://floci:4566/${ACCOUNT_ID}/${QUEUE},AWS_ENDPOINT_URL=http://floci:4566}"

if aws lambda get-function --function-name "${FUNCTION}" >/dev/null 2>&1; then
  echo "==> Updating ${FUNCTION}"
  aws lambda update-function-code --function-name "${FUNCTION}" --zip-file "${ZIP}" >/dev/null
  aws lambda wait function-updated --function-name "${FUNCTION}"
  aws lambda update-function-configuration --function-name "${FUNCTION}" --environment "${ENV_VARS}" >/dev/null
  aws lambda wait function-updated --function-name "${FUNCTION}"
else
  echo "==> Creating ${FUNCTION}"
  aws lambda create-function \
    --function-name "${FUNCTION}" \
    --runtime provided.al2023 --handler bootstrap --architectures arm64 \
    --role "arn:aws:iam::${ACCOUNT_ID}:role/${ROLE}" \
    --timeout 60 --memory-size 1024 \
    --zip-file "${ZIP}" \
    --environment "${ENV_VARS}" >/dev/null
  aws lambda wait function-active-v2 --function-name "${FUNCTION}"
fi

# The same event pattern the Pulumi program declares: every scan status, scoped
# to this bucket. Keeping it identical is what makes the local rehearsal worth
# anything — see infra/src/shared/documentScanHandler.ts.
aws events put-rule --name "${RULE}" \
  --event-pattern "{\"source\":[\"aws.guardduty\"],\"detail-type\":[\"GuardDuty Malware Protection Object Scan Result\"],\"detail\":{\"s3ObjectDetails\":{\"bucketName\":[\"${BUCKET}\"]}}}" \
  >/dev/null
aws lambda add-permission --function-name "${FUNCTION}" --statement-id "${RULE}" \
  --action lambda:InvokeFunction --principal events.amazonaws.com >/dev/null 2>&1 || true
aws events put-targets --rule "${RULE}" \
  --targets "Id=scan-handler,Arn=arn:aws:lambda:${AWS_DEFAULT_REGION}:${ACCOUNT_ID}:function:${FUNCTION}" \
  >/dev/null

cat <<EOF

Deployed. Add to .env if not already there:

  SCAN_RESULTS_QUEUE_URL=http://floci:4566/${ACCOUNT_ID}/${QUEUE}
  SCAN_THUMBNAILS_ENABLED=true

Then restart \`composer run dev\` so scan:consume starts and Octane re-reads .env.

GuardDuty does not scan anything locally, so nothing will fire on its own:

  ./scripts/floci-scan-event.sh <object-key>

EOF
