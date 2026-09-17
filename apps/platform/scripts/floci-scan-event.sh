#!/usr/bin/env bash
# Emit a GuardDuty malware scan verdict for a stored object, locally.
#
# This exists because Floci answers the GuardDuty API but never actually scans
# an object, so the one link in the production chain that cannot run locally is
# the trigger. Everything downstream is real: this puts a genuine event on the
# default EventBridge bus, where the same rule the Pulumi program declares
# matches it and invokes the real function.
#
# Usage:
#   ./scripts/floci-scan-event.sh tenants/1/dossiers/1/01JBQ....pdf
#   ./scripts/floci-scan-event.sh tenants/1/dossiers/1/01JBQ....pdf THREATS_FOUND
#
# Statuses: NO_THREATS_FOUND (default), THREATS_FOUND, UNSUPPORTED,
#           ACCESS_DENIED, FAILED.
set -euo pipefail

OBJECT_KEY="${1:-}"
SCAN_STATUS="${2:-NO_THREATS_FOUND}"

if [ -z "${OBJECT_KEY}" ]; then
  echo "Usage: $0 <object-key> [scan-status]" >&2
  echo "Find a key with: select path from uploaded_documents;" >&2
  exit 1
fi

ENDPOINT="${FLOCI_ENDPOINT:-http://localhost:4566}"
BUCKET="${FLOCI_BOOTSTRAP_BUCKET:-local}"

export AWS_ACCESS_KEY_ID="${AWS_ACCESS_KEY_ID:-sail}"
export AWS_SECRET_ACCESS_KEY="${AWS_SECRET_ACCESS_KEY:-password}"
export AWS_DEFAULT_REGION="${AWS_DEFAULT_REGION:-eu-west-1}"
export AWS_PAGER=""

# A scan that ran and found nothing is COMPLETED + NO_THREATS_FOUND; the states
# where GuardDuty could not answer arrive as SKIPPED or FAILED with no result.
# See statusFrom() in apps/lambdas/document-scan/internal/scanevent.
case "${SCAN_STATUS}" in
  NO_THREATS_FOUND|THREATS_FOUND) OUTER="COMPLETED" ;;
  UNSUPPORTED)                    OUTER="SKIPPED" ;;
  *)                              OUTER="FAILED" ;;
esac

DETAIL=$(OBJECT_KEY="${OBJECT_KEY}" BUCKET="${BUCKET}" OUTER="${OUTER}" SCAN_STATUS="${SCAN_STATUS}" python3 - <<'PY'
import json, os

print(json.dumps({
    "schemaVersion": "1.0",
    "scanStatus": os.environ["OUTER"],
    "resourceType": "S3_OBJECT",
    "s3ObjectDetails": {
        "bucketName": os.environ["BUCKET"],
        "objectKey": os.environ["OBJECT_KEY"],
        "versionId": "null",
    },
    "scanResultDetails": {"scanResultStatus": os.environ["SCAN_STATUS"]},
}))
PY
)

ENTRIES=$(DETAIL="${DETAIL}" python3 - <<'PY'
import json, os

print(json.dumps([{
    "Source": "aws.guardduty",
    "DetailType": "GuardDuty Malware Protection Object Scan Result",
    "Detail": os.environ["DETAIL"],
}]))
PY
)

FAILED=$(aws --endpoint-url "${ENDPOINT}" events put-events \
  --entries "${ENTRIES}" --query 'FailedEntryCount' --output text)

if [ "${FAILED}" != "0" ]; then
  echo "EventBridge rejected the event." >&2
  exit 1
fi

echo "Sent ${SCAN_STATUS} for ${OBJECT_KEY}."
echo "The function renders on a clean verdict only; scan:consume applies all of them."
