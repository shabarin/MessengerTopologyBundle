#!/bin/sh
# Reports how the Messenger message map (docs/diagrams/events.md) changed
# compared to the target branch: which messages, publishers, handlers and
# transports were added, removed or changed. The report is Markdown with
# highlighted Mermaid diagrams ("was + is": added green, removed as a red
# ghost, transport change amber) and goes to events-diff.md, collected as a
# CI artifact and posted to the merge request by bin/post-topology-comment.sh.
#
# The comparison runs on the committed JSON snapshot (docs/diagrams/events.json)
# at the merge base - not on a second checkout - because the collector relies on
# reflection and only sees the classes of the current tree. Staleness of the
# snapshot itself is a separate, blocking check (`make diagrams-check`).
#
# Needs a full clone (GIT_DEPTH=0) for the merge base and a way to run the
# Symfony console: MESSENGER_TOPOLOGY_CONSOLE, e.g. "docker compose exec -T php bin/console"
# when the app lives in a compose stack. Informational by design: never fails
# the pipeline.
set -eu

TARGET_BRANCH="${TARGET_BRANCH:-${CI_MERGE_REQUEST_TARGET_BRANCH_NAME:-main}}"
CONSOLE="${MESSENGER_TOPOLOGY_CONSOLE:-bin/console}"
SNAPSHOT="${MESSENGER_TOPOLOGY_SNAPSHOT:-docs/diagrams/events.json}"
REPORT="${MESSENGER_TOPOLOGY_REPORT:-events-diff.md}"

# explicit refspec: gitlab-runner checkouts may not have the default
# remote-tracking refspec configured
git fetch --quiet origin "+refs/heads/$TARGET_BRANCH:refs/remotes/origin/$TARGET_BRANCH"
base=$(git merge-base HEAD "origin/$TARGET_BRANCH")
short=$(git rev-parse --short "$base")

if ! git cat-file -e "$base:$SNAPSHOT" 2>/dev/null; then
    echo "No event map snapshot on the merge base ($TARGET_BRANCH @ $short) - it is introduced by this branch, nothing to compare against."
    rm -f "$REPORT"
    exit 0
fi

# $CONSOLE is split on purpose: it may be a command with arguments
# shellcheck disable=SC2086
git show "$base:$SNAPSHOT" \
    | $CONSOLE messenger-topology:diff --base=- --base-label="$TARGET_BRANCH ($short)" \
    > "$REPORT"

echo "Event map compared to $TARGET_BRANCH ($short); full report with diagrams in $REPORT."
echo
# the text part only - the diagrams are for the MR, not the job log
awk '/^## /{exit} {print}' "$REPORT"
