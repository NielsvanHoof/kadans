#!/usr/bin/env bash
# Shared helpers for upserting KEY=VALUE lines into a dotenv file.
#
# Expects ENV_FILE to be set to an absolute path before calling upsert_env_key
# or apply_env_profile.

upsert_env_key() {
  local key="$1"
  local value="$2"
  local tmp

  if [[ -z "${ENV_FILE:-}" ]]; then
    echo "ENV_FILE is not set." >&2
    return 1
  fi

  tmp="$(mktemp)"
  awk -v k="$key" -v v="$value" '
    BEGIN { done=0 }
    index($0, k"=")==1 { print k"="v; done=1; next }
    { print }
    END { if (!done) print k"="v }
  ' "$ENV_FILE" > "$tmp"
  mv "$tmp" "$ENV_FILE"
}

# Apply every KEY=VALUE from a profile file onto ENV_FILE.
# Skips blank lines and comments. Values may be empty (KEY=).
apply_env_profile() {
  local profile_file="$1"
  local line key value

  if [[ ! -f "$profile_file" ]]; then
    echo "Missing profile: $profile_file" >&2
    return 1
  fi

  while IFS= read -r line || [[ -n "$line" ]]; do
    # Trim leading whitespace for comment detection.
    line="${line#"${line%%[![:space:]]*}"}"

    [[ -z "$line" || "$line" == \#* ]] && continue
    [[ "$line" != *=* ]] && continue

    key="${line%%=*}"
    value="${line#*=}"

    upsert_env_key "$key" "$value"
  done < "$profile_file"
}
