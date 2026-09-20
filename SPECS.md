# Cosmic Cache — Technical Specification

Performance-focused WordPress caching plugin (page cache, object cache, asset optimization, CDN rewrite), written in pure PHP 8.0+, namespaced `CosmicCache\`, inspired by WP Rocket but built to serve cache hits **before** WordPress boots.

---

## 1. Overview

Cosmic Cache accelerates WordPress sites by:

1. Serving pre-rendered static HTML from disk **before** the WordPress core loads (via the `advanced-cache.php` drop-in), with precompressed gzip/brotli variants and correct HTTP caching headers.
2. Providing an opt-in object-cache drop-in backed by ext-redis or APCu.
3. Optimizing page assets (minify/combine/defer/lazy-load) at cache-write time.
4. Rewriting static asset URLs to a CDN.
5. Giving site operators a tabbed admin UI and WP-CLI commands.

**Unique selling point:** the hot path (cache hit) involves zero WP API calls — a plain PHP config array drives a regex-only vary-key check, then `readfile()`.

---

## 2. Goals

- Serve a cache hit in <5ms PHP execution (before WP boots).
- Zero external runtime dependencies (pure PHP; optional ext-redis/APCu).
- Fully automated cache invalidation on content changes.
- Safe on shared hosting (no root, no system services).
- Broad compatibility: WordPress ≥ 6.0, PHP 8.0 – 8.5, nginx/Apache/LiteSpeed, PHP-FPM/mod_php.

---

## 3. Non-Goals

- Not a full CDN; only URL rewriting to an existing CDN.
- Critical CSS is an integration stub only (external engine required).
- Not a managed image optimizer; only lazy-load + placeholder.
- No bundled pure-PHP Redis client; object cache requires `ext-redis`/APCu or an existing object-cache drop-in.
- No multisite support at v1. Caching is disabled on multisite unless explicitly opted in via `COSMIC_CACHE_ALLOW_MULTISITE` (unsupported): the pre-boot hot path cannot resolve the blog ID, so network-enabled installs would leak site 1's keys (including `alloptions`) to every blog. `Config::compile()` bakes `multisite_disabled` when not opted in and the serve path fails open.

---

## 4. Terminology

| Term | Meaning |
|---|---|
| Hot path | Request served from cache without booting WordPress |
| Capture path | Normal WP request that renders HTML and stores it to cache |
| Vary key | Suffix segment encoding request variants (mobile, cookies) |
| Drop-in | WP convention file in `wp-content/` overriding core (`advanced-cache.php`, `object-cache.php`) |
| Cache root | `WP_CONTENT_DIR . '/cache/cosmic/'` |
| Config file | `cache/cosmic/config.php` — compiled plain-PHP array read by the drop-in |

---

## 5. Directory Layout

```
cosmic-cache/
├── cosmic-cache.php              # Main file: header, constants, bootstrap, activation
├── uninstall.php               # Cleanup on manual delete
├── SPECS.md
├── README.md
├── src/
│   ├── Autoloader.php          # PSR-4 autoloader for CosmicCache\
│   ├── Plugin.php              # Container, wiring, hook registration
│   ├── Config.php              # Settings model, defaults, config writer/reader
│   ├── Cache/
│   │   ├── PageCache.php       # Store/purge lifecycle, decision logic
│   │   ├── Serve.php           # readfile + headers (shared with drop-in)
│   │   ├── Vary.php            # Compute vary key from URI/UA/cookies
│   │   ├── Bypass.php          # Rules for excluding requests
│   │   ├── Purger.php          # Full/URL/prefix/regex purges
│   │   ├── Preloader.php       # Background crawl to warm cache
│   │   └── Storage/Disk.php    # File naming, atomic writes, TTL, gzip/brotli
│   ├── ObjectCache/
│   │   ├── Status.php          # Detect drop-ins, benchmark, health
│   │   └── DropInGenerator.php # Opt-in object-cache.php (ext-redis/APCu)
│   ├── Optimization/
│   │   ├── Minify.php          # CSS/JS regex minifier
│   │   ├── Combine.php         # Merge assets (order + media aware)
│   │   ├── DeferJS.php         # async/defer, remove render-blocking
│   │   ├── LazyLoad.php        # Images/iframes → data-src + placeholder
│   │   └── Cleanup.php         # Emoji removal, DNS-prefetch, query-string strip
│   ├── Cdn/Cdn.php             # Asset URL rewrite
│   ├── Admin/
│   │   ├── SettingsPage.php    # Tabbed UI, Options API integration
│   │   ├── PurgeController.php # AJAX purge handlers + nonces
│   │   └── Notice.php          # Admin notices
│   ├── Cli/Command.php         # WP-CLI commands
│   └── Integration/
│       ├── WooCommerce.php
│       ├── Elementor.php
│       └── WPML.php
├── dropins/
│   ├── advanced-cache.php      # Source of the hot-path drop-in
│   └── object-cache.php        # Source template for object-cache drop-in
├── views/                      # Admin templates (escaped PHP)
├── assets/                     # Admin CSS/JS (vanilla, no build step)
└── tests/                      # php -l, unit, wp-env smoke tests
```

---

## 6. Configuration

### 6.1 Storage model

- Source of truth: `wp_options` (option key `cosmic_cache_settings`, autoloaded).
- Compiled runtime config: `cache/cosmic/config.php` returning a plain array — this is what the drop-in reads (no DB, no WP bootstrap).
- Settings are versioned; the config file is regenerated on save and on plugin upgrade.
- Constants (Section 6.3) take precedence over stored settings and always win.

### 6.2 Settings schema (defaults)

```php
[
    // Page cache
    'enabled'                => false,   // master switch
    'ttl'                    => 86400,   // seconds; 0 = until purged
    'cache_mobile'           => false,   // separate mobile vary key
    'cache_logged_in'        => false,   // cache for logged-in users
    'cache_cookies'          => [],      // cookie names that split the vary key
    'bypass_query'           => [],      // query params that force bypass
    'cache_whitelist_query'  => [],      // query params allowed to be cached
    'bypass_user_agents'     => [],      // regex UA bypass list
    'exclude_urls'           => [],      // URL/regex patterns never cached
    'exclude_cookies'        => [        // cookie presence forces bypass
        'wordpress_logged_in_*',
        'comment_author_*',
        'wp_woocommerce_session_*',
    ],
    'gzip'                   => true,
    'brotli'                 => true,    // only used if brotli_compress() exists
    'cache_headers'          => true,    // emit Cache-Control/ETag/Last-Modified
    'preload_enabled'        => false,
    'preload_sitemap'        => '',      // sitemap URL to crawl
    'preload_interval'       => 86400,
    'purge_on_post'          => true,    // purge URLs on post save/trash
    'purge_on_comment'       => true,
    'purge_on_terms'         => true,
    'show_admin_bar_button'  => true,    // "Clear cache" in the admin toolbar

    // Optimization
    'minify_html'            => false,   // master gate for the Minify stage
    'minify_css'             => false,   // requires minify_html
    'minify_js'              => false,   // requires minify_html
    'combine_css'            => false,
    'combine_js'             => false,
    'defer_js'               => false,
    'exclude_css'            => [],      // patterns skipped by all CSS processing
    'exclude_js'             => [],      // patterns skipped by all JS processing
    'defer_exclude'          => [],      // script handles to exclude
    'lazyload'               => false,   // experimental
    'lazyload_iframes'       => false,   // experimental
    'remove_emoji'           => false,
    'dns_prefetch'           => [],
    'remove_query_strings'   => false,   // experimental

    // CDN
    'cdn_url'                => '',      // rewrite target host
    'cdn_exclude'            => [],      // dirs/extensions excluded from rewrite

    // Object cache
    'object_cache_enabled'   => false,   // enables generated object-cache drop-in
    'object_cache_backend'   => 'redis', // redis|apcu
    'object_cache_host'      => '127.0.0.1',
    'object_cache_port'      => 6379,
    'object_cache_prefix'    => '',      // optional stable prefix override

    // Debug
    'debug'                  => false,   // log drop-in decisions; COSMIC_CACHE_DEBUG overrides
]
```

List-shaped defaults are hardcoded; stored settings extend rather than replace them (e.g. `exclude_cookies`, `cache_whitelist_query`).

### 6.3 Constants that override settings

- `COSMIC_CACHE_DISABLE` — hard off.
- `COSMIC_CACHE_ENABLE` — force on.
- `COSMIC_CACHE_TTL` — override TTL.
- `COSMIC_CACHE_DIR` — override cache root (the `config.php` file and all cached files live under this dir when set).
- `COSMIC_CACHE_DEBUG` — force debug logging on regardless of the stored `debug` setting.

Constants are evaluated by the drop-in **at request time** (never baked into `config.php`): `COSMIC_CACHE_DISABLE` makes the drop-in bail immediately, `COSMIC_CACHE_ENABLE` forces serving even when the stored `enabled` flag is false, and `COSMIC_CACHE_TTL` overrides the stored TTL for header generation.

---

## 7. Page Cache Engine

### 7.1 Lifecycle

1. **Activation**: verify `WP_CONTENT_DIR` writable; create cache root; write `wp-content/advanced-cache.php`; add `define('WP_CACHE', true)` to `wp-config.php` only if absent (removed on deactivation only if Cosmic added it). If `wp-config.php` is not writable and `WP_CACHE` is not already defined, activation fails **softly**: cache stays disabled and an admin notice explains the fix — never a hard error.
2. **Capture**: on `template_redirect` (WP booted), if caching enabled and request cacheable → start output buffer.
3. **Store**: on `shutdown`, buffer is post-processed (optimization pipeline, Section 9), stored atomically, gzip/brotli variants precompressed.
4. **Serve**: subsequent request → drop-in handles it and exits before WP core loads.

### 7.2 Bypass rules

Two layers, kept intentionally separate:

**Hot-path (drop-in, no WP) — URI/regex only.**
- Method is not `GET`/`HEAD`.
- `$_GET` contains a parameter not on the whitelist (default whitelist: `p`, `page_id`, `page`, `cat`, `tag`, `author`, `m`, `year`, `monthnum`, `day`, `w`, `cpage`, `orderby`, `order`, `paged`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, `gclid`, `fbclid`, `ref`, `mc_cid`) and not on `cache_whitelist_query`. Note: `s` (search), `preview`, and `feed` are deliberately **not** whitelisted — they bypass.
- `$_GET` contains any param in `bypass_query`, or a `_wpnonce` parameter.
- Any `$_COOKIE` name matches `exclude_cookies` (defaults cover `wordpress_logged_in_*`, `comment_author_*`, `wp_woocommerce_session_*`; stored values extend the list). The `wordpress_logged_in_*` rule only applies when `cache_logged_in` is false; when `cache_logged_in` is true that cookie moves to the vary key instead (see 7.3).
- Path matches `exclude_urls`; UA matches `bypass_user_agents`.
- Path is `wp-admin`, `wp-login.php`, `wp-json`, `robots.txt`, `/feed/` (or any URL with a `feed` query param), or post preview.
- URL contains a cache-bust param (default `?nocache`).

**Capture-path (WP booted) — state-aware.**
- `is_user_logged_in()` and `cache_logged_in` false.
- Response is not `200` (redirect/404/error), or is an admin/feed/REST response.
- `is_404()`, `is_feed()`, `is_search()`, `is_trackback()`, `is_preview()`.
- `post_password_required()` (password-protected posts).
- Request is a POST or contains a nonce submission.

All rules can be overridden via `cosmic_cache_is_cacheable` filter. The filter runs only on the **capture path** (WordPress is booted); the **hot path** cannot consult it because the drop-in runs before the WP API exists. As a result, the filter influences what is *stored* (and therefore what a subsequent hot-path hit may serve), never the drop-in's own serve decision. When any hot-path rule triggers, the drop-in skips serving; when any capture-path rule triggers, the page is served live and never stored.

### 7.3 Vary key

Computed without the WP API on the hot path (regex on `$_SERVER`):

- Scheme: `http` | `https`
- Host: sanitized `HTTP_HOST`
- Path: lowercased normalized path + whitelisted query string in canonical order (whitelisted params kept, sorted by key via `ksort`, values URL-encoded, all others dropped)
- Variants appended as hashed suffixes: `mobile` (UA regex, gated by `cache_mobile`), plus the value of each cookie in `cache_cookies`, plus the `wordpress_logged_in_*` cookie value when `cache_logged_in` is true, plus `lang` cookie when WPML integration is active.

The default mobile UA regex is `iPhone|iPod|iPad|Android|Mobile|Opera Mini|Opera Mobi|IEMobile|Windows Phone|BlackBerry|Kindle|Silk|webOS|Fennec`.

Final cache path:

```
cache/cosmic/<scheme>/<host>/[<blog_id>/]<sha1(hashable-key)>[.vary-<hash>].html
```

The `<blog_id>` component is present only in multisite installs, keeping per-site caches isolated. `.vary-` segments are only present when variants are configured, keeping the default case to a single file per URL.

### 7.4 Hot-path serve (`Serve.php` + drop-in)

The drop-in written to `wp-content/advanced-cache.php` is **self-contained**: `Serve.php` is compiled into the drop-in at write time (activation / upgrade), so the hot path reads only the cache root, `config.php`, and the cached files — no requires into the plugin directory. `Serve.php` in `src/` is kept as the canonical source that the drop-in is generated from, keeping the two in sync.

1. Include `config.php` from the cache root (see `COSMIC_CACHE_DIR`); then apply constants: `COSMIC_CACHE_DISABLE` → bail; `COSMIC_CACHE_ENABLE` → serve even if `enabled` is false; otherwise if `enabled` is false → bail silently (normal WP flow).
2. Run Vary + Bypass regex checks (no WP boot).
3. Resolve cache file; negotiate encoding → prefer `.html.br` → `.html.gz` → `.html`.
4. Emit headers:
   - `Content-Type: text/html; charset=UTF-8`
   - `Content-Encoding` (when compressed)
   - `Vary: Accept-Encoding` only when gzip or brotli is enabled (omitted when both are off) (+ `Vary: Cookie, User-Agent` when variants active)
   - `Cache-Control`: `public, max-age=<ttl>` when `ttl > 0`; when `ttl = 0` (purge-only mode) emit `public, max-age=0, must-revalidate` and rely on ETag/304.
   - `Last-Modified`
   - `ETag` (sha1 of mtime+size)
   - `X-Cache: HIT`
5. Honor `If-Modified-Since` / `If-None-Match` → reply `304` with no body.
6. For `HEAD` requests, emit headers only (no body). Otherwise `readfile()` then `exit()`.

### 7.5 Storage (`Storage/Disk.php`)

- **Atomic writes**: write to `.<name>.tmp`, then `rename()`.
- **File locking**: `LOCK_EX` on write; read via `readfile` (no lock).
- **TTL/GC**: files carry mtime; daily cron `cosmic_cache_cleanup` deletes expired files in bounded batches (≤1000 per run; skipped entirely when `ttl = 0`).
- **Precompression**: at store time, `gzip` → `gzencode()`; `brotli` → `brotli_compress()` when available.

### 7.6 Purge (`Purger.php`)

- **Full purge**: recursively delete cache root.
- **URL purge**: remove the exact file, all `.vary-` variants, and every whitelisted-query path hash of the same URL (e.g. comment pages, paginated archives), so a single event invalidates every variant of that URL.
- **Prefix purge**: delete a subtree (taxonomy archives, paged archives).
- **Regex purge**: bounded iteration over matching paths.

The `purge_on_post`, `purge_on_comment`, and `purge_on_terms` settings gate their respective hook groups below.

Hook triggers (each filterable via `cosmic_cache_purge_urls`):

- `transition_post_status` — purges whenever a post leaves the `publish` status (publish → draft/private/etc.), so unpublishing never leaves a stale page. `save_post` / `wp_trash_post` / `untrash_post` / `delete_post` → permalink + home + associated term archives (`get_the_terms`). Revisions, autosaves, and posts that never held a `publish` status are ignored.
- Post and term events also purge the `/feed/` variants of the affected URLs, since feeds bypass the cache and would otherwise go stale.
- `edit_term` / `delete_term` → term archive + home.
- `comment_post` / `wp_set_comment_status` / `trackback_post` → associated post, including its comment-paged variants (whitelisted-query path hashes such as `cpage`).
- `wp_update_nav_menu` / `switch_theme` → full purge.
- `switch_theme`, `customize_save_after`, plugin/theme `upgrader_process_complete`, and `elementor/editor/after_save` additionally flag the pasted critical CSS snippet as possibly stale (`CriticalCSS::markStale()`, option `cosmic_cache_critical_stale`): pages are purged, but the snippet needs a human refresh. Saving a fresh snippet clears the flag and records provenance (`cosmic_cache_critical_meta`: hash/time/theme/version) shown in the Optimization tab; saving an empty snippet clears the provenance instead. An admin notice nags only while the feature is in use. Nothing is ever auto-disabled (fail-open).
- `update_option` of `siteurl`, `home`, or permalink structure → full purge.
- Integration events (WooCommerce product save → product + shop page; Elementor CSS regen → full purge).

Purges within one request are **deferred to `shutdown`** and de-duplicated to avoid repeated filesystem walks.

### 7.7 Preloader (`Preloader.php`)

- Crawls the configured sitemap, or falls back to homepage + linked URLs, via `wp_remote_get`. The fallback crawl is bounded (max depth 2, max 500 URLs per run) so it stays deterministic on large sites.
- Requests carry a `COSMIC` header/flag so the capture path stores the page without re-running the preloader.
- Concurrency ≤ 4; respects `robots.txt`; skips files already fresh.
- Scheduled via WP cron at `preload_interval`; manual trigger from the admin Tools tab and WP-CLI.

---

## 8. Object Cache

- **Status.php**: detects an existing `object-cache.php` drop-in (Redis/Memcached/APCu) and reports backend + version; runs a micro-benchmark (get/set ops) and reports latency.
- **DropInGenerator.php**: opt-in. If `ext-redis` present → writes a Redis-backed drop-in mirroring the WP core cache API surface (`wp_cache_init`, `wp_cache_add/get/set/delete/flush/incr/decr`, `wp_cache_get_multiple`, `wp_cache_set_multiple`, `wp_cache_close`, `wp_cache_add_global_groups`, `wp_cache_flush_runtime`, `wp_cache_flush_group` (WP ≥ 6.1, conditional), `wp_cache_supports`, `wp_cache_switch_to_blog`). If APCu → APCu variant. If neither is available → refuse with an admin notice.
- Uses a stable prefix derived from `DB_NAME` + blog ID (multisite-safe), or overridden via `object_cache_prefix`.
- **Never overwrites a non-Cosmic object-cache drop-in** without explicit confirmation.
- Transient/object TTLs pass through; no default TTL (mirrors WP behavior).
- **Fail-open**: the drop-in never fatals. A missing `ext-redis`/`Redis` class, a connect timeout (0.2 s), or any runtime `RedisException` on an op degrades that op — and, on repeated failure, the whole backend — to the in-process array cache, with one logged notice. The WP site keeps working.
- **Decode guard**: `unserialize` runs with `allowed_classes = [stdClass]` so WP's own cached stdClass values (e.g. `alloptions`) round-trip while arbitrary gadget classes are never instantiated.

---

## 9. Optimization Pipeline (applied at capture/store time)

Pipeline runs on captured HTML before storage, in this fixed order:

```
Cleanup → Combine → CriticalCSS → Minify → DeferJS → DelayJS → LazyLoad → CDN rewrite
```

Each stage is an isolated filter (`cosmic_cache_html_stage_*`) and is skipped when disabled or excluded. Patterns in `exclude_css`/`exclude_js` opt files out of **all** CSS/JS stages (minify, combine, defer). All rewriting is content-aware: only `http(s)` local URLs are touched, never data URIs or inline JSON-LD.

> **Experimental stages.** HTML rewriting is opt-in. The `minify_html` master switch (default **off**) gates the Minify stage: without it the pipeline is a byte-identical pass-through. `lazyload`/`lazyload_iframes`, `remove_query_strings`, and the CDN rewrite are also marked experimental and default off. Default output is therefore byte-identical to the uncached HTML.

- **Cleanup.php**: strip WP emoji script/link tags; inject `<link rel="dns-prefetch"|"preconnect">` for configured hosts; remove version query strings from static asset URLs (when enabled).
- **Combine.php**: group same-directory local CSS/JS; preserve document order; honor the `media` attribute; drop already-combined files; output a single file named by content hash (cache-busting) referenced with an absolute URL. When combining CSS, rewrite relative `url(...)` references to absolute paths so they keep resolving against the combined file's new location. Eligibility requires a `.css`/`.js` extension and rejects `..` segments; `url_to_path()` realpath-normalizes and verifies containment under `WP_CONTENT_DIR` so crafted URLs can never read arbitrary files. JS groups join with `";\n"` (ASI-safe).
- **Minify.php**: gated by `minify_html`. Strips plain comments (protecting SSI `#`, Gutenberg `wp:`, `more`/`nextpage`, IE conditionals) and collapses tag-adjacent whitespace. Text-node whitespace is preserved, so visible words are never merged (`Contact <a>us</a>` stays intact). `minify_css()` keeps `calc(100% + 10px)` intact; `minify_js()` is comment-stripping only — operator/whitespace collapsing is removed because it corrupted `a++b`, regex literals, and ASI `return\n{...}`.
- **DeferJS.php**: add `defer`/`async` to eligible `<script>` tags; move non-essential inline scripts to run on `DOMContentLoaded`; never touch scripts matching `defer_exclude`, or any script whose `type` is present and non-JS (JSON-LD/JSON/template) — those are never wrapped or deferred.
- **DelayJS.php**: delay non-critical scripts until visitor interaction (scroll/click, 3 s timeout fallback); honors `delay_js_exclude` + `defer_exclude`. **Experimental**.
- **CriticalCSS.php**: inline the operator-pasted above-the-fold snippet as `<style id="cosmic-critical-css">` and convert the remaining stylesheets to non-render-blocking (`rel="preload"` + `onload` swap + `<noscript>` fallback). The snippet is never auto-generated; staleness is tracked instead (see below). **Experimental**.
- **CriticalGenerator.php + CssParser.php** (manual-only, admin/CLI — never the hot path): heuristic critical-CSS generator with zero dependencies and no browser. Local stylesheets are read via the shared `AssetFetch` trait (disk-first, same containment rules as Combine); selectors are matched against an above-the-fold DOM index (header/nav subtrees + bounded body prefix, class/id/tag lookup — never naive rule×element). Unjudgeable selectors (pseudos, attributes, complex combinators) and `@supports` innards are kept; print-only rules are dropped; `@font-face`/`@keyframes` survive only when referenced. Budgets abort cleanly (5 URLs, 1 MB CSS, 20 s, 30 KB inline cap). Output is always a draft (`cosmic_cache_critical_draft`) for human review; Apply backs up to `cosmic_cache_critical_prev` (one-click revert) and flows through `Config::save()` + purge + stale/provenance tracking. A `cosmic_cache_critical_provider` filter may replace the heuristic result (e.g. headless Chromium on a VPS). Requires ext-dom, with a graceful message when missing.
- **LazyLoad.php**: swap `src` → `data-cosmic-src` with a transparent placeholder for images/iframes without `loading="eager"`; inject a tiny inline IntersectionObserver bootstrap; wrap swapped elements in `<noscript>` fallbacks so content stays visible without JS. **Experimental** — may mis-handle edge-case markup (no srcset/CLS handling yet).
- **CDN rewrite**: when `cdn_url` is set, rewrite only **asset** `<link>`/`script`/`img` `src`/`href` values (stylesheet, icon, preload, prefetch, script/img tags) to the CDN host. Page-level `<link>` tags (canonical, next/prev, RSS, `wp-json`) and inline/JSON-LD blocks are never rewritten (see Section 10). **Experimental**.

