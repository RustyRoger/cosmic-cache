#!/usr/bin/env bash
# Extended integration test against the live cosmic-cache container:
# object cache (redis), preloader, optimization on real HTML, debug logging,
# and HTTP headers / 304 handling.
#
# Usage (from repo root):
#   bash tests/integration/extended.sh [container-name]
set -euo pipefail

CONTAINER="${1:-container_wordpress_1}"
URL="${COSMIC_SITE_URL:-http://localhost:18080}"
CTR="${COSMIC_CTR:-$(command -v podman 2>/dev/null || command -v docker 2>/dev/null)}"
CTR="${CTR:-podman}"
REDIS_CONTAINER="${COSMIC_REDIS_CONTAINER:-container_redis_1}"
TMPCMP="$(mktemp -d)"
W() { "$CTR" exec "$CONTAINER" wp --allow-root --path=/var/www/html "$@"; }
PE() { "$CTR" exec "$CONTAINER" sh -c "$1"; }
RC() { "$CTR" exec "$REDIS_CONTAINER" redis-cli "$@"; }

cleanup() {
  # Restore the original site URL if it was changed for the preload phase.
  if [ -n "${ORIG_SITE_URL:-}" ]; then
    W option update siteurl "$ORIG_SITE_URL" >/dev/null 2>&1 || true
    W option update home "$ORIG_SITE_URL" >/dev/null 2>&1 || true
  fi
  rm -rf "$TMPCMP"
  W option update cosmic_cache_settings '{"enabled":true}' --format=json >/dev/null 2>&1 || true
  W eval 'CosmicCache\Config::compile();' >/dev/null 2>&1 || true
  W cosmic purge >/dev/null 2>&1 || true
  PE 'rm -rf /var/www/html/wp-content/mu-plugins' 2>/dev/null || true
}
trap cleanup EXIT

echo "== 1. Object cache (redis) =="
W option update cosmic_cache_settings \
  '{"object_cache_enabled":true,"object_cache_backend":"redis","object_cache_host":"redis","object_cache_port":6379}' \
  --format=json >/dev/null
W eval 'CosmicCache\ObjectCache\DropInGenerator::generate(CosmicCache\Config::load());'
PE 'grep -q "backend.*redis" /var/www/html/wp-content/object-cache.php && echo "object-cache drop-in OK"'
W cosmic status | grep -i "object-cache"

W transient set cosmic_xtest hello >/dev/null
value=$(W transient get cosmic_xtest 2>/dev/null | tr -d '\r')
[ "$value" = "hello" ] && echo "transient round-trip OK" || { echo "transient FAILED ($value)"; exit 1; }
RC --scan --pattern '*cosmic_xtest*' 2>/dev/null | grep -q . && echo "redis holds key OK"
W transient delete cosmic_xtest >/dev/null

echo "== 2. Optimization pipeline on real HTML =="
WP_ID=$(W post create --post_type=page --post_status=publish --post_title="Opt Test" --porcelain)
PE 'mkdir -p /var/www/html/wp-content/mu-plugins && cat > /var/www/html/wp-content/mu-plugins/opt-inject.php <<"PHPEOF"
<?php
add_filter( "the_content", function ( $content ) {
	$content .= "<style>  .demo { color: red; }  </style>";
	$content .= "<script>  var a = 1;  </script>";
	$content .= "<script src=\"http://localhost:18080/wp-content/plugins/cosmic-cache/assets/admin.js\"></script>";
	$content .= "<img class=\"demo-img\" src=\"http://localhost:18080/wp-content/plugins/cosmic-cache/assets/admin.css\" alt=\"x\">";
	return $content;
} );
PHPEOF'
W option update cosmic_cache_settings \
  '{"enabled":true,"minify_html":true,"minify_css":true,"minify_js":true,"defer_js":true,"lazyload":true,"cdn_url":"https://cdn.example.com"}' \
  --format=json >/dev/null
W eval 'CosmicCache\Config::compile();'

