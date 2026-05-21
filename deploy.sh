#!/bin/bash
# ──────────────────────────────────────────────────────────────────────────────
# deploy.sh — pull latest code from git and update the running container.
#
# Three outcomes:
#   1. No changes       → exits immediately, nothing touched
#   2. Code-only changes (src/, templates/, public/, bin/, etc.)
#                       → docker-compose up -d  (fast, no rebuild)
#   3. Dockerfile/composer/docker/ changed
#                       → docker-compose build && up -d  (full rebuild)
#
# Usage:
#   bash deploy.sh               (pulls from origin/main)
#   bash deploy.sh --branch dev  (pulls from origin/dev)
#   bash deploy.sh --force       (skips the "already up to date" check)
#
# Cron (Synology Task Scheduler → User-defined script):
#   bash /volume1/docker/Media-Server/deploy.sh >> /volume1/docker/Media-Server/data/deploy.log 2>&1
# ──────────────────────────────────────────────────────────────────────────────
set -euo pipefail

BRANCH="main"
FORCE=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --branch) BRANCH="$2"; shift 2 ;;
        --force)  FORCE=true;  shift   ;;
        *)        echo "Unknown option: $1"; exit 1 ;;
    esac
done

# ── Resolve script directory so it works regardless of cwd ────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# ── Pick docker-compose command (v1 on older Synology, v2 plugin elsewhere) ───
if command -v docker-compose &>/dev/null; then
    DC="docker-compose"
else
    DC="docker compose"
fi

TIMESTAMP="$(date '+%Y-%m-%d %H:%M:%S')"
echo ""
echo "[$TIMESTAMP] ── deploy.sh ──────────────────────────────────────────"

# ── Fetch & check for changes ─────────────────────────────────────────────────
echo "Fetching origin…"
git fetch origin "$BRANCH" --quiet

LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse "origin/$BRANCH")

if [ "$LOCAL" = "$REMOTE" ] && [ "$FORCE" = "false" ]; then
    echo "Already up to date ($BRANCH @ ${LOCAL:0:7}). Nothing to do."
    exit 0
fi

echo "Updating: ${LOCAL:0:7} → ${REMOTE:0:7}"

# ── Capture which files changed before pulling ────────────────────────────────
CHANGED_FILES=$(git diff --name-only "$LOCAL" "$REMOTE" 2>/dev/null || true)

# ── Pull ──────────────────────────────────────────────────────────────────────
git pull origin "$BRANCH" --quiet
echo "Pulled $BRANCH successfully."

if [ -n "$CHANGED_FILES" ]; then
    echo "Changed files:"
    echo "$CHANGED_FILES" | sed 's/^/  /'
fi

# ── Decide: rebuild or just restart? ──────────────────────────────────────────
#
# Rebuild is needed when Composer deps or the Docker image itself changes.
# Everything else is served live via the bind mount — only a container
# restart (up -d) is needed to pick up .env / docker-compose.yml changes.
#
NEEDS_REBUILD=false
if echo "$CHANGED_FILES" | grep -qE "^(Dockerfile|composer\.json|composer\.lock|docker/)"; then
    NEEDS_REBUILD=true
fi

if [ "$FORCE" = "true" ]; then
    NEEDS_REBUILD=true
fi

if [ "$NEEDS_REBUILD" = "true" ]; then
    echo "Build-affecting files changed — rebuilding image…"
    $DC build
fi

echo "Applying update…"
$DC up -d

echo "Done ✓"
