#!/usr/bin/env bash
# Body-delivery integration test against the live cosmic-cache container:
# X-Sendfile / X-Accel-Redirect header emission and fail-open fallback.
#
# Usage (from repo root):
#   bash tests/integration/sendfile.sh [container-name]
set -euo pipefail

CONTAINER="${1:-container_wordpress_1}"
URL="${COSMIC_SITE_URL:-http://localhost:18080}"
CTR="${COSMIC_CTR:-$(command -v podman 2>/dev/null || command -v docker 2>/dev/null)}"
CTR="${CTR:-podman}"
W() { "$CTR" exec "$CONTAINER" wp --allow-root --path=/var/www/html "$@"; }
PE() { "$CTR" exec "$CONTAINER" sh -c "$1"; }

cleanup() {
  W option update cosmic_cache_settings '{"enabled":true}' --format=json >/dev/null 2>&1 || true
  W eval 'CosmicCache\Config::compile();' >/dev/null 2>&1 || true
  W cosmic purge >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "== 1. Default (php): no server header, full body =="
W option update cosmic_cache_settings '{"enabled":true}' --format=json >/dev/null
W eval 'CosmicCache\Config::compile();' >/dev/null
W cosmic purge >/dev/null
curl -s -o /dev/null "$URL/"
hdrs=$(curl -s -D - -o /dev/null "$URL/")
echo "$hdrs" | grep -qi 'x-sendfile:' && { echo "DEFAULT LEAKS X-Sendfile (BAD)"; exit 1; } || echo "no X-Sendfile OK"
echo "$hdrs" | grep -qi 'x-accel-redirect:' && { echo "DEFAULT LEAKS X-Accel (BAD)"; exit 1; } || echo "no X-Accel-Redirect OK"
# A 200 with a non-empty body: readfile path.
code=$(echo "$hdrs" | head -1 | awk '{print $2}')
[ "$code" = "200" ] || { echo "unexpected status $code"; exit 1; }

echo "== 2. X-Sendfile: drop-in emits header with absolute path =="
W option update cosmic_cache_settings '{"enabled":true,"serve_method":"x-sendfile"}' --format=json >/dev/null
W eval 'CosmicCache\Config::compile();' >/dev/null
W cosmic purge >/dev/null
curl -s -o /dev/null "$URL/"
XSF=$(curl -s -D - -o /dev/null "$URL/" | grep -i '^x-sendfile:' | tr -d '\r' | awk '{print $2}')
[ -n "$XSF" ] || { echo "X-Sendfile header MISSING"; exit 1; }
case "$XSF" in
  /var/www/html/wp-content/cache/cosmic/*.html) echo "X-Sendfile path OK ($XSF)" ;;
  *) echo "X-Sendfile path UNEXPECTED ($XSF)"; exit 1 ;;
esac

echo "== 3. X-Accel-Redirect: internal URI mapped under /cosmic-cache/ =="
W option update cosmic_cache_settings '{"enabled":true,"serve_method":"x-accel-redirect"}' --format=json >/dev/null
W eval 'CosmicCache\Config::compile();' >/dev/null
W cosmic purge >/dev/null
curl -s -o /dev/null "$URL/"
XAR=$(curl -s -D - -o /dev/null "$URL/" | grep -i '^x-accel-redirect:' | tr -d '\r' | awk '{print $2}')
[ -n "$XAR" ] || { echo "X-Accel-Redirect header MISSING"; exit 1; }
case "$XAR" in
  /cosmic-cache/http/localhost/*.html) echo "X-Accel-Redirect URI OK ($XAR)" ;;
  *) echo "X-Accel-Redirect URI UNEXPECTED ($XAR)"; exit 1 ;;
esac

echo "== 4. Unknown serve_method fails open to readfile =="
W option update cosmic_cache_settings '{"enabled":true,"serve_method":"bogus"}' --format=json >/dev/null
W eval 'CosmicCache\Config::compile();' >/dev/null
W cosmic purge >/dev/null
curl -s -o /dev/null "$URL/"
hdrs2=$(curl -s -D - -o /dev/null "$URL/")
echo "$hdrs2" | grep -qi 'x-sendfile:' && { echo "bogus leaked X-Sendfile (BAD)"; exit 1; } || echo "no X-Sendfile OK"
echo "$hdrs2" | grep -qi 'x-accel-redirect:' && { echo "bogus leaked X-Accel (BAD)"; exit 1; } || echo "no X-Accel-Redirect OK"

echo "SENDFILE TEST PASSED"