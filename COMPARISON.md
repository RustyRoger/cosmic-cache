# Cosmic Cache — Comparison with major caching plugins

Status: **documentation matrix, data as of 2026**. Numbers and feature sets are
taken from the respective plugin pages, WordPress.org listings, pricing pages
and independent 2026 benchmark/review articles listed in Sources. They are a
guide, not a lab benchmark across equal environments.

Last updated: 2026-08. Version compared: **Cosmic Cache 1.0.3**.

---

## 1. Quick overview

| Plugin | License / Price | Installs+ | Caching model | Page cache | Object cache |
|---|---|---|---|---|---|
| **WP Rocket** | Commercial (~\€59/yr) | 100k+ | Page + browser + object | Yes (disk/X-Cache) | Redis/Memcached add-on |
| **LiteSpeed Cache** | Free (GPLv2) | 5M+ | Page + object + server-side | Yes (disk/x-LiteSpeed-Cache) | Redis/Memcached/LiteSpeed | 
| **W3 Total Cache** | Free (GPLv2) | 1M+ | Page + object + DB + fragment | Yes (disk/memcached/redis/opcache) | Redis/Memcached |
| **WP Super Cache** | Free (GPLv2, now part of WP ecosystem) | 2M+ | Page (mod_rewrite / PHP / legacy) | Yes (disk via .htaccess or PHP) | No |
| **WP Fastest Cache** | Free + Premium (~\€39.99) | 1M+ (free) | Page + browser | Yes (disk via .htaccess) | No |
| **FlyingPress** | Commercial (~$51/yr) | 40k+ | Page + critical CSS service | Yes (disk) | Redis/Memcached |
| **Cache Enabler** | Free (GPLv2) | 200k+ | Page (advanced-cache.php) | Yes (disk) | No |
| **Hummingbird** | Free (GPLv2) + Pro (\$36/yr) | 100k+ | Page + object (Pro) | Yes (disk) | Redis/Memcached (Pro) |
| **Cosmic Cache** | Free (GPLv2) | — | Page via advanced-cache.php + fail-open | Yes (disk, pre-boot serve) | Redis/Memcached (opt-in drop-in) |

---

## 2. Feature matrix

Legend: &#x2713; built-in, &#x2717; not available, ~ partial / paywalled / experimental.

