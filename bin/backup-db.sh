#!/usr/bin/env bash
#
# Dumps the database, checks the dump is usable, and keeps the most recent few.
#
#   bin/backup-db.sh                    # a nightly dump
#   bin/backup-db.sh pre-deploy a1b2c3d # before a deploy applies migrations
#
# Runs from cron, so it says nothing when it works and says what broke when it does not.
#
# The checks exist because a dump that failed halfway is still a file. A directory of
# truncated archives looks exactly like a directory of backups, and the difference is
# discovered on the one day it matters -- so a dump that cannot be read, is implausibly
# small, has no schema in it, or lacks mysqldump's own completion marker is an error here
# rather than a surprise later.

set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$ROOT"

DEST=${BACKUP_DIR:-$ROOT/backups}
KEEP=${BACKUP_KEEP:-14}

label=${1:-nightly}
note=${2:-}
name="${label}-$(date +%Y-%m-%d_%H%M%S)${note:+-$note}"
file="$DEST/$name.sql.gz"

mkdir -p "$DEST"
chmod 700 "$DEST"

# The password is read inside the container from its own environment, so it never reaches
# this file, the host's process list, or a cron line.
docker compose exec -T db sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" \
        --single-transaction --routines --triggers --events \
        --default-character-set=utf8mb4 --databases dansk' 2>/dev/null | gzip > "$file"

# The dump carries password hashes and every learner's progress.
chmod 600 "$file"

# A rejected dump is kept under .rejected rather than deleted. The check can be wrong --
# this one already was once -- and a file that at least exists can be looked at, where a
# deleted one leaves only the complaint.
refuse() { mv -f "$file" "$file.rejected"; echo "backup-db: $* (kept as $file.rejected)" >&2; exit 1; }

gzip -t "$file" 2>/dev/null            || refuse "$name is not a readable archive"

size=$(stat -c %s "$file")
[ "$size" -ge 50000 ]                  || refuse "$name is only $size bytes"

# grep reads from a process substitution, not from a pipe. Under pipefail a `grep -q`
# that stops at the first match kills zcat with SIGPIPE, and the pipeline reports 141 --
# so a perfectly good dump is condemned by the check that was meant to protect it.
grep -q 'CREATE TABLE `idioms`' <(zcat "$file") \
                                       || refuse "$name contains no schema"
grep -q '^-- Dump completed' <(zcat "$file") \
                                       || refuse "$name stops before mysqldump finished"

# Each label prunes its own, so a run of deploys cannot push the nightly dumps out.
#
# The `|| true` is not decoration. A glob that matches nothing is handed to ls verbatim,
# ls fails, and under pipefail that ends the script -- after the dump has been written and
# before it has been reported, which looks like a backup that did not happen.
keep_newest() {
    ls -1t "$@" 2>/dev/null | tail -n +$((KEEP + 1)) | xargs -r rm -- || true
}

keep_newest "$DEST/${label}-"*.sql.gz
keep_newest "$DEST/${label}-"*.sql.gz.rejected

echo "$file"
