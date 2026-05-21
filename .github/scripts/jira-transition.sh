#!/usr/bin/env bash
#
# Move every VAULT-* ticket referenced in $SOURCE_TEXT to a target status.
#
# Scans $SOURCE_TEXT for issue keys (VAULT-123), then for each one asks Jira
# which transition lands on the requested status *from the issue's current
# state* and fires it. The per-issue lookup means we never hard-code a
# transition id, and the workflow itself guards against backward moves: if no
# transition from the current status reaches the target (e.g. an already
# "Released" ticket would never have a path back to "Beta Testing"), the issue
# is skipped instead of failing the run.
#
# Required environment:
#   JIRA_BASE_URL    e.g. https://vaultkeeper.atlassian.net (no trailing slash)
#   JIRA_USER_EMAIL  Atlassian account email for the API token
#   JIRA_API_TOKEN   API token from id.atlassian.com (Basic auth)
#   SOURCE_TEXT      blob (PR title/body/branch/commits) to scan for keys
#
# Usage: jira-transition.sh "<target status name>"

set -euo pipefail

TARGET_STATUS="${1:?usage: jira-transition.sh <target-status>}"

: "${JIRA_BASE_URL:?JIRA_BASE_URL is required}"
: "${JIRA_USER_EMAIL:?JIRA_USER_EMAIL is required}"
: "${JIRA_API_TOKEN:?JIRA_API_TOKEN is required}"
: "${SOURCE_TEXT:?SOURCE_TEXT is required}"

BASE_URL="${JIRA_BASE_URL%/}"
AUTH="${JIRA_USER_EMAIL}:${JIRA_API_TOKEN}"

mapfile -t KEYS < <(printf '%s' "$SOURCE_TEXT" | grep -oE 'VAULT-[0-9]+' | sort -u)

if [ "${#KEYS[@]}" -eq 0 ]; then
  echo "No VAULT-* issue keys found in source text; nothing to do."
  exit 0
fi

echo "Found keys: ${KEYS[*]}"
echo "Target status: ${TARGET_STATUS}"

fail=0
for key in "${KEYS[@]}"; do
  echo "::group::${key}"

  transitions_json="$(curl -fsS -u "$AUTH" -H 'Accept: application/json' \
    "${BASE_URL}/rest/api/3/issue/${key}/transitions" 2>/dev/null || true)"

  if [ -z "$transitions_json" ]; then
    echo "Could not read transitions for ${key} (missing issue or auth?); skipping."
    echo "::endgroup::"
    continue
  fi

  tid="$(printf '%s' "$transitions_json" \
    | jq -r --arg s "$TARGET_STATUS" 'first(.transitions[]? | select(.to.name==$s) | .id) // empty')"

  if [ -z "$tid" ]; then
    cur="$(curl -fsS -u "$AUTH" -H 'Accept: application/json' \
      "${BASE_URL}/rest/api/3/issue/${key}?fields=status" 2>/dev/null \
      | jq -r '.fields.status.name // "unknown"')"
    echo "No transition to '${TARGET_STATUS}' available for ${key} (current: ${cur}); skipping."
    echo "::endgroup::"
    continue
  fi

  code="$(curl -sS -u "$AUTH" -o /tmp/jira-resp.json -w '%{http_code}' \
    -X POST -H 'Content-Type: application/json' \
    "${BASE_URL}/rest/api/3/issue/${key}/transitions" \
    -d "{\"transition\":{\"id\":\"${tid}\"}}")"

  if [ "$code" = "204" ]; then
    echo "Moved ${key} → ${TARGET_STATUS}."
  else
    echo "Failed to transition ${key} (HTTP ${code}): $(cat /tmp/jira-resp.json)" >&2
    fail=1
  fi
  echo "::endgroup::"
done

exit "$fail"
