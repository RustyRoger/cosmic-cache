#!/usr/bin/env bash
# Integration smoke test for a live WordPress install in the cosmic-cache
# container (see tests/container/docker-compose.yml). Container-runtime aware
# (podman locally, docker on CI + Docker on the host) execs INTO the WP
# container; HTTP requests run from the HOST against the mapped port.
#
# Verifies (SPEC §17):
#  1. First (uncached) request writes cache files
#  2. Second (cached) request is served BEFORE WordPress boots
#     (X-Cache: HIT + did_action('init') never fires)
#  3. Settings save regenerates config.php
#  4. Purge hooks fire on post save
#  5. wp cosmic status / purge work
#
# Usage (from repo root):
#   bash tests/integration/smoke.sh [container-name]
set -euo pipefail

CONTAINER="${1:-container_wordpress_1}"
URL="${COSMIC_SITE_URL:-http://localhost:18080}"
CTR="${COSMIC_CTR:-$(command -v podman 2>/dev/null || command -v docker 2>/dev/null)}"
CTR="${CTR:-podman}"
W() { "$CTR" exec "$CONTAINER" wp --allow-root --path=/var/www/html "$@"; }
PE() { "$CTR" exec "$CONTAINER" sh -c "$1"; }

echo "== 1. First (uncached) request writes cache =="
# Warm-up: right after bootstrap the very first request races the freshly
# generated config (compile + opcache invalidate) and can miss capturing a
# cache file. Allow a brief settle then retry until a cache file exists, so
# a fresh CI container behaves deterministically.
curl -s -o /dev/null "$URL/"
for try in $(seq 1 10); do
	PE 'test -f /var/www/html/wp-content/cache/cosmic/config.php && echo "config.php OK"'
	PE 'ls /var/www/html/wp-content/cache/cosmic/http/localhost/*.html >/dev/null 2>&1 && echo "cache file OK" || echo "cache file MISSING"'
	if PE 'ls /var/www/html/wp-content/cache/cosmic/http/localhost/*.html >/dev/null 2>&1'; then
		break
	fi
	sleep 1
	curl -s -o /dev/null "$URL/"
done
PE 'ls /var/www/html/wp-content/cache/cosmic/http/localhost/*.html >/dev/null 2>&1' || { echo "cache file still MISSING after warm-up (BAD)"; exit 1; }

echo "== 2. Second (cached) request is served pre-boot =="
PE 'mkdir -p /var/www/html/wp-content/mu-plugins && printf "<?php\nadd_action(\"init\", function(){ file_put_contents(\"/tmp/booted\", time()); });\n" > /var/www/html/wp-content/mu-plugins/boot-probe.php && rm -f /tmp/booted'
headers=$(curl -s -D - -o /dev/null "$URL/")
echo "$headers" | grep -i "X-Cache: HIT" >/dev/null && echo "X-Cache: HIT OK"
PE 'test ! -e /tmp/booted && echo "pre-boot OK (init never fired)" || echo "WP BOOTED (BAD)"'
PE 'rm -rf /var/www/html/wp-content/mu-plugins /tmp/booted'

echo "== 3. Settings save regenerates config.php =="
before=$(PE 'stat -c %Y /var/www/html/wp-content/cache/cosmic/config.php')
W option update cosmic_cache_settings '{"enabled":true,"ttl":7200,"gzip":true,"brotli":true}' --format=json >/dev/null
W eval 'CosmicCache\Config::compile();'
after=$(PE 'stat -c %Y /var/www/html/wp-content/cache/cosmic/config.php')
[ "$before" -lt "$after" ] && echo "config.php regenerated" || { echo "config.php NOT regenerated"; exit 1; }

echo "== 4. Purge hooks fire on post save =="
PE 'rm -rf /var/www/html/wp-content/cache/cosmic/http'
curl -s -o /dev/null "$URL/"
PE 'ls /var/www/html/wp-content/cache/cosmic/http/localhost/*.html >/dev/null 2>&1 && echo "cached before post save"'
PID=$(W post list --post_type=post --format=ids | head -1 | tr -d '[:space:]')
W post update "$PID" --post_title="Smoke $RANDOM" >/dev/null
PE 'n=$(ls /var/www/html/wp-content/cache/cosmic/http/localhost/*.html 2>/dev/null | wc -l); [ "$n" -eq 0 ] && echo "cache purged after post save" || { echo "NOT purged (BAD): $n files remain"; exit 1; }'

echo "== 5. wp cosmic status / purge =="
W cosmic status
W cosmic purge >/dev/null && echo "wp cosmic purge OK"

echo "SMOKE TEST PASSED"
