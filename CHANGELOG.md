# Changelog

All notable changes to Cosmic Cache are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.3] — 2026-09-09

### Fixed

- **Release packaging**: `bin/build.sh` omitted `dropins/`, so every admin
  zip upload installed a plugin that could not generate its drop-ins at
  activation (PHP warnings as "unexpected output" + missing drop-in
  notice) — including the v1.1.2 zip. The templates ship again, the build
  fails closed when a runtime file is missing from the zip, and the
  release workflow asserts the zip contents. New `PackagingTest` pins the
  runtime file list.
- **Silent activation I/O**: template reads (`write_advanced_cache()`,
  object-cache generator, `source()`) and `wp-config.php` reads/writes no
  longer emit warnings on failure — incomplete uploads or unwritable files
  now surface as clean admin notices (new `advanced_cache_write_failed`
  message) instead of "unexpected output" during activation.
- **Browser caching without mod_expires**: image/CSS/JS caching relied
  solely on `ExpiresByType`, so hosts without `mod_expires` (common on
  shared hosting) served them with no cache headers at all — Lighthouse
  "Use efficient cache lifetimes" flagged megabytes. The `.htaccess` block
  now adds a `mod_headers` `FilesMatch` fallback with the same TTLs
  (CSS/JS 1 year immutable, content images 1 month non-immutable).

## [1.1.2] — 2026-09-09

### Added

- **Release automation**: new `release.yml` workflow — pushing a `v*` tag
  runs a version-coherence guard (tag vs plugin header vs `readme.txt`
  Stable tag vs CHANGELOG section, blocking on mismatch), rebuilds
  `cosmic-cache.zip` from scratch and publishes the GitHub Release with
  notes extracted from the CHANGELOG section.

## [1.1.1] — 2026-09-09

### Added

- **Critical CSS generator (heuristic, manual-only)**: new opt-in
  `CriticalGenerator` + `CssParser` (zero dependencies, no browser, no SaaS)
  that analyzes the homepage stylesheets, matches selectors against the
  above-the-fold DOM (header/nav/hero + bounded body prefix) and saves a
  draft with metrics (rules kept/dropped, bytes, warnings) for human review.
  Conservative by design: pseudos, attribute selectors and anything unjudged
  are kept; print-only rules dropped. Budgets: 5 URLs, 1 MB CSS, 20 s, 30 KB
  inline cap (force to override). Generate from the Optimization tab
  (AJAX, ext-dom required with graceful message) or
  `wp cosmic critical-css [--url=..] [--apply] [--force]`; Apply backs up
  for one-click revert and reuses the stale/provenance tracking below.
  A `cosmic_cache_critical_provider` filter lets VPS owners plug a
  headless-Chromium result in. 10 unit tests.

## [1.1.0] — 2026-09-09

### Added

- **Critical CSS staleness tracking**: the pasted snippet is now flagged as
  possibly stale when the theme switches, a plugin/theme update installs,
  the Customizer saves, or Elementor regenerates styles (`switch_theme`,
  `upgrader_process_complete`, `customize_save_after`,
  `elementor/editor/after_save`). An admin notice links to the Optimization
  tab while the feature is in use; saving a fresh snippet clears the flag
  and records provenance (hash, date, theme/version) shown under the
  Critical CSS fields. Fail-open throughout: nothing is ever auto-disabled.
  4 unit tests.

### Fixed

- Saving optimization or CDN settings now purges the page cache: the purge
  key list (`SettingsPage::html_purge_keys()`) covers the whole HTML
  pipeline (minify/combine/defer/delay/critical/lazyload, excludes lists,
  `remove_emoji`, `dns_prefetch`, `remove_query_strings`) and the CDN
  rewrite, not just the page-cache keys. Previously a new critical snippet
  (or combine/defer change) left old HTML on disk until TTL/purge.

### Documentation

- `COMPARISON.md` aligned to 1.0.3: server-assisted body delivery via
  X-Sendfile / X-Accel-Redirect marked as implemented (opt-in, requires a
  server module/location); "Remove unused CSS" stays an honest `✗` — the
  local-heuristic approach was rejected to keep the no-SaaS / fail-open
  guarantee.