| Feature | WP Rocket | LiteSpeed | W3TC | WPSuperCache | WPFastest | FlyingPress | CacheEnabler | Hummingbird | **Cosmic** |
|---|---|---|---|---|---|---|---|---|---|
| Pre-boot page serve (no WP bootstrap) | &#x2717; | &#x2713; (server-side) | &#x2717; | &#x2717; | &#x2717; | &#x2717; | &#x2717; | &#x2717; | **&#x2713; advanced-cache.php** |
| Server-assisted body delivery (X-Sendfile / X-Accel-Redirect) | &#x2717; | ~ (server cache) | ~ (X-Sendfile option) | ~ (known-file via .htaccess) | ~ | &#x2717; | &#x2717; | &#x2717; | **&#x2713; opt-in (1.0.3)**, internal location |
| gzip / brotli page variants | ~ gzip | &#x2713; | &#x2713; gzip | ~ gzip | ~ gzip | &#x2713; | ~ gzip | &#x2713; | **&#x2713; gzip + brotli** |
| ETag / conditional 304 | ~ | &#x2713; | &#x2713; | ~ | ~ | ~ | &#x2713; | &#x2713; | **&#x2713;** |
| Minify HTML | &#x2713; | &#x2713; | &#x2713; | &#x2717; | &#x2713; | &#x2713; | ~ | &#x2713; | **&#x2713; opt-in** |
| Minify CSS/JS | &#x2713; | &#x2713; | &#x2713; | &#x2717; | &#x2713; | &#x2713; | ~ | &#x2713; | **&#x2713; opt-in** |
| Combine CSS/JS | &#x2713; | &#x2713; | &#x2713; | &#x2717; | &#x2713; | &#x2713; | &#x2717; | &#x2713; | **&#x2713; opt-in** |
| Defer JS | &#x2713; | &#x2713; | &#x2713; | &#x2717; | ~ | &#x2713; | &#x2717; | &#x2713; | **&#x2713; opt-in** |
| Delay JS | &#x2713; | &#x2713; | &#x2713; | &#x2717; | ~ | &#x2713; | &#x2717; | &#x2713; | **&#x2713; opt-in (1.0.2)**, exclude list |
| Critical CSS | &#x2713; (service add-on) | &#x2713; (API) | ~ | &#x2717; | ~ | &#x2713; (service) | &#x2717; | &#x2713; | **&#x2713; opt-in (1.0.2)**, manual inline |
| Remove unused CSS | &#x2713; (service) | &#x2713; | &#x2717; | &#x2717; | &#x2717; | &#x2713; | &#x2717; | ~ | **&#x2717;** (roadmap) |
| Lazy-load images / iframes | &#x2713; | &#x2713; | &#x2713; | &#x2717; | &#x2713; | &#x2713; | &#x2717; | &#x2713; | **&#x2713; opt-in** |
| Remove emoji / query strings | &#x2713; | &#x2713; | ~ | &#x2717; | &#x2713; | &#x2713; | &#x2717; | &#x2713; | **&#x2713; opt-in** |
| DNS prefetch | &#x2713; | &#x2713; | ~ | &#x2717; | ~ | &#x2713; | &#x2717; | &#x2713; | **&#x2713; opt-in** |
| CDN integration | &#x2713; | ~ | &#x2713; | ~ | &#x2713; | &#x2713; | ~ | &#x2713; | **&#x2713; rewrite opt-in** |
| Preloader | &#x2713; | &#x2713; | &#x2713; | &#x2713; | &#x2713; | &#x2713; | &#x2717; | ~ | **&#x2713; queued job, opt-in** |
| Page cache for logged-in / cookies | ~ | &#x2713; | &#x2713; | &#x2717; | ~ | ~ | &#x2717; | &#x2713; | **&#x2713; opt-in** (+cookie whitelist) |
| Mobile/UA variant | &#x2713; | &#x2713; | ~ | &#x2717; | &#x2713; | &#x2713; | &#x2717; | ~ | **&#x2713; opt-in** |
| Variants (real AbleToEncode /\* encoding-\*/) | ~ | &#x2713; | ~ | ~ | &#x2717; | ~ | ~ | ~ | **&#x2713; per-encoding variants** |
| REST / admin-bar purge button | &#x2713; REST trigger | &#x2713; | &#x2713; | ~ | ~ | ~ | &#x2717; | &#x2713; | **&#x2713;** |
| Automatic purge on post/comment/term | &#x2713; | &#x2713; | &#x2713; | &#x2713; | &#x2713; | &#x2713; | ~ | &#x2713; | **&#x2713;** |
| Cache for WooCommerce/blocks (bypass) | &#x2713; | &#x2713; | ~ | ~ | ~ | &#x2713; | &#x2717; | ~ | **Bypass via cookie/query, purge hooks** |
| Fail-open / safe fallback on disk error | ~ | &#x2713; | ~ | ~ | ~ | &#x2713; | ~ | ~ | **&#x2713; write-failure + critical audit** |
| GD/prune disk cap (oldest-first) | ~ | &#x2713; | ~ | &#x2717; | ~ | ~ | &#x2717; | ~ | **&#x2713; max-files cap + .tmp sweep** |
| i18n / locale-aware | &#x2713; | &#x2713; | &#x2713; | &#x2713; | ~ | &#x2713; | ~ | &#x2713; | **it_IT, POT 136 strings** |
| CDN — free/open-source | &#x2717; commercial | &#x2713; | &#x2713; | &#x2713; | ~ (premium) | &#x2717; commercial | &#x2713; | ~ (Pro) | **&#x2713; GPLv2** |

> **Legend note** for "~": in WP Rocket/FlyingPress critical & unused CSS are
> a paid *service* (SaaS generation), not a local algorithm; in LiteSpeed it
> needs the QUIC.cloud API; WPFastestCache delays/defers only with Premium.
> "Server-assisted body delivery" marks equivalents that are indirect (a
> server-side cache, a historical X-Sendfile option, or `.htaccess` known-file
> serving); Cosmic Cache ships it as an explicit opt-in with an Nginx
> `internal` location / Apache `mod_xsendfile`.

---

## 3. Architecture & safety notes

| | WP Rocket | LiteSpeed | W3TC | Cosmic Cache |
|---|---|---|---|---|
| Serve without loading WordPress | No — PHP serve needs WP | Yes, server-level (LiteSpeed) | No | **Yes**, `advanced-cache.php` standalone compile + pre-boot serve |
| Body delivery (zero-copy) | readfile() | Server cache | readfile() | **X-Sendfile / X-Accel-Redirect, opt-in (1.0.3)** |
| Storage | Disk | Disk / server cache | Disk / Redis / Memcached / object | Disk (page) + Redis/Memcached (object, opt-in) |
| Concurrency safety | File lock | Server-side | Lock | `.tmp` atomic write + orphan sweep |
| Overload / fail-open | Depends | Fail-open | Can fail hard on misconfig | **Fail-open by design** (read errors → WP) |
| Admin surface | Large settings UI | Very large | Very large | Minimal (single page) |
| Performance expert needed | No | Yes | Yes | No |

