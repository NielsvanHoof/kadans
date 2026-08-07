#!/usr/bin/env bash
# Apply an AWS storage profile overlay onto apps/platform/.env.
#
# Usage:
#   scripts/use-aws-profile.sh floci
#   scripts/use-aws-profile.sh aws
#
# Profiles live in env/profiles/<name>.env. The aws profile is gitignored and
# written by infra/scripts/sync-ocr-credentials.sh (Pulumi outputs + IAM keys).
#
# Flags:
#   --apply-only   Never invoke sync; fail if the profile file is missing.
#                  Used by sync-ocr-credentials.sh after it writes aws.env.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLATFORM_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=lib/env-profile.sh
source "$SCRIPT_DIR/lib/env-profile.sh"

ENV_FILE="${PLATFORM_ENV_FILE:-$PLATFORM_ROOT/.env}"
PROFILES_DIR="$PLATFORM_ROOT/env/profiles"
APPLY_ONLY=0
PROFILE=""

usage() {
  echo "Usage: $0 [--apply-only] <floci|aws>" >&2
  exit 1
}

for arg in "$@"; do
  case "$arg" in
    --apply-only) APPLY_ONLY=1 ;;
    floci|aws) PROFILE="$arg" ;;
    -h|--help) usage ;;
    *)
      echo "Unknown argument: $arg" >&2
      usage
      ;;
  esac
done

[[ -n "$PROFILE" ]] || usage

PROFILE_FILE="$PROFILES_DIR/${PROFILE}.env"
SYNC_SCRIPT="$PLATFORM_ROOT/infra/scripts/sync-ocr-credentials.sh"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing $ENV_FILE" >&2
  exit 1
fi

# Pulumi + stack credentials live on the host. Sail has neither.
if [[ "$PROFILE" == "aws" && ! -f "$PROFILE_FILE" && "${LARAVEL_SAIL:-0}" == "1" ]]; then
  echo "No cached aws profile, and Pulumi cannot run inside Sail." >&2
  echo "On the host (not sail):" >&2
  echo "  composer env:aws" >&2
  echo "Or:" >&2
  echo "  cd infra && ./scripts/sync-ocr-credentials.sh" >&2
  exit 1
fi

if [[ "$PROFILE" == "aws" && ! -f "$PROFILE_FILE" ]]; then
  if [[ "$APPLY_ONLY" -eq 1 ]]; then
    echo "Missing cached AWS profile at $PROFILE_FILE" >&2
    exit 1
  fi

  echo "No cached aws profile — running sync-ocr-credentials.sh…"
  exec "$SYNC_SCRIPT"
fi

if [[ ! -f "$PROFILE_FILE" ]]; then
  echo "Missing profile: $PROFILE_FILE" >&2
  exit 1
fi

apply_env_profile "$PROFILE_FILE"

echo "Applied AWS profile '$PROFILE' from $PROFILE_FILE onto $ENV_FILE"
echo "Reload PHP config so Octane/Sail pick it up:"
echo "  vendor/bin/sail artisan config:clear"
echo "  # restart vendor/bin/sail artisan dev if it is already running"
