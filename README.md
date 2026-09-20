<h1 align="center">Cosmic Cache</h1>

<p align="center">
  High-performance WordPress page cache with object cache (Redis/APCu),
  full optimization pipeline (combine, defer, critical CSS, delay JS,
  lazy load, CDN) and server-assisted body delivery (X-Sendfile /
  X-Accel-Redirect). Fail-open, no SaaS, no third-party requests.
</p>

---

## Why Cosmic Cache?

The free caching plugins available for WordPress felt bloated and, in some
cases, unstable. Since I use WordPress myself, I decided to build a solid
free alternative.

Initially developed for personal use, it is now published because caching is
an almost fundamental part of any website, and WordPress users deserve a free,
solid option for it.

### Built with AI assistance

This plugin was developed by a human maintainer with substantial AI-assisted
coding (boilerplate, refactors, tests) to reduce development time and effort.
Architecture decisions, security-sensitive code and every release were
human-reviewed, and the automated gates (PHPUnit, PHPStan, PHPCS, container
integration tests) must stay green. AI-assisted contributions are welcome
under the same bar — see CONTRIBUTING.md.



Performance-focused WordPress caching plugin:

- **Page cache served before WordPress boots** — a self-contained `advanced-cache.php`
  drop-in reads a compiled PHP config and serves static HTML (`.html`, with optional
  `.gz` / `.br` precompression) directly from disk, exiting before WP core loads
  (`did_action( 'init' )` is never reached on a cache hit).
- **Object cache drop-in** with Redis / APCu backends and an array fallback.
- **Asset optimization** pipeline: cleanup, CSS/JS combine, minify, JS defer,
  critical CSS, delay JS, lazy-load, emoji removal, DNS prefetch, query-string
  stripping.
- **CDN rewrite** for asset URLs.
- **Automatic purge** on post/term/comment/menu/theme changes, plus CLI + admin tools.

Requires PHP 8.0+. No runtime dependencies; dev-only Composer packages.



> **Single-site only.** Cosmic Cache is not yet supported on WordPress multisite.
> The object cache namespaces keys per `blog_id`, but that value is never
> initialized to the current site pre-boot, so a network-wide install would make
> every blog read site 1's keys (including `alloptions`). On multisite the plugin
> detects this and disables caching (fail-open); define
> `COSMIC_CACHE_ALLOW_MULTISITE` to opt in — at your own risk.

## Install

1. Copy the plugin folder to `wp-content/plugins/cosmic-cache` and activate it.
2. Activation writes:
   - `wp-content/advanced-cache.php` (generated from `dropins/advanced-cache.php`,
     with the hot-path `Vary` / `Bypass` / `Serve` classes inlined),
   - `WP_CACHE` constant in `wp-config.php` (when writable),
   - the compiled runtime config `wp-content/cache/cosmic/config.php`,
   - a daily cleanup cron event.
3. Open **Settings → Cosmic Cache**, enable the page cache, and adjust the rest.
   Changes regenerate `config.php` immediately.

Remove `WP_CACHE` / the drop-in yourself on deactivation conflicts; the plugin
restores both on deactivate and removes its own cache files on uninstall.

## Page cache hot path

`wp-content/cache/cosmic/config.php` is plain PHP (`return array(...)`) so the
drop-in never calls WP APIs. Files are stored under
`wp-content/cache/cosmic/<scheme>/<host>/[<blog_id>/]<sha1>[.vary-<sha1>].html`
plus precompressed `.gz` / `.br` siblings.

- **Cache keys** combine scheme, host, path, whitelisted query params, and vary
  suffixes (mobile, configured cookies, logged-in cookies).
- **Blog segment**: single-site installs store files with no blog segment; the
  compiled config carries a `host => blog_id` map so the pre-boot path resolves
  the segment identically on multisite.
- **Vary / bypass** honor mobile UA, cookies, query params, user agents, and
  URL/cookie exclusions (wildcards and `/regex/` supported).
- **Scheme detection** honors `X-Forwarded-Proto` (first comma-separated value;
  `https` / `on` → https, else http) so TLS terminates outside the origin.
- Headers on a hit: `X-Cache: HIT`, optional `X-Cosmic-Cache`, and
  `304 Not Modified` for conditional requests.
- With debug logging on, a serve hit is logged with the request time
  (`[cosmic-cache:serve] hit <file> in <N.NNN> ms`).

### Body delivery

The `serve_method` setting controls who sends the cached page bytes:

| Value | Behavior |
| --- | --- |
| `php` (default) | Drop-in uses `readfile()` — zero-config, works everywhere |
| `x-sendfile` | Apache `mod_xsendfile` — sends the absolute path via header |
| `x-accel-redirect` | Nginx — sends an internal URI (`/cosmic-cache/...`) |