---

## 10. CDN Rewrite (`Cdn/Cdn.php`)

- With `cdn_url` set, rewrite local asset URLs to the CDN host in stored HTML. **Experimental** — validate the rewritten page after enabling.
- Only **asset** links are rewritten: `<script src>`, `<img src>`, and `<link>` tags whose `rel` is stylesheet/icon/preload/prefetch/dns-prefetch/preconnect. Page-level `<link>` tags (canonical, next/prev, `alternate`/RSS, `wp-json`) and inline/JSON-LD script content are never rewritten.
- Skip rules: `cdn_exclude` (paths/extensions), data URIs, `application/ld+json` blocks.
- Supports absolute, scheme-relative, and `//` CDN URLs; when the CDN URL is scheme-relative the host keeps the original scheme.
- Per-asset opt-out via `cosmic_cache_cdn_rewrite` filter.

---

## 11. Admin UI (`Admin/`)

- The admin UI is localized via `load_plugin_textdomain` (textdomain `cosmic-cache`); all strings pass through the relevant `__()`/`_e()` functions.
- **SettingsPage**: tabs — *Page Cache*, *Optimization*, *CDN*, *Object Cache*, *Tools*.
  - Options API (`register_setting`) with sanitize callbacks; sections per tab.
  - Every save regenerates `config.php`; changing any key in `SettingsPage::html_purge_keys()` (page-cache keys plus the whole optimization pipeline and the CDN rewrite) triggers a cache purge.
  - Tools tab: Purge Cache, Purge URL(s) textarea, Preload Now, Debug log viewer (shown when debug logging is active — the `debug` setting or `COSMIC_CACHE_DEBUG`).
