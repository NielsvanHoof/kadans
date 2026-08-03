#!/usr/bin/env bash
# One-time bootstrap for the AWS MCP server: creates a dedicated IAM user
# instead of handing MCP your root credentials.
#
# Root works for this script because IAM user/policy management is an
# account-admin action root already has — but root should never be the
# credentials MCP itself runs with day to day (see infra/README.md). Run this
# once with your root (or admin) credentials active, then point your MCP
# client at the "qompose-mcp" AWS CLI profile this script writes.
set -euo pipefail

if ! command -v aws >/dev/null 2>&1; then
  echo "AWS CLI not found. Install it first: https://docs.aws.amazon.com/cli/" >&2
  exit 1
fi

USER_NAME="${MCP_IAM_USER_NAME:-mcp}"
USER_PATH="${MCP_IAM_PATH:-/qompose/}"
POLICY_ARN="${MCP_POLICY_ARN:-arn:aws:iam::aws:policy/AWSMCPSignInOAuthAccessPolicy}"
PROFILE_NAME="${MCP_AWS_PROFILE:-qompose-mcp}"
REGION="${MCP_AWS_REGION:-eu-west-1}"

CALLER_ARN="$(aws sts get-caller-identity --query Arn --output text)"
echo "Bootstrapping as: $CALLER_ARN"

if ! aws iam get-policy --policy-arn "$POLICY_ARN" >/dev/null 2>&1; then
  echo "Policy $POLICY_ARN does not exist or isn't visible to this account." >&2
  echo "Double-check the ARN before rerunning." >&2
  exit 1
fi

if aws iam get-user --user-name "$USER_NAME" >/dev/null 2>&1; then
  echo "IAM user $USER_NAME already exists, reusing it."
else
  aws iam create-user --user-name "$USER_NAME" --path "$USER_PATH" >/dev/null
  echo "Created IAM user $USER_NAME (path $USER_PATH)."
fi

aws iam attach-user-policy --user-name "$USER_NAME" --policy-arn "$POLICY_ARN"
echo "Attached $POLICY_ARN to $USER_NAME."

EXISTING_KEYS="$(aws iam list-access-keys --user-name "$USER_NAME" --query 'AccessKeyMetadata[].AccessKeyId' --output text)"

if [[ -n "$EXISTING_KEYS" ]]; then
  echo "$USER_NAME already has an access key ($EXISTING_KEYS)." >&2
  echo "AWS never returns a secret key after creation, so nothing to write." >&2
  echo "Reuse the credentials you already saved, or run:" >&2
  echo "  aws iam create-access-key --user-name $USER_NAME" >&2
  echo "then set them in the '$PROFILE_NAME' profile yourself." >&2
  exit 0
fi

CREDENTIALS_JSON="$(aws iam create-access-key --user-name "$USER_NAME")"
ACCESS_KEY_ID="$(echo "$CREDENTIALS_JSON" | grep -o '"AccessKeyId": *"[^"]*"' | cut -d'"' -f4)"
SECRET_ACCESS_KEY="$(echo "$CREDENTIALS_JSON" | grep -o '"SecretAccessKey": *"[^"]*"' | cut -d'"' -f4)"

aws configure set aws_access_key_id "$ACCESS_KEY_ID" --profile "$PROFILE_NAME"
aws configure set aws_secret_access_key "$SECRET_ACCESS_KEY" --profile "$PROFILE_NAME"
aws configure set region "$REGION" --profile "$PROFILE_NAME"

echo "Wrote a new access key to the '$PROFILE_NAME' profile in ~/.aws/credentials."
echo "Point your AWS MCP config at --profile $PROFILE_NAME instead of root."
echo "Once confirmed working, remove any root access keys you have lying around."