With `x-sendfile` or `x-accel-redirect` the drop-in still computes the
cache key / TTL / encoding, but the header releases the bytes to the web
server (zero-copy). Unknown values and paths outside the cache root fall
back to `readfile()` (fail-open); the server configuration stays the
operator's responsibility (see `readme.txt` FAQ).

## Configuration

Constants (optional, applied at runtime on top of stored settings):

| Constant | Effect |
| --- | --- |
| `COSMIC_CACHE_ENABLE` | Force-enable the page cache |
| `COSMIC_CACHE_DISABLE` | Force-disable the page cache |
| `COSMIC_CACHE_TTL` | Cache lifetime in seconds |
| `COSMIC_CACHE_DIR` | Override the cache directory (default `WP_CONTENT_DIR/cache/cosmic`) |
| `COSMIC_CACHE_DEBUG` | Enable debug logging |
| `COSMIC_CACHE_ALLOW_MULTISITE` | Opt in to running on multisite (unsupported) |
| `COSMIC_CACHE_VERSION` | Plugin version (mirrored in settings panel) |

### Settings (option `cosmic_cache_settings`)

Page cache: `enabled`, `ttl`, `cache_mobile`, `cache_logged_in`, `cache_cookies`,
`bypass_query`, `cache_whitelist_query`, `bypass_user_agents`, `exclude_urls`,
`exclude_cookies`, `gzip`, `brotli`, `cache_headers`, `serve_method`,
`preload_enabled`, `preload_sitemap`, `preload_interval`, `purge_on_post`,
`purge_on_comment`, `purge_on_terms`, `show_admin_bar_button`.

Optimization: `minify_html`, `minify_css`, `minify_js`, `combine_css`, `combine_js`,
`defer_js`, `delay_js`, `delay_js_exclude`, `critical_css_enabled`, `critical_css`,
`critical_css_exclude`, `exclude_css`, `exclude_js`, `defer_exclude`, `lazyload`,
`lazyload_iframes`, `remove_emoji`, `dns_prefetch`, `remove_query_strings`.

> **Experimental.** HTML minification (`minify_html` master switch) is
> **off by default** — the pipeline never rewrites HTML unless you opt in,
> and `minify_css`/`minify_js` are ignored without it. `critical_css` and
> `delay_js` are also experimental; validate output after enabling.
> The pasted critical CSS is never auto-regenerated — theme/plugin updates
> flag it as possibly stale in the admin (with snippet provenance shown in
> the Optimization tab) until you paste a fresh snippet. The "Generate"
> button (or `wp cosmic critical-css`) drafts a heuristic snippet from the
> homepage for review: nothing is ever applied automatically.
> `lazyload`/`lazyload_iframes` and `remove_query_strings` may mis-handle
> edge-case HTML.

CDN: `cdn_url`, `cdn_exclude`. Object cache: `object_cache_enabled`,
`object_cache_backend`, `object_cache_host`, `object_cache_port`,
`object_cache_prefix`. Debug: `debug`.

> **Experimental.** CDN rewriting targets asset `src`/`href` attributes only
> (stylesheet, icon, preload, prefetch, and script/img tags). Page-level `<link>`
> tags (canonical, next/prev, RSS, `wp-json`) and inline scripts are never
> rewritten. Validate the rewritten page after enabling.

The object-cache drop-in supports group-scoped invalidation via
`wp_cache_flush_group()` (advertised through `wp_cache_supports('flush_group')`).
Regenerating the drop-in (settings save or activation) drops the Redis prefix so
it never serves data left over from before it existed.

Deactivation restores the site: it removes `advanced-cache.php` and
`object-cache.php` (only when Cosmic generated them), strips the `WP_CACHE`
define it added, clears its cron hooks and cache directory, and reactivation
regenerates the drop-ins and a fresh `WP_CACHE` define.

### Browser caching

When the page cache is enabled, the plugin writes an `Expires` / `Cache-Control`
block into the root `.htaccess` (Apache) for versioned static assets (`?ver=`):
fonts, CSS and JS (1 year, immutable), images and other assets (1 month,
non-immutable since content uploads carry no version string). Two mechanisms
with the same TTLs: `ExpiresByType` (`mod_expires`) plus a `mod_headers`
`FilesMatch` fallback, because `mod_expires` is commonly disabled on shared
hosting while `mod_headers` is not. The block is idempotent and
removed on deactivation. For Nginx, add equivalent `location` rules (see FAQ in
`readme.txt`).

### WP-CLI

