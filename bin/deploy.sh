#!/usr/bin/env bash
#
# Puts the current commit on the server and proves the site still works from outside.
#
#   bin/deploy.sh              # test, deploy, verify
#   bin/deploy.sh --no-tests   # skip the PHP suite (it needs the local containers up)
#
# Four steps a "git pull && docker compose up" misses, three of them silently: the
# dependencies (vendor/ is not committed), the migrations (a missing column surfaces on
# the first request that needs it, not at deploy time), the hand-written idioms and the
# reading documents (files in content/ are the source of truth, and a database that never
# imported them serves a site that works and is empty).
#
# What it will not do is roll back. A failed smoke check leaves the previous commit
# printed and the command to return to it, because a migration that already applied is
# not undone by moving the checkout backwards, and deciding that is a person's job.

set -euo pipefail

HOST=${DEPLOY_HOST:-friday-bot}
DIR=${DEPLOY_DIR:-dansk.div}
URL=${DEPLOY_URL:-https://danskidioms.com}
# --progress quiet: a build log is not a deploy report, and the interesting lines are
# the ones this script prints itself.
COMPOSE="docker compose --progress quiet --profile tls"

say()  { printf '\n\033[1m%s\033[0m\n' "$*"; }
fail() { printf '\033[31m%s\033[0m\n' "$*" >&2; exit 1; }

# ---- the checkout being deployed must be the one that was tested ----------------------

say "checking the working tree"
[ -z "$(git status --porcelain)" ] || fail "uncommitted changes -- commit or stash them first"
git fetch -q origin
local_head=$(git rev-parse HEAD)
[ "$local_head" = "$(git rev-parse origin/master)" ] \
    || fail "HEAD is not what origin/master points at -- push first"
echo "  ${local_head:0:7} $(git log -1 --format=%s)"

if [ "${1:-}" != "--no-tests" ]; then
    say "running the suite"
    docker-compose exec -T app vendor/bin/phpunit 2>&1 | tail -3
fi

# ---- the server ----------------------------------------------------------------------

say "deploying to $HOST:$DIR"
previous=$(ssh "$HOST" "cd $DIR && git rev-parse HEAD")
echo "  currently at ${previous:0:7}"

ssh "$HOST" "set -euo pipefail
    cd $DIR
    git pull -q origin master
    echo '  now at' \$(git rev-parse --short HEAD)

    $COMPOSE up -d --build

    # Compose returns as soon as the containers start, which is before the database is
    # ready to be migrated against.
    for i in \$(seq 1 30); do
        curl -sf -m 5 -o /dev/null http://127.0.0.1:8080/api/v1/health && break
        sleep 2
    done

    $COMPOSE exec -T app composer install --no-interaction --no-progress --quiet

    # Before the migrations, not after. git reset returns the code; nothing returns a
    # column a migration dropped, so this is the only thing standing between a bad
    # migration and the corpus.
    echo '  dumped to' \$(bin/backup-db.sh pre-deploy \$(git rev-parse --short HEAD))

    $COMPOSE exec -T app php bin/migrate.php | tail -2
    $COMPOSE exec -T app php bin/idiom-import.php | tail -2
    $COMPOSE exec -T app php bin/reading-import.php content/reading/*.txt | tail -2
"

# ---- the verdict, taken from outside --------------------------------------------------

say "checking $URL from here, not from the server"
problems=0
check() {
    local what=$1 got=$2 want=$3
    if [ "$got" = "$want" ]; then
        printf '  %-34s %s\n' "$what" "$got"
    else
        printf '  \033[31m%-34s %s (expected %s)\033[0m\n' "$what" "$got" "$want"
        problems=$((problems + 1))
    fi
}

code() { curl -s -o /dev/null --connect-timeout 8 -m 25 -w '%{http_code}' "$@" || echo 000; }

check "https answers"        "$(code "$URL/")"                          200
check "http redirects"       "$(code "${URL/https:/http:}/")"           308
check "reading page"         "$(code "$URL/read.html")"                 200
check "health"               "$(code "$URL/api/v1/health")"             200
# The body, not just the status. A bare 404 is also what a stranger's server says, and
# a check that passes when the site is missing entirely is not checking anything.
hidden=$(curl -s --connect-timeout 8 -m 25 "$URL/api/v1/admin/review" \
         | grep -o '"code":"not_found"' || true)
check "admin stays hidden"   "${hidden:-something else}"                '"code":"not_found"'

exam=$(curl -s --connect-timeout 8 -m 25 -X POST "$URL/api/v1/reading/sessions" \
        -H 'Content-Type: application/json' -d '{"mode":"exam"}' \
       | grep -o '"points_max":[0-9]*' || true)
check "an exam assembles"    "${exam:-nothing}"                         '"points_max":24'

if [ "$problems" -ne 0 ]; then
    printf '\n\033[31m%s check(s) failed. The previous commit was %s.\033[0m\n' \
        "$problems" "${previous:0:7}" >&2
    printf 'To go back:\n  ssh %s "cd %s && git reset --hard %s && %s up -d --build"\n' \
        "$HOST" "$DIR" "$previous" "$COMPOSE" >&2
    printf 'Migrations that already applied are not undone by that.\n' >&2
    exit 1
fi

say "deployed"
