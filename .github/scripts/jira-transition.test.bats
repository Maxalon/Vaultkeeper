#!/usr/bin/env bats
# Tests for .github/scripts/jira-transition.sh
#
# Covers: idx_of, walk_one skip cases (mocked curl), and key extraction.

SCRIPT="$(cd "$(dirname "$BATS_TEST_FILENAME")"; pwd)/jira-transition.sh"

# ── idx_of ────────────────────────────────────────────────────────────────────

@test "idx_of: in-pipeline value 'In Progress' returns 2" {
  run bash -c "source \"${SCRIPT}\"; idx_of 'In Progress'"
  [ "$status" -eq 0 ]
  [ "$output" = "2" ]
}

@test "idx_of: first pipeline entry 'Concept' returns 0" {
  run bash -c "source \"${SCRIPT}\"; idx_of 'Concept'"
  [ "$status" -eq 0 ]
  [ "$output" = "0" ]
}

@test "idx_of: last pipeline entry 'Released' returns 6" {
  run bash -c "source \"${SCRIPT}\"; idx_of 'Released'"
  [ "$status" -eq 0 ]
  [ "$output" = "6" ]
}

@test "idx_of: unknown value returns -1" {
  run bash -c "source \"${SCRIPT}\"; idx_of 'Feedback'"
  [ "$status" -eq 0 ]
  [ "$output" = "-1" ]
}

# ── walk_one ──────────────────────────────────────────────────────────────────

@test "walk_one: off-pipeline status skips ticket" {
  local tmp; tmp="$(mktemp)"
  printf '%s' '{"fields":{"status":{"name":"Feedback"}},"transitions":[]}' > "$tmp"
  run bash -c "
    source \"${SCRIPT}\"
    BASE_URL=x AUTH=x TARGET_STATUS='In Progress' target_idx=2
    curl() { cat \"${tmp}\"; }
    walk_one 'VAULT-1'
  "
  rm -f "$tmp"
  [ "$status" -eq 0 ]
  [[ "$output" == *"off-pipeline"* ]]
}

@test "walk_one: already at target reports and returns" {
  local tmp; tmp="$(mktemp)"
  printf '%s' '{"fields":{"status":{"name":"In Progress"}},"transitions":[]}' > "$tmp"
  run bash -c "
    source \"${SCRIPT}\"
    BASE_URL=x AUTH=x TARGET_STATUS='In Progress' target_idx=2
    curl() { cat \"${tmp}\"; }
    walk_one 'VAULT-1'
  "
  rm -f "$tmp"
  [ "$status" -eq 0 ]
  [[ "$output" == *"already at"* ]]
}

@test "walk_one: already past target leaves ticket as-is" {
  local tmp; tmp="$(mktemp)"
  printf '%s' '{"fields":{"status":{"name":"In Progress"}},"transitions":[]}' > "$tmp"
  run bash -c "
    source \"${SCRIPT}\"
    BASE_URL=x AUTH=x TARGET_STATUS='To Do' target_idx=1
    curl() { cat \"${tmp}\"; }
    walk_one 'VAULT-1'
  "
  rm -f "$tmp"
  [ "$status" -eq 0 ]
  [[ "$output" == *"already past target"* ]]
}

@test "walk_one: no forward transition available stops gracefully" {
  local tmp; tmp="$(mktemp)"
  printf '%s' '{"fields":{"status":{"name":"To Do"}},"transitions":[]}' > "$tmp"
  run bash -c "
    source \"${SCRIPT}\"
    BASE_URL=x AUTH=x TARGET_STATUS='In Code Review' target_idx=3
    curl() { cat \"${tmp}\"; }
    walk_one 'VAULT-1'
  "
  rm -f "$tmp"
  [ "$status" -eq 0 ]
  [[ "$output" == *"no forward transition"* ]]
}

# ── key extraction ────────────────────────────────────────────────────────────

@test "key extraction: deduplicates keys from blob with duplicates" {
  run bash -c "printf '%s' 'Fix VAULT-42 and VAULT-42 again; also VAULT-7' | grep -oE 'VAULT-[0-9]+' | sort -u"
  [ "$status" -eq 0 ]
  [[ "$output" == *"VAULT-7"* ]]
  [[ "$output" == *"VAULT-42"* ]]
  [ "$(printf '%s\n' "$output" | grep -c 'VAULT-42')" -eq 1 ]
}

@test "key extraction: returns nothing for blob with no keys" {
  run bash -c "printf '%s' 'no issue references here' | grep -oE 'VAULT-[0-9]+' | sort -u"
  [ -z "$output" ]
}

@test "key extraction: ignores lowercase and mixed-case noise" {
  run bash -c "printf '%s' 'vault-1 Vault-2 VAULT-3 vAuLt-4' | grep -oE 'VAULT-[0-9]+' | sort -u"
  [ "$status" -eq 0 ]
  [ "$output" = "VAULT-3" ]
}