```
wp cosmic status                # settings + drop-in + cache state
wp cosmic purge [<url> ...]     # purge all, or specific URLs (prefix/regex via filters)
wp cosmic preload               # warm the cache (sitemap or site crawl)
wp cosmic critical-css [--url=<url>] [--apply] [--force]  # heuristic critical-CSS draft (manual-only)
wp cosmic debug [on|off]        # enable/disable debug logging
```

Example:

```
wp cosmic purge
wp cosmic purge https://example.com/hello-world/
wp option update cosmic_cache_settings '{"enabled":true,"ttl":7200}' --format=json
```

## Hooks

Filters:
- `cosmic_cache_is_cacheable` — override the hot-path bypass decision.
- `cosmic_cache_html_stage_{cleanup,combine,criticalcss,minify,defer,delayjs,lazyload,cdn}`
  and `cosmic_cache_html` — HTML pipeline stages (fixed order).
- `cosmic_cache_purge_urls` — adjust URLs purged for a given event.
- `cosmic_cache_preload_urls` — adjust the URL list before a preload run.
- `cosmic_cache_cdn_rewrite` — post-process CDN-rewritten HTML.
- `cosmic_cache_settings` — let integrations adjust resolved settings.
- `cosmic_cache_integrations` — register or replace integration classes.
- `cosmic_cache_critical_provider` — replace the heuristic critical-CSS draft with e.g. a headless-Chromium result.

Actions:
- `cosmic_cache_stored` — after a file is written to disk.
- `cosmic_cache_preloaded` — after a preload run completes.
- `cosmic_cache_purged_all` / `cosmic_cache_purged_urls` — after purge operations.
- `cosmic_cache_cleanup` — daily cron for TTL-based file cleanup.
- `cosmic_cache_preload` — scheduled preload run (when enabled).

Integrations ship for WooCommerce, Elementor, and WPML (conditional on the
plugin being active; e.g. WooCommerce cart pages bypass the cache, Elementor
CSS/JS are excluded from combining, WPML URL variants purge together).

## Development

```bash
composer install          # dev only (PHPUnit)
composer run lint         # php -l over src/dropins/bootstrap
vendor/bin/phpunit --no-coverage
```

### Container integration test

A `wordpress:php8.4-apache` + MariaDB + Redis stack runs the plugin against a
live install (includes brotli/APCu/Redis and wp-cli):

```bash
cd tests/container
podman-compose up -d --build   # wordpress on http://localhost:18080
# first run: create wp-config, install WordPress, activate cosmic-cache
bash tests/integration/smoke.sh container_wordpress_1
```

The smoke test verifies the cache is written on the first request, served
`X-Cache: HIT` before WP boots on the second, `config.php` regeneration on save,
post-save purge, and `wp cosmic` commands.

```bash
bash tests/integration/extended.sh container_wordpress_1
```

The extended test covers the object-cache drop-in (Redis round-trip), the
optimization pipeline on real HTML (lazy-load, CDN rewrite, defer, minify),
byte-identical output with optimization disabled, purge on an unpublish
transition, bounded debug logging + warm-serve time (SPEC §17 perf gate),
`ETag`/`If-None-Match` → `304`, and the preloader.

```bash
bash tests/integration/deactivate.sh container_wordpress_1
```

The deactivation test generates both drop-ins and a `WP_CACHE` define, warms a
page, deactivates, asserts every artifact (drop-ins, define, cron hooks, cache
directory) is removed while the front end still serves `200`, then reactivates
and confirms the drop-ins regenerate.

## Layout

- `cosmic-cache.php` — bootstrap + activation hooks
- `src/Plugin.php` — wiring, drop-in generation, cron
- `src/Config.php` — schema, sanitize, compile/read
- `src/AtomicWriteTrait.php` — shared atomic file write (temp + flock + rename)
- `src/Autoloader.php` — PSR-4 autoloader for `CosmicCache\`
- `src/Cache/` — `Vary`, `Bypass`, `Serve` (hot path), `PageCache`, `Purger`,
  `Preloader`, `Storage/Disk`
- `src/Optimization/` — `Cleanup`, `Combine`, `CriticalCSS`, `CriticalGenerator`,
  `CssParser`, `AssetFetch`, `Minify`, `DeferJS`, `DelayJS`, `LazyLoad`
- `src/ObjectCache/`, `src/Cdn/`, `src/Admin/`, `src/Cli/`, `src/Integration/`
- `dropins/` — drop-in templates; `views/` + `assets/` — admin UI
- `languages/` — i18n files (`.pot`, `.po`, `.mo`); `bin/` — build scripts

## License

GPL-2.0-or-later.
