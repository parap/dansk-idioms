#!/usr/bin/env bash
# Import the newest Telegram Desktop export.
#
#   bin/load-export.sh                 # finds the newest ChatExport_* in ~/Downloads
#   bin/load-export.sh /path/to/dir    # or point at an export folder directly
#
# Safe to run repeatedly: everything upserts, and entries you corrected by hand in
# the review queue are never overwritten.
set -euo pipefail
cd "$(dirname "$0")/.."

SRC="${1:-}"
if [ -z "$SRC" ]; then
  SRC=$(ls -dt "$HOME/Downloads/Telegram Desktop/ChatExport_"* 2>/dev/null | head -1 || true)
fi
[ -n "$SRC" ] || { echo "No export found. Pass the folder path as an argument." >&2; exit 1; }

FILE="$SRC/messages.html"
[ -f "$FILE" ] || { echo "No messages.html in: $SRC" >&2; exit 1; }

echo "Loading: $FILE"
# The container only sees the project directory, so the export has to live inside it.
cp "$FILE" storage/exports/messages.html
# Multi-part exports (messages2.html, ...) are concatenated by the reader run below.
for extra in "$SRC"/messages[0-9]*.html; do
  [ -f "$extra" ] && cp "$extra" storage/exports/ || true
done

for f in storage/exports/messages*.html; do
  echo
  echo "--- $(basename "$f") ---"
  docker-compose exec -T app php bin/import.php --file="$f"
done

echo
docker-compose exec -T app php bin/reclassify.php | head -1