- Repo hygiene: English README/CHANGELOG, LICENSE, CONTRIBUTING,
  SECURITY.md; CI update; integration-test fixes (byte-identical trap
  restore, portable temp dir); README updates.

## [1.0.3] — 2026-08-17

### Added

- **Server-assisted body delivery (X-Sendfile / X-Accel-Redirect)**: new
  `serve_method` option (default `php`). With `x-sendfile` (Apache
  `mod_xsendfile`) or `x-accel-redirect` (Nginx, `internal` location
  `/cosmic-cache/`) the drop-in still computes the key/TTL/encoding but the
  header releases the bytes to the web server (zero-copy). Unknown values and
  paths outside the cache root fall back to `readfile()` (fail-open); the
  server configuration stays the operator's responsibility (FAQ in
  readme.txt). 4 unit tests + integration script `tests/integration/sendfile.sh`.

## [1.0.2] — 2026-08-17

### Added

- **Delay JS**: opt-in optimization (`delay_js`) that turns classic scripts
  into delayed loading (scroll/mousedown/touchstart/keydown, with a 3s
  fallback). Ignores JSON-LD/JSON, `async`/`defer`/`module`, inline scripts
  and scripts in `delay_js_exclude`. Stage
  `cosmic_cache_html_stage_delayjs`, 4 unit tests, i18n strings.
- **Critical CSS**: opt-in optimization (`critical_css_enabled`) that injects
  critical CSS inline before the first stylesheet and loads the full sheet via
  `rel="preload"` + `onload` swap with a `<noscript>` fallback. Respects
  existing `preload`/`onload` headers, `media != all` and
  `critical_css_exclude`. New helper `cosmic_field_textarea`, 5 unit tests,
  i18n strings.
- **`COMPARISON.md`**: documentation comparison matrix (2026 data) with
  WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest
  Cache, FlyingPress, Cache Enabler and Hummingbird, plus an honest "where
  Cosmic Cache stands" section and live benchmarks.

### Verification

- Pipeline updated: `cleanup → combine → criticalcss → minify → defer →
  delayjs → lazyload → cdn`.
- Empirical checks closed on the live container (WP 7.0.2): homepage
  byte-identical with X-Cache HIT; REST post-save purge (block editor) and
  REST posts; uninstall without leftovers (drop-in, cache dir, `WP_CACHE`
  marker, options, cron); expired TTL treated as a miss and file regenerated;
  disk cap with oldest-first eviction.
- `ab -n 500 -c 10` in-container benchmark: uncached 173 req/s (~58 ms) vs
  cached hit 13 826 req/s (~0.7 ms) — ~80x; pre-boot serve confirmed
  (0.07–0.13 ms).

## [1.0.1] — 2026-08-17

### Fixed

- Settings persistence: saving one tab of the admin page no longer resets the
  settings of the other tabs. `SettingsPage::sanitize()` now merges POST input
  onto saved values instead of restarting from defaults (regression test in
  `AdminTest`).
- Removal of the `example.com` placeholders from the plugin header and
  translation file headers (no reference site available yet).

### Improved

- Complete Italian localization (`cosmic-cache-it_IT.po`/`.mo`, 129 strings):
  the panel follows the site locale automatically; English stays the primary
  language. `build.sh` compiles every `.po` and removes orphaned `.mo` files.
- `ttl` default raised to 86400 s (24h): purge events already handle
  invalidation and the serve-time TTL removes expired files.
- Field descriptions rewritten (EN + IT): TTL expiry, query whitelist,
  gzip/brotli costs, CSS/JS combine risks, lazy-load, Redis configuration.
- Numeric fields with `min`/`max`/`step` constraints in the markup (`ttl`,
  `preload_interval`, `object_cache_port`).

## [1.0.0] — 2026-08-15

Initial release. Implements the full pipeline described in the project spec.

### Page cache (hot path)

- `advanced-cache.php` drop-in compiled from source, serving pages from disk
  before WordPress boots.
- Fail-open dispatch: any hot-path error lets WordPress start.
- `Content-Encoding` negotiation with precompressed gzip/brotli variants.
- `ETag`/`Last-Modified` with 304 and correct HEAD handling.
- Serve-time TTL: an expired file is removed (all variants) and treated as a
  miss.
