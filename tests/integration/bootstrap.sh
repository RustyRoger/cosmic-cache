#!/usr/bin/env bash
# Integration bootstrap: turn a freshly-started WordPress container into a
# usable test site. Idempotent, so it is safe to re-run before each suite (and
# cheap when the site is already installed). Container-runtime aware like the
# other integration scripts (podman locally, docker on CI):
#   - installs WordPress core if missing
#   - activates cosmic-cache
#   - ensures at least one published post exists (smoke.sh relies on it)
#   - purges whatever cache may still be present
#
# Usage (from repo root):
#   bash tests/integration/bootstrap.sh [container-name]
set -euo pipefail

CONTAINER="${1:-container_wordpress_1}"
CTR="${COSMIC_CTR:-$(command -v podman 2>/dev/null || command -v docker 2>/dev/null)}"
CTR="${CTR:-podman}"
W() { "$CTR" exec "$CONTAINER" wp --allow-root --path=/var/www/html "$@"; }
PE() { "$CTR" exec "$CONTAINER" sh -c "$1"; }

echo "== 1. WordPress core =="
if W core is-installed >/dev/null 2>&1; then
	echo "WP already installed"
else
	# The compose maps the site to host port 18080; installing at that URL
	# keeps canonical redirects and the host header identical to what the
	# test scripts hit. (Vary::sanitizeHost ignores the port for cache paths.)
	W core install \
		--url=http://localhost:18080 \
		--title="Cosmic Cache" \
		--admin_user=admin \
		--admin_password=cosmic \
		--admin_email=admin@example.com \
		--skip-email
	echo "WP installed"
fi

echo "== 2. Plugin activation =="
W plugin activate cosmic-cache >/dev/null
echo "cosmic-cache active"

echo "== 3. Opt-in (enabled) =="
# The plugin's default is disabled (fail-open); bootstrap performs the same
# opt-in the admin panel would, so a fresh CI site actually caches.
W option update cosmic_cache_settings '{"enabled":true}' --format=json >/dev/null
W eval 'CosmicCache\Config::compile();' >/dev/null
echo "caching enabled"

echo "== 4. Seed post =="
count=$(W post list --post_type=post --post_status=publish --format=count)
if [ "$count" -ge 1 ] 2>/dev/null; then
	echo "seed post present ($count)"
else
	W post create --post_type=post --post_status=publish --post_title="Bootstrap Post"
	echo "seed post created"
fi

echo "== 5. Clean slate =="
W cosmic purge >/dev/null 2>&1 || PE 'rm -rf /var/www/html/wp-content/cache/cosmic' || true
echo "cache purged"

echo "BOOTSTRAP DONE"