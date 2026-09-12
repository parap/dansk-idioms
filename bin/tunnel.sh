#!/usr/bin/env bash
#
# The public URL, and keeping it working.
#
#   bin/tunnel.sh url     print the current public URL
#   bin/tunnel.sh check   confirm that URL actually serves the app (exit 1 if not)
#   bin/tunnel.sh start   create the container, replacing any existing one
#   bin/tunnel.sh heal    check, and rebuild the tunnel if it is broken
#
# A quick tunnel has no fixed hostname: Cloudflare issues a new one every time
# cloudflared starts, so a URL from an earlier run is usually dead. It also drops its
# control stream and sits in a reconnect loop while the container still reports "Up",
# which the browser shows as a page that renders from the service worker cache and then
# fails every API call. "check" is what tells those apart.
#
# A stable hostname needs a named tunnel, which needs a domain in a Cloudflare zone.
#
# Two things about this network decide how the container is started and checked.
#
# cloudflared prefers QUIC, and UDP 7844 does not leave here. The tunnel then registers
# nothing and retries forever, logging "control stream encountered a failure" -- so
# --protocol http2 is not a tuning knob, it is what makes the tunnel work at all.
#
# And the hostname must not be looked up before the tunnel has registered. The record
# does not exist yet, the network's resolver caches that answer for the zone's negative
# TTL, and a working tunnel then looks dead from this machine for half an hour while it
# serves everyone else perfectly.

set -euo pipefail

CONTAINER=${TUNNEL_CONTAINER:-dansk_tunnel}
NETWORK=${TUNNEL_NETWORK:-danskdiv_default}
ORIGIN=${TUNNEL_ORIGIN:-http://app:80}
IMAGE=cloudflare/cloudflared:latest

# Long enough for the new name to reach the network's resolver. Asking sooner is what
# poisons its negative cache.
SETTLE=${TUNNEL_SETTLE:-30}

url() {
    docker logs "$CONTAINER" 2>&1 \
        | grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' \
        | tail -1
}

check() {
    local u
    u=$(url)
    if [ -z "$u" ]; then
        echo "no URL in the tunnel log -- is $CONTAINER running?" >&2
        return 1
    fi

    # The health endpoint, not the front page: the service worker can serve a cached
    # page from a dead tunnel, but it never answers for /api/.
    local code
    code=$(curl -s -o /dev/null -m 20 -w '%{http_code}' "$u/api/v1/health" || true)
    if [ "$code" != "200" ]; then
        echo "$u -> HTTP ${code:-none}" >&2
        return 1
    fi

    echo "$u"
}

# Replaces the container rather than restarting it: a restart keeps the command the
# container was created with, so a tunnel created without --protocol http2 can never be
# repaired by restarting it.
start() {
    docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
    docker run -d --name "$CONTAINER" --network "$NETWORK" --restart unless-stopped \
        "$IMAGE" tunnel --no-autoupdate --protocol http2 --url "$ORIGIN" >/dev/null

    local deadline=$((SECONDS + 90))
    while [ $SECONDS -lt $deadline ]; do
        if docker logs "$CONTAINER" 2>&1 | grep -q 'Registered tunnel connection'; then
            sleep "$SETTLE"
            check
            return
        fi
        sleep 3
    done

    echo "tunnel did not register within 90s" >&2
    docker logs --tail 20 "$CONTAINER" >&2
    return 1
}

heal() {
    if check >/dev/null 2>&1; then
        echo "tunnel is healthy: $(url)"
        return 0
    fi

    echo "tunnel is not serving; rebuilding" >&2
    start
}

case "${1:-check}" in
    url)   url ;;
    check) check ;;
    start) start ;;
    heal)  heal ;;
    *)     echo "usage: $0 {url|check|start|heal}" >&2; exit 2 ;;
esac
