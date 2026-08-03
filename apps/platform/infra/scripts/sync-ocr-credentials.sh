#!/usr/bin/env bash
# Pull OCR / Transcribe stack outputs from Pulumi into apps/platform/.env.
#
# Local development only. A process on a laptop is not something AWS can hand a
# role to, so it uses a scoped IAM user instead. The deployed application uses the
# ECS task role (infra/src/application.ts) and carries no keys at all.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLATFORM_ROOT="$(cd "$ROOT/.." && pwd)"
ENV_FILE="${PLATFORM_ENV_FILE:-$PLATFORM_ROOT/.env}"

cd "$ROOT"

if [[ -x "$ROOT/node_modules/.bin/pulumi" ]]; then
  PULUMI="$ROOT/node_modules/.bin/pulumi"
elif command -v pulumi >/dev/null 2>&1; then
  PULUMI="$(command -v pulumi)"
else
  echo "Pulumi CLI not found. Run npm ci in apps/platform/infra first." >&2
  exit 1
fi

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing $ENV_FILE" >&2
  exit 1
fi

stack_output() {
  local name="$1"
  local show_secrets="${2:-}"
  if [[ "$show_secrets" == "--show-secrets" ]]; then
    "$PULUMI" stack output "$name" --show-secrets
  else
    "$PULUMI" stack output "$name"
  fi
}

ACCESS_KEY="$(stack_output ocrAccessKeyId --show-secrets)"
SECRET_KEY="$(stack_output ocrSecretAccessKey --show-secrets)"
BUCKET="$(stack_output documentsBucketName)"
TEXTRACT_TOPIC="$(stack_output textractSnsTopicArn)"
TEXTRACT_ROLE="$(stack_output textractPublishRoleArn)"
TEXTRACT_QUEUE="$(stack_output textractResultsQueueUrl)"
TRANSCRIBE_QUEUE="$(stack_output transcribeResultsQueueUrl)"

ACCESS_KEY="$(printf '%s' "$ACCESS_KEY" | tr -d '\r' | grep -Eo 'AKIA[0-9A-Z]{16}' | head -1)"
SECRET_KEY="$(printf '%s' "$SECRET_KEY" | tr -d '\r' | awk 'NF{print; exit}')"
BUCKET="$(printf '%s' "$BUCKET" | tr -d '\r' | awk 'NF{print; exit}')"
TEXTRACT_TOPIC="$(printf '%s' "$TEXTRACT_TOPIC" | tr -d '\r' | awk 'NF{print; exit}')"
TEXTRACT_ROLE="$(printf '%s' "$TEXTRACT_ROLE" | tr -d '\r' | awk 'NF{print; exit}')"
TEXTRACT_QUEUE="$(printf '%s' "$TEXTRACT_QUEUE" | tr -d '\r' | awk 'NF{print; exit}')"
TRANSCRIBE_QUEUE="$(printf '%s' "$TRANSCRIBE_QUEUE" | tr -d '\r' | awk 'NF{print; exit}')"

if [[ -z "$ACCESS_KEY" || -z "$SECRET_KEY" ]]; then
  echo "Could not read ocrAccessKeyId / ocrSecretAccessKey. Run npm run up first." >&2
  exit 1
fi

if [[ -z "$BUCKET" || -z "$TEXTRACT_TOPIC" || -z "$TRANSCRIBE_QUEUE" ]]; then
  echo "Could not read document / Textract / Transcribe stack outputs. Run npm run up first." >&2
  exit 1
fi

upsert_env() {
  local key="$1"
  local value="$2"
  local tmp
  tmp="$(mktemp)"
  awk -v k="$key" -v v="$value" '
    BEGIN { done=0 }
    index($0, k"=")==1 { print k"="v; done=1; next }
    { print }
    END { if (!done) print k"="v }
  ' "$ENV_FILE" > "$tmp"
  mv "$tmp" "$ENV_FILE"
}

upsert_env AWS_ACCESS_KEY_ID "$ACCESS_KEY"
upsert_env AWS_SECRET_ACCESS_KEY "$SECRET_KEY"
# Long-lived IAM user keys do not use a session token.
upsert_env AWS_SESSION_TOKEN ""

# Real AWS (not MinIO).
upsert_env AWS_ENDPOINT ""
upsert_env AWS_URL ""
upsert_env AWS_USE_PATH_STYLE_ENDPOINT false

upsert_env AWS_BUCKET "$BUCKET"
upsert_env TEXTRACT_SNS_TOPIC_ARN "$TEXTRACT_TOPIC"
upsert_env TEXTRACT_SNS_ROLE_ARN "$TEXTRACT_ROLE"
upsert_env TEXTRACT_RESULTS_QUEUE_URL "$TEXTRACT_QUEUE"
upsert_env TRANSCRIBE_RESULTS_QUEUE_URL "$TRANSCRIBE_QUEUE"

echo "Wrote OCR / Transcribe IAM credentials and stack outputs to $ENV_FILE"
echo "Restart Sail / textract:consume / transcribe:consume so PHP picks up the new env."