- **Admin bar**: when `show_admin_bar_button` is enabled, a "Clear cache" button (nonce-guarded, `manage_options`) appears in the toolbar on the front end for quick purges.
- **PurgeController**: authenticated AJAX actions `cosmic_purge_all`, `cosmic_purge_urls`, `cosmic_preload`; nonce `cosmic_cache_admin`; capability `manage_options`.
- **Notice.php**: warnings for missing drop-in, unwritable cache dir, PHP < 8.0, unavailable object-cache backend, non-Cosmic drop-in detected, possibly-stale critical CSS.

---

## 12. WP-CLI (`Cli/Command.php`)

```
wp cosmic status            # settings, drop-in health, cache size/count
wp cosmic purge             # purge all
wp cosmic purge <url>       # purge one URL
wp cosmic preload           # run preloader now
wp cosmic critical-css [--url=<url>] [--apply] [--force]  # heuristic critical-CSS draft (manual-only)
wp cosmic debug on|off      # toggle the stored debug setting (COSMIC_CACHE_DEBUG still wins)
```

Requires WP-CLI ≥ 2.0; graceful no-op when WP-CLI is absent.

---

## 13. Integrations (`Integration/`)

- **WooCommerce**: never cache cart/checkout/account pages; the integration adds `woocommerce_items_in_cart` to `cache_cookies` so pages vary on cart contents (rather than bypassing); purge product + shop page on product save.
- **Elementor**: purge full cache on `elementor/editor/after_save` (and flag the critical CSS snippet as possibly stale); bypass caching for `?elementor-preview=`.
- **WPML**: purge per-language URL variants; vary key includes the `lang` cookie when present.
- Each integration is registered through `cosmic_cache_integrations` (pluggable).