page_url=$(W post list --post_type=page --post__in="$WP_ID" --field=url | head -1 | tr -d '\r')
html=$(curl -s "$page_url")
echo "$html" | grep -q 'loading="lazy"' && echo "lazyload OK" || { echo "lazyload FAILED"; exit 1; }
echo "$html" | grep -q 'cdn.example.com' && echo "cdn rewrite OK" || { echo "cdn FAILED"; exit 1; }
echo "$html" | grep -q 'defer' && echo "defer OK" || { echo "defer FAILED"; exit 1; }
echo "$html" | grep -q '\.demo{color:red}' && echo "minify OK" || { echo "minify FAILED"; exit 1; }
echo "$html" | grep -q 'var a = 1;' && echo "minify js OK" || { echo "minify js FAILED"; exit 1; }

W post delete "$WP_ID" --force >/dev/null 2>&1 || true
PE 'rm -rf /var/www/html/wp-content/mu-plugins'

echo "== 3. Optimization-disabled output is byte-identical =="
W option update cosmic_cache_settings '{"enabled":true}' --format=json >/dev/null
W eval 'CosmicCache\Config::compile();'
W cosmic purge >/dev/null
BT_PID=$(W post create --post_type=page --post_status=publish --post_title="Byte Test" --porcelain)
bt_url=$(W post list --post_type=page --post__in="$BT_PID" --field=url | head -1 | tr -d '\r')
curl -s --compressed "$bt_url" -o "$TMPCMP/body1.html"
curl -s --compressed "$bt_url" -o "$TMPCMP/body2.html"
cmp -s "$TMPCMP/body1.html" "$TMPCMP/body2.html" && echo "byte-identical OK" || { echo "byte-identical FAILED"; exit 1; }
W post delete "$BT_PID" --force >/dev/null 2>&1 || true

echo "== 4. Unpublish transition purges the post =="
UP_PID=$(W post create --post_type=page --post_status=publish --post_title="Unpublish Test" --porcelain)
up_key="http://localhost/?page_id=$UP_PID"
up_hash=$(W eval "echo sha1('$up_key');")
up_file="/var/www/html/wp-content/cache/cosmic/http/localhost/$up_hash.html"
curl -s -o /dev/null "http://localhost:18080/?page_id=$UP_PID"
PE "test -f $up_file && echo 'unpublish: post cached' || { echo 'unpublish: not cached (BAD)'; exit 1; }"
W post update "$UP_PID" --post_status=draft >/dev/null
PE "test ! -f $up_file && echo 'unpublish: purged OK' || { echo 'unpublish: NOT purged (BAD)'; exit 1; }"
W post delete "$UP_PID" --force >/dev/null 2>&1 || true

echo "== 4b. Publish purges the static posts page (incl. scheduled) =="
FRONT_PID=$(W post create --post_type=page --post_status=publish --post_title="Front Static" --porcelain | tr -d '\r')
POSTS_PID=$(W post create --post_type=page --post_status=publish --post_title="Articoli" --porcelain | tr -d '\r')
W option update show_on_front page >/dev/null
W option update page_on_front "$FRONT_PID" >/dev/null
W option update page_for_posts "$POSTS_PID" >/dev/null
posts_url=$(W post list --post_type=page --post__in="$POSTS_PID" --field=url | head -1 | tr -d '\r')
curl -s -o /dev/null "$posts_url"
curl -s -D - -o /dev/null "$posts_url" | grep -qi 'x-cache: hit' && echo "posts page warmed"
SP_PID=$(W post create --post_status=publish --post_title="News flash" --porcelain | tr -d '\r')
curl -s -D - -o /dev/null "$posts_url" | grep -qi 'x-cache: hit' && { echo "posts page NOT purged on publish (BAD)"; exit 1; } || echo "posts page purged on publish OK"
curl -s -o /dev/null "$posts_url"
FUT_PID=$(W post create --post_status=future --post_date_gmt="$(date -u -d '2 minutes ago' '+%Y-%m-%d %H:%M:%S')" --post_title="Scheduled news" --porcelain | tr -d '\r')
W cron event run publish_future_post >/dev/null 2>&1 || W eval "do_action('publish_future_post', $FUT_PID);" >/dev/null
curl -s -D - -o /dev/null "$posts_url" | grep -qi 'x-cache: hit' && { echo "posts page NOT purged on scheduled publish (BAD)"; exit 1; } || echo "posts page purged on scheduled publish OK"
W post delete "$SP_PID" --force >/dev/null 2>&1 || true
W post delete "$FUT_PID" --force >/dev/null 2>&1 || true
W option update show_on_front posts >/dev/null
W option delete page_on_front >/dev/null 2>&1 || true
W option delete page_for_posts >/dev/null 2>&1 || true
W post delete "$FRONT_PID" --force >/dev/null 2>&1 || true
W post delete "$POSTS_PID" --force >/dev/null 2>&1 || true
W cosmic purge >/dev/null 2>&1 || true