- Variation for mobile, cookies and logged-in users (`.vary-<hash>`).
- Two-level bypass (hot path + capture) with query whitelist.

### Storage and integrity

- Atomic writes (`flock` + rename) and per-run GC budget.
- Disk cap (50 000 entries) with oldest-first eviction and orphaned `.tmp`
  sweep.
- `config.php` invalidated via `opcache_invalidate()` on every rewrite.
- Complete purge on post/terms/comments including paginated variants,
  tag/author/date archives and paginated home.
- Symlink-safe `delete_tree()`.

### Object cache (optional)

- `object-cache.php` drop-in on APCu, Redis and Memcached.
- Prefix-scoped flush (never a global `apcu_clear_cache()`).
- `incr`/`decr` read-modify-write, `replace`/`exists`, backend aliasing as
  `wp_object_cache`.

### Services

- Preloader with persistent transient queue, bounded batch, overlap lock
  (stale timeout), `robots.txt` support (User-agent groups, prefix-match) and
  `stored` metrics based on real disk writes.
- Drawtime asset optimization (combine, defer, lazy-load, CDN).
- `wp cosmic` CLI (purge, preload), admin bar, cleanup cron.

### Security

- `ABSPATH` and minimum PHP version guards also in compiled code.
- SSRF guard in the preloader sitemap crawl.
- Permission verification (capability/user) in destructive CLI commands.

### Tests

- 160+ unit test suite with a WordPress-less bootstrap (WP stubs).
- Regressions for hot path, purge, storage, preloader and hardening.
- End-to-end integration suite (smoke/extended/deactivate) verified on a real
  podman container from a wiped volume.
- Lint on all PHP files; PHP 8.0–8.5 CI matrix.

### Packaging and quality

- `readme.txt`, `CHANGELOG.md` and `languages/cosmic-cache.pot` (124 strings)
  generated by `bin/extract-pot.php` without wp-cli.
- `bin/build.sh` produces `cosmic-cache.zip` (runtime only; no tests/dev).
- PHPStan level 5 (official WP/Woo/CLI stubs) and PHPCS (hygiene gate) green.
- Separate CI jobs: 8.0–8.5 matrix, static analysis, integration (docker) and
  zip build on `v*` tags.

### Pre-release hardening (2026-08-15)

- Integration suite bootstrap: idempotent `tests/integration/bootstrap.sh`
  (installs WP, activates the plugin, opts in `enabled:true`, seeds a post,
  purges), wired into the `integration` CI job before smoke.
- Fixes from the clean-container run: warm-up/retry in `smoke.sh` and
  `extended.sh` for the opcache race after `config.php` recompilation.
- `Debug.log` now honors `COSMIC_CACHE_DIR` (no longer hardcoded).
- CLI: `wp cosmic purge`/`preload` require confirmation (`WP_CLI::confirm()`)
  without `--yes` when an authenticated user exists.
- `cosmic_cache_write_failures` counter capped at 1000 and reset on every
  successful store.
- `Accept-Encoding` with q-values (`br;q=0`): a variant disabled by the client
  is never served.
- Browser caching of versioned static files (`?ver=`): `mod_expires`/
  `mod_headers` block written into the root `.htaccess` (idempotent
  install/remove, follows the `enabled` flag), with Nginx FAQ snippet.
  Resolves the Lighthouse "Use efficient cache lifetimes" audit flagged on the
  live container.

[Unreleased]: https://github.com/RustyRoger/cosmic-cache/compare/v1.1.3...HEAD
[1.1.3]: https://github.com/RustyRoger/cosmic-cache/compare/v1.1.2...v1.1.3
[1.1.2]: https://github.com/RustyRoger/cosmic-cache/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/RustyRoger/cosmic-cache/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/RustyRoger/cosmic-cache/compare/v1.0.3...v1.1.0
[1.0.3]: https://github.com/RustyRoger/cosmic-cache/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/RustyRoger/cosmic-cache/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/RustyRoger/cosmic-cache/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/RustyRoger/cosmic-cache/releases/tag/v1.0.0
