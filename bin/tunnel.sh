#!/usr/bin/env bash
#
# The public URL, and keeping it working.
#
#   bin/tunnel.sh url     print the current public URL
#   bin/tunnel.sh check   confirm that URL actually serves the app (exit 1 if not)
#   bin/tunnel.sh heal    check, and restart the tunnel if it is broken
#
# A quick tunnel has no fixed hostname: Cloudflare issues a new one every time
# cloudflared starts, so a URL from an earlier run is usually dead. It also drops its
# control stream and sits in a reconnect loop while the container still reports "Up",
# which the browser shows as a page that renders from the service worker cache and then
# fails every API call. "check" is what tells those apart.
#
# A stable hostname needs a named tunnel, which needs a domain in a Cloudflare zone.

set -euo pipefail

CONTAINER=${TUNNEL_CONTAINER:-dansk_tunnel}

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

heal() {
    if check >/dev/null 2>&1; then
        echo "tunnel is healthy: $(url)"
        return 0
    fi

    echo "tunnel is not serving; restarting" >&2
    docker restart "$CONTAINER" >/dev/null

    # Wait for the banner, then for the edge connection to register.
    local deadline=$((SECONDS + 90))
    while [ $SECONDS -lt $deadline ]; do
        if docker logs --since 2m "$CONTAINER" 2>&1 | grep -q 'Registered tunnel connection'; then
            sleep 3
            if check >/dev/null 2>&1; then
                echo "new URL: $(docker logs --since 2m "$CONTAINER" 2>&1 \
                    | grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' | tail -1)"
                return 0
            fi
        fi
        sleep 3
    done

    echo "tunnel did not come back within 90s" >&2
    return 1
}

case "${1:-check}" in
    url)   url ;;
    check) check ;;
    heal)  heal ;;
    *)     echo "usage: $0 {url|check|heal}" >&2; exit 2 ;;
esac