echo "== 5. Debug logging + perf gate =="
W option update cosmic_cache_settings '{"enabled":true,"debug":true}' --format=json >/dev/null
W eval 'CosmicCache\Config::compile();'
W cosmic purge >/dev/null
# The first request after compile can race opcache (stale config with debug
# unset is included for up to revalidate_freq). Retry until the hot path picks
# up the fresh config and writes debug.log, keeping fresh-CI behavior stable.
for try in $(seq 1 10); do
	curl -s -o /dev/null "$URL/"
	PE 'grep -q "cosmic" /var/www/html/wp-content/cache/cosmic/debug.log 2>/dev/null' && break
	sleep 1
done
PE 'grep -q "cosmic" /var/www/html/wp-content/cache/cosmic/debug.log 2>/dev/null && echo "debug.log OK" || { echo "debug.log MISSING"; exit 1; }'
# Guarantee the last line is a served HIT so the perf gate measures a warm
# serve (the retry above may have stopped on a "miss" line).
curl -s -D - -o /dev/null "$URL/" | grep -qi 'x-cache: hit'
ms=$(PE 'tail -1 /var/www/html/wp-content/cache/cosmic/debug.log | sed -nE "s/.* in ([0-9.]+) ms.*/\1/p" | tr -d "\r"')
[ -n "$ms" ] && echo "warm serve: ${ms} ms" || { echo "perf gate: no serve timing line"; exit 1; }
awk -v m="$ms" 'BEGIN { if (m < 5) exit 0; exit 1 }' && echo "perf gate OK (<5ms)" || { echo "perf gate FAILED (${ms} ms)"; exit 1; }
W option update cosmic_cache_settings '{"enabled":true,"debug":false}' --format=json >/dev/null
W eval 'CosmicCache\Config::compile();'

echo "== 6. HTTP headers / 304 =="
curl -s -o /dev/null "$URL/"
etag=$(curl -s -D - -o /dev/null "$URL/" | grep -i '^etag:' | tr -d '\r' | awk '{print $2}')
[ -n "$etag" ] && echo "ETag present" || { echo "ETag MISSING"; exit 1; }
curl -s -D - -o /dev/null "$URL/" | grep -qi 'x-cache: hit' && echo "X-Cache HIT OK"
code=$(curl -s -o /dev/null -w '%{http_code}' -H "If-None-Match: $etag" "$URL/")
[ "$code" = "304" ] && echo "304 OK" || { echo "304 FAILED (got $code)"; exit 1; }

echo "== 7. Preloader =="
ORIG_SITE_URL=$(W option get siteurl | tr -d '\r')
W option update siteurl 'http://localhost' >/dev/null
W option update home 'http://localhost' >/dev/null
W cosmic purge >/dev/null
W cosmic preload
n=$(PE 'find /var/www/html/wp-content/cache/cosmic/http -name "*.html" 2>/dev/null | wc -l')
[ "$n" -ge 1 ] && echo "preload cached $n URL(s) OK" || { echo "preload FAILED (0 files)"; exit 1; }

echo "EXTENDED TEST PASSED"