---

## 14. Public API (Hooks)

Filters:

- `cosmic_cache_settings` — full settings array.
- `cosmic_cache_is_cacheable` — override the bypass decision.
- `cosmic_cache_vary_key` — override the computed vary key.
- `cosmic_cache_purge_urls` — extend URLs purged on a given event.
- `cosmic_cache_html` — post-process final HTML before store.
- `cosmic_cache_html_stage_*` — isolated per-stage hooks in the optimization pipeline (`cleanup`, `combine`, `criticalcss`, `minify`, `defer`, `delayjs`, `lazyload`, `cdn`); receive the current HTML and return it.
- `cosmic_cache_critical_provider` — replace the heuristic critical-CSS result with e.g. a headless-Chromium computation (receives null, urls, settings; return an array with a `css` string).
- `cosmic_cache_cdn_rewrite` — disable CDN rewrite for a given asset.
- `cosmic_cache_preload_urls` — seed additional preload URLs.
- `cosmic_cache_integrations` — register integrations.

Actions:

- `cosmic_cache_purged_all`, `cosmic_cache_purged_urls`, `cosmic_cache_preloaded` — for cache-aware plugins.

---

## 15. Security

- Cache root lives under `wp-content/cache/cosmic/` (outside the docroot web path where possible); `config.php` returns only primitive arrays.
- All admin output escaped (`esc_html`/`esc_attr`/`esc_url`); sanitize callbacks on every setting.
- AJAX + WP-CLI guarded by capability + nonce.
- Regex purge is bounded to prevent DoS; URL purge strictly derived from allowed URL shapes.
- Drop-in files written with secure perms (0644/0755); never overwrite a non-Cosmic drop-in.
- No secrets ever written to cache files.

