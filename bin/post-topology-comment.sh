#!/bin/sh
# Posts events-diff.md (see bin/report-topology-changes.sh) as a comment on the
# merge request of the current pipeline, so the reviewer sees what changed in the
# message map - with the highlighted Mermaid diagrams GitLab renders in notes -
# without opening job artifacts.
#
# One comment per MR: the note carries a hidden marker and is updated in place on
# every pipeline instead of adding a new one. When the map is unchanged, no
# comment is created; an existing one is updated to say so, so a comment never
# describes changes the branch no longer has.
#
# Needs MESSENGER_TOPOLOGY_COMMENT_TOKEN: an access token with the `api` scope of a user
# who may comment on MRs (the release bot's PAT works; see the "Release" job in
# .gitlab-ci.yml). Must NOT be a protected variable: MR pipelines run on
# unprotected branches and would not see it. Without the token, or outside an MR
# pipeline, the script only says so and exits 0 - the report is still an artifact.
# Sent as "Authorization: Bearer", which GitLab accepts for personal access
# tokens as well as OAuth tokens (e.g. glab's, for a local dry run).
set -eu

REPORT="${MESSENGER_TOPOLOGY_REPORT:-${REPORT:-events-diff.md}}"
MARKER="<!-- messenger-topology-diff -->"

if [ -z "${CI_MERGE_REQUEST_IID:-}" ]; then
    echo "Not a merge request pipeline - nothing to comment on."
    exit 0
fi
if [ -z "${MESSENGER_TOPOLOGY_COMMENT_TOKEN:-}" ]; then
    echo "MESSENGER_TOPOLOGY_COMMENT_TOKEN is not set - skipping the MR comment (the report is still a job artifact)."
    exit 0
fi

api="${CI_API_V4_URL:-https://gitlab.com/api/v4}/projects/${CI_PROJECT_ID}/merge_requests/${CI_MERGE_REQUEST_IID}/notes"
auth="Authorization: Bearer ${MESSENGER_TOPOLOGY_COMMENT_TOKEN}"
# scratch files live under the working tree (see bin/report-api-contract-changes.sh);
# no pipes after curl: in a pipeline its failure would be masked by jq's exit code
notes=".messenger-topology-notes.json"
note=".messenger-topology-note.json"
trap 'rm -f "$notes" "$note"' EXIT

# the note we own, if any (the marker is invisible in the rendered comment)
curl -sS --fail-with-body -o "$notes" -H "$auth" "$api?per_page=100&sort=asc&order_by=created_at"
existing=$(jq -r --arg m "$MARKER" '[.[] | select(.body | startswith($m))] | first | .id // empty' "$notes")

if [ ! -s "$REPORT" ] || grep -qx 'No changes\.' "$REPORT"; then
    if [ -z "$existing" ]; then
        echo "Event map unchanged - no comment to post."
        exit 0
    fi
    printf '%s\n%s' "$MARKER" "Message map: no changes compared to the target branch any more." | jq -Rs '{body: .}' > "$note"
    curl -sS --fail-with-body -o /dev/null -X PUT -H "$auth" -H 'Content-Type: application/json' --data "@$note" "$api/$existing"
    echo "Event map unchanged - updated the earlier comment ($existing) to say so."
    exit 0
fi

{ printf '%s\n' "$MARKER"; cat "$REPORT"; } | jq -Rs '{body: .}' > "$note"
if [ -n "$existing" ]; then
    curl -sS --fail-with-body -o /dev/null -X PUT -H "$auth" -H 'Content-Type: application/json' --data "@$note" "$api/$existing"
    echo "Updated the event map comment ($existing) on MR !$CI_MERGE_REQUEST_IID."
else
    curl -sS --fail-with-body -o "$notes" -X POST -H "$auth" -H 'Content-Type: application/json' --data "@$note" "$api"
    echo "Posted the event map comment ($(jq -r '.id' "$notes")) on MR !$CI_MERGE_REQUEST_IID."
fi
