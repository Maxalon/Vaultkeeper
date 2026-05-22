#!/usr/bin/env bash
#
# Walk every VAULT-* ticket referenced in $SOURCE_TEXT *forward* to a target
# status, one workflow transition at a time.
#
# The VAULT workflow has no direct edge from e.g. "Beta Testing" to "Released"
# (it goes Beta Testing → Ready for Release → Released), so a single transition
# won't do. For each ticket this asks Jira for its current status + available
# transitions, takes the one step that moves it closest to — but not past —
# the target, and repeats until it arrives. Movement is forward-only along the
# pipeline below:
#
#   Concept → To Do → In Progress → In Code Review → Beta Testing
#           → Ready for Release → Released
#
# A ticket already at or beyond the target is left untouched (so re-running the
# backfill, or a later staging merge, never drags a Released ticket back). A
# ticket sitting on an off-pipeline status (Feedback, Done) is skipped rather
# than guessed at — move those by hand.
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

# Forward pipeline order (lower-cased for case-insensitive matching).
PIPELINE=( "concept" "to do" "in progress" "in code review" "beta testing" "ready for release" "released" )

idx_of() {
  local needle i
  needle="$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')"
  for i in "${!PIPELINE[@]}"; do
    [ "${PIPELINE[$i]}" = "$needle" ] && { echo "$i"; return; }
  done
  echo -1
}

target_idx="$(idx_of "$TARGET_STATUS")"
if [ "$target_idx" -lt 0 ]; then
  echo "Target status '${TARGET_STATUS}' is not on the known pipeline; aborting." >&2
  exit 1
fi

# Step one ticket forward until it reaches the target (or can't progress).
walk_one() {
  local key="$1" hop resp cur cur_idx tid tname di best_id best_idx best_name code
  for hop in $(seq 1 "${#PIPELINE[@]}"); do
    resp="$(curl -fsS -u "$AUTH" -H 'Accept: application/json' \
      "${BASE_URL}/rest/api/3/issue/${key}?fields=status&expand=transitions" 2>/dev/null || true)"

    cur="$(printf '%s' "$resp" | jq -r '.fields.status.name // empty' 2>/dev/null || true)"
    if [ -z "$cur" ]; then
      echo "  ${key}: cannot read status (missing issue or auth?); skipping."
      return 0
    fi

    cur_idx="$(idx_of "$cur")"
    if [ "$cur_idx" -lt 0 ]; then
      echo "  ${key}: current status '${cur}' is off-pipeline; skipping (move manually)."
      return 0
    fi
    if [ "$cur_idx" -eq "$target_idx" ]; then
      if [ "$hop" -eq 1 ]; then echo "  ${key}: already at '${cur}'."; else echo "  ${key}: reached '${cur}'."; fi
      return 0
    fi
    if [ "$cur_idx" -gt "$target_idx" ]; then
      echo "  ${key}: already past target at '${cur}'; leaving as-is."
      return 0
    fi

    # Pick the available transition whose destination is the next step toward
    # the target: smallest pipeline index strictly above current and at/below
    # the target.
    best_id=""; best_idx=99; best_name=""
    while IFS=$'\t' read -r tid tname; do
      [ -z "$tid" ] && continue
      di="$(idx_of "$tname")"
      if [ "$di" -gt "$cur_idx" ] && [ "$di" -le "$target_idx" ] && [ "$di" -lt "$best_idx" ]; then
        best_idx="$di"; best_id="$tid"; best_name="$tname"
      fi
    done < <(printf '%s' "$resp" | jq -r '.transitions[]? | "\(.id)\t\(.to.name)"' 2>/dev/null || true)

    if [ -z "$best_id" ]; then
      echo "  ${key}: no forward transition from '${cur}' toward '${TARGET_STATUS}'; stopping."
      return 0
    fi

    code="$(curl -sS -u "$AUTH" -o /tmp/jira-resp.json -w '%{http_code}' \
      -X POST -H 'Content-Type: application/json' \
      "${BASE_URL}/rest/api/3/issue/${key}/transitions" \
      -d "{\"transition\":{\"id\":\"${best_id}\"}}")"

    if [ "$code" = "204" ]; then
      echo "  ${key}: ${cur} → ${best_name}."
    else
      echo "  ${key}: transition failed (HTTP ${code}): $(cat /tmp/jira-resp.json)" >&2
      return 1
    fi
  done
  echo "  ${key}: hop limit reached without arriving at '${TARGET_STATUS}'."
  return 0
}

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
  walk_one "$key" || fail=1
  echo "::endgroup::"
done

exit "$fail"