---

## 16. Error Handling & Logging

- **Fail-open**: on any drop-in include error or corrupt config → include WP core normally (never a 500).
- **Fail-open**: if a cache write fails (disk full/perms), the request still completes; the error is counted and surfaced via an admin Notice.
- Debug logging is active when the stored `debug` setting is enabled or `COSMIC_CACHE_DEBUG` is defined; it writes structured lines to `cache/cosmic/debug.log` (truncated when >5MB).

---

## 17. Testing

- `php -l` on every PHP file (CI step).
- Unit tests (PHPUnit ≥ 9.6) for pure logic via mockable interfaces: Vary, Bypass, Storage atomicity, Minify/Combine parsers, Purger URL resolution, CDN rewrite — no WP boot required.
- Integration tests with `wp-env` (Docker, WP 6.x on PHP 8.0/8.5): cache hit/miss, purge hooks, preload, settings save → config regen. Purge coverage explicitly includes the unpublish (`publish → draft`) transition, feed invalidation, and comment-paged (`cpage`) URL variants.
- Manual performance gate: cache hit must not boot WP — assert `did_action('init') === 0` and `X-Cache: HIT` header present.
- Automated perf check in CI: a warm-cache hit completes in <5ms PHP execution (measured on the `wp-env` container against `time` around `readfile()`, excluding PHP-FPM overhead); fails the pipeline when exceeded.