---

## 4. Where Cosmic Cache stands (honest assessment)

### Cosmic Cache wins
- **Pre-boot page serve** without loading WordPress (only LiteSpeed does this at
  server level). Measured in this repo's live container: warm cached hit served
  in **0.07–0.13 ms**, ~**14–17k req/s** (ab -n 500 -c 10) vs **173 req/s /
  57 ms** when WP boots uncached.
- **Per-encoding variants** (gzip/brotli) selected by request, generated once.
- **Fail-open + minimal surface**: nothing can crash the frontend on a disk or
  cache mistake, because the hot path is a small compiled drop-in.
- **Free, GPLv2, self-contained**: no SaaS/quota to generate critical CSS
  (manual paste), no reliance on a proprietary server (unlike LiteSpeed).
- **Modern WordPress block/editing purge**: RSS REST + block-editor save purge
  verified live in this repo's tests.

### Cosmic Cache needs work (honest gaps vs the leaders)
- **Remove unused CSS**: not implemented (WP Rocket/FlyingPress do it — via paid
  service; LiteSpeed via API). A local heuristic was deliberately rejected so
  the plugin keeps its no-SaaS / fail-open guarantee — an unrendered CSS pass
  risks stripping styles that JS or dynamic classes depend on.
- **Server-level body delivery**: made opt-in in 1.0.3 via X-Sendfile
  (Apache `mod_xsendfile`) / X-Accel-Redirect (Nginx `internal` location). It
  requires the matching server module/location and is NOT fail-open in that
  case — the safe default remains PHP (`serve_method`).
- **GUI coverage / Ecosystem**: far smaller setting surface; no multi-site UI
  extras yet, no Gravit/Adv.CSS upload, no user-agent granular per-device
  cache beyond mobile regex.
- **Maturity/battle-testing**: 1.0.x vs millions of installs. TTL, preload
  edge cases, and exotic host setups are less proven.

### Neutral
- Performance generic vs LiteSpeed server cache: LiteSpeed server-level wins on
  raw speed at the edge in many benchmarks; our numbers target the common
  case "PHP-FPM + no server cache module".

---

## 5. Live benchmark (this repo, 2026-08)

Environment: podman container, WP 7.0.2, `ab -n 500 -c 10`, `Accept-Encoding: gzip`.

| Scenario | req/s | time/req (mean) | time/req (mean, all) | failed |
|---|---|---|---|---|
| Uncached (WP boots every request) | 173.29 | 57.707 ms | 5.771 ms | 0 |
| Cached, default (disk hit)² | 13 826.29 | 0.723 ms | 0.072 ms | 0 |
| Cached + DelayJS + CriticalCSS configured¹ | 16 879.92 | 0.592 ms | 0.059 ms | 0 |

¹ Footnote: this host's homepage ships no `<link rel=stylesheet>` and only
  `type=module`/`importmap`/JSON scripts (theme-inlined CSS), so the
  DelayJS/CriticalCSS optimizers correctly have nothing to rewrite here —
  the "optimized" row is effectively the same cached HTML. Transform
  behaviour of both features is covered by unit tests and the extended
  integration suite on HTML that *does* contain classic scripts/links.

² Footnote: the `serve_method` options (X-Sendfile / X-Accel-Redirect, 1.0.3)
  do not change the cached-hit numbers — this container's Apache lacks
  `mod_xsendfile`, so body delivery is exercised at the header/mechanism level
  by `tests/integration/sendfile.sh`, not by the benchmark.

Note: WP Rocket/W3TC/similar numbers in blog benchmarks are usually measured
on their best-case cached page; our uncached figure is the same container and
its small page, so the ratio (~80x) is the meaningful signal, not the absolute
req/s.

---

## 6. Sources

- WordPress.org plugin pages (installs, license, last update): WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, Cache Enabler, Hummingbird, FlyingPress — accessed 2026-08.
- Pricing: WP Rocket (wp-rocket.me), FlyingPress (flying-press.com), WP Fastest Cache Premium, Hummingbird Pro — accessed 2026-08.
- Independent comparisons/benchmarks 2026: "WP Rocket vs WP Fastest Cache" (pagespeedmatters), "WP Super Cache vs W3 Total Cache" (best-plugins.net, hostinger tutorials).
- Cosmic Cache numbers: live `ab` run in this repository (see §5).