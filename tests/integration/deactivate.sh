#!/usr/bin/env bash
# Deactivation integration test against the live cosmic-cache container:
# generate both drop-ins and the WP_CACHE define, warm a page, deactivate, then
# assert every artifact Cosmic created is removed (advanced-cache.php,
# object-cache.php, WP_CACHE define, cron hooks, cache dir), then reactivate and
# confirm the drop-ins regenerate cleanly.
#
# Usage (from repo root):
#   bash tests/integration/deactivate.sh [container-name]
set -euo pipefail

CONTAINER="${1:-container_wordpress_1}"
URL="${COSMIC_SITE_URL:-http://localhost:18080}"
CTR="${COSMIC_CTR:-$(command -v podman 2>/dev/null || command -v docker 2>/dev/null)}"
CTR="${CTR:-podman}"
W() { "$CTR" exec "$CONTAINER" wp --allow-root --path=/var/www/html "$@"; }
PE() { "$CTR" exec "$CONTAINER" sh -c "$1"; }

cleanup() {
  W plugin activate cosmic-cache >/dev/null 2>&1 || true
  W option update cosmic_cache_settings '{"enabled":true,"object_cache_enabled":true,"object_cache_backend":"redis","object_cache_host":"redis","object_cache_port":6379}' --format=json >/dev/null 2>&1 || true
  W eval 'CosmicCache\Plugin::instance()->write_advanced_cache();' >/dev/null 2>&1 || true
  W eval 'CosmicCache\ObjectCache\DropInGenerator::generate(CosmicCache\Config::load());' >/dev/null 2>&1 || true
  W cosmic purge >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "== 1. Activate and generate both drop-ins =="
W plugin activate cosmic-cache >/dev/null
W option update cosmic_cache_settings '{"enabled":true,"object_cache_enabled":true,"object_cache_backend":"redis","object_cache_host":"redis","object_cache_port":6379}' --format=json >/dev/null
W eval 'CosmicCache\Plugin::instance()->write_advanced_cache();'
W eval 'CosmicCache\ObjectCache\DropInGenerator::generate(CosmicCache\Config::load());'
PE 'test -f /var/www/html/wp-content/advanced-cache.php && echo "advanced-cache.php present" || { echo "advanced-cache.php missing (BAD)"; exit 1; }'
PE 'test -f /var/www/html/wp-content/object-cache.php && echo "object-cache.php present" || { echo "object-cache.php missing (BAD)"; exit 1; }'

echo "== 2. Own WP_CACHE + warm a page =="
W eval 'update_option("cosmic_cache_owns_wp_cache", "1");'
PID=$(W post create --post_type=page --post_status=publish --post_title="Deact Test" --porcelain)
curl -s -o /dev/null "$URL/?page_id=$PID"
PE 'test -d /var/www/html/wp-content/cache/cosmic && find /var/www/html/wp-content/cache/cosmic -name "*.html" | grep -q . && echo "cache dir populated" || { echo "cache dir not populated (BAD)"; exit 1; }'

echo "== 3. Deactivate =="
W plugin deactivate cosmic-cache >/dev/null

PE 'test ! -f /var/www/html/wp-content/advanced-cache.php && echo "advanced-cache.php removed" || { echo "advanced-cache.php still present (BAD)"; exit 1; }'
PE 'test ! -f /var/www/html/wp-content/object-cache.php && echo "object-cache.php removed" || { echo "object-cache.php still present (BAD)"; exit 1; }'
PE 'test ! -d /var/www/html/wp-content/cache/cosmic && echo "cache dir removed" || { echo "cache dir still present (BAD)"; exit 1; }'
PE 'grep -q "Added by Cosmic Cache" /var/www/html/wp-config.php && { echo "WP_CACHE define still present (BAD)"; exit 1; } || echo "WP_CACHE define removed"'
cron_left=$(W eval 'var_export((bool) wp_next_scheduled("cosmic_cache_cleanup") || (bool) wp_next_scheduled("cosmic_cache_preload"));')
[ "$cron_left" = "false" ] && echo "cron hooks cleared" || { echo "cron hooks still scheduled (BAD)"; exit 1; }

echo "== 4. Front end still serves without the plugin =="
code=$(curl -s -o /dev/null -w '%{http_code}' "$URL/")
[ "$code" = "200" ] && echo "site still serves 200" || { echo "site broken after deactivate (got $code)"; exit 1; }

echo "== 5. Reactivate and regenerate =="
W plugin activate cosmic-cache >/dev/null
W eval 'CosmicCache\Plugin::instance()->write_advanced_cache();'
W eval 'CosmicCache\ObjectCache\DropInGenerator::generate(CosmicCache\Config::load());'
PE 'test -f /var/www/html/wp-content/advanced-cache.php && echo "advanced-cache.php regenerated" || { echo "advanced-cache.php missing after reactivate (BAD)"; exit 1; }'
PE 'test -f /var/www/html/wp-content/object-cache.php && echo "object-cache.php regenerated" || { echo "object-cache.php missing after reactivate (BAD)"; exit 1; }'
W post delete "$PID" --force >/dev/null 2>&1 || true

echo "DEACTIVATE TEST PASSED"