---

## 18. Milestones

| # | Deliverable | Exit criteria |
|---|---|---|
| M1 | Bootstrap, Autoloader, Config | Activation writes drop-in + config; settings save regenerates config |
| M2 | Page cache core (Bypass/Vary/Storage/Serve/drop-in) | Cache hit served pre-boot with gzip; purge on post save |
| M3 | Purger + Preloader + cron GC | URL/prefix/full purges; background preload |
| M4 | Optimization pipeline | Minify/combine/defer/lazy/cleanup pass content tests |
| M5 | CDN rewrite + Object Cache | CDN URLs in HTML; opt-in drop-in works with redis/apcu |
| M6 | Admin UI + WP-CLI + Integrations | Tabs functional; `wp cosmic purge` works; Woo/Elementor/WPML hooks fire |
| M7 | Tests + docs + hardening | CI green, lint clean, README + SPECS final |

---

## 19. Acceptance Criteria

1. On a cache hit, WordPress core (`init` and later) never executes.
2. When optimization is disabled, cached HTML is byte-identical to the uncached output for the same request; with optimization enabled, cached output is deterministic and passes content-equivalence tests for the same request.
3. Any content change (post/term/comment/menu/settings) is reflected on next load without manual purge.
4. Plugin activates/deactivates cleanly on shared hosting; deactivation restores drop-ins and removes only its own cache files.
5. Zero runtime composer dependencies.
