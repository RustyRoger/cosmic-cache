=== Cosmic Cache ===
Contributors: cosmiccache
Tags: cache, caching, performance, page cache, object cache, optimization, cdn
Requires at least: 6.0
Tested up to: 7.0.2
Requires PHP: 8.0
Stable tag: 1.1.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Serve pages from disk before WordPress boots, with optional object cache, asset optimization and CDN rewrite. Pure PHP, zero dependencies.

== Description ==

Cosmic Cache is a performance-oriented caching plugin:

* **Pre-boot page cache** — the `advanced-cache.php` drop-in serves pages
  from disk before WordPress is loaded (fail-open hot path).
* **Optional object cache** — `object-cache.php` drop-in on APCu/Redis/Memcached
  with prefix-scoped flush.
* **Asset optimization** — combine, defer, lazy-load, CDN rewrite and more,
  applied at drawtime.
* **Automatic cleanup** — serve-time TTL, disk-cap GC with oldest-first
  eviction, purge on post/term/comment events.
* **Preloader** — background warm-up with a persistent queue, overlap lock
  and `robots.txt` support.

No runtime dependencies: a self-contained plugin in pure PHP.

== Installation ==

1. Upload the `cosmic-cache` folder to `wp-content/plugins/` or use
   "Upload plugin" with the zip file.
2. Activate the plugin. Activation writes `wp-content/advanced-cache.php`
   as a Cosmic Cache-controlled drop-in.
3. Configure the cache in `Settings → Cosmic Cache`. Caching starts on
   the first request.

== Frequently Asked Questions ==

= The site goes blank after activation? =

The hot path is fail-open: any error lets WordPress start normally.
Make sure `wp-content` is writable by the PHP user.

= How do I clear the cache? =

From the admin bar with the "Purge cache" button, or:

    wp cosmic purge all

= I use Nginx: why might the cache be served directly? =

The plugin protects the cache root with `.htaccess`/`web.config`
(Apache/IIS), but Nginx does not read those files. Add a rule to your
site's `server {}` block that denies access to the cache root (while
leaving only the combined `.css`/`.js` assets public):

    location ~ ^/wp-content/cache/cosmic/ {
        location ~ \.(css|js)$ { }
        deny all;
    }

If you set a custom cache root with `COSMIC_CACHE_DIR`, adjust the path
accordingly.

= How do I offload the cached page body (X-Sendfile / X-Accel-Redirect)? =

The "Body delivery" setting chooses who sends the cached page bytes. By
default ("PHP") the drop-in uses `readfile()`. With "X-Sendfile"
(Apache) or "X-Accel-Redirect" (Nginx) the drop-in still computes the
cache key, TTL expiry and compression variant, but lets the web server
send the file from disk (zero-copy), freeing the PHP worker earlier.

WARNING: this is an **opt-in** choice that requires a configured server.
If the module (Apache) or location (Nginx) is missing, the server will
not deliver the file: the response has an empty body and, on Apache
without mod_xsendfile, the `X-Sendfile` header is exposed to the client.
Unlike the rest of the plugin, this mode is NOT fail-open in that case.
Enable it only if you can configure the web server; otherwise leave
"PHP" (no header is ever sent). Unknown values and paths outside the
cache root fall back to `readfile()` automatically.

Apache + mod_xsendfile (enable the module; the absolute path to the file
is sent in the header):

    # enable with: a2enmod xsendfile
    # if the cache root is outside the document root:
    XSendFilePath /path/to/wp-content/cache

Nginx (internal location mapping the cache root to a private URI):

    location /cosmic-cache/ {
        alias /path/to/wp-content/cache/cosmic/;
        internal;
    }

    # if you use a custom COSMIC_CACHE_DIR, use that path as the alias.
    # The location stays internal: never reachable from external requests,
    # so the cached .html/.gz/.br files are not downloadable.

= How do I warm the cache? =

    wp cosmic preload

The preloader respects `robots.txt`, configured exclusions and resumes
from a persistent queue on the next tick.

== Changelog ==

= 1.1.3 =
* Fix: the release zip omitted `dropins/`, so admin uploads (including
  1.1.2) could not generate drop-ins at activation; the build now fails
  closed on missing runtime files. Activation file errors surface as clean
  admin notices instead of "unexpected output".
* Fix: browser caching relied solely on `mod_expires`, so images/CSS/JS
  had no cache headers on hosts without it; the `.htaccess` block now has
  a `mod_headers` fallback with the same TTLs.

= 1.1.2 =
* Release automation (GitHub workflow with version guard).

= 1.1.1 =
* New opt-in critical CSS generator (heuristic, no browser, no SaaS):
  "Generate from homepage" in Optimization, or `wp cosmic critical-css`.
  Drafts are reviewed before Apply and never applied automatically; the
  previous snippet is kept for one-click revert.
* Fix: the Optimization tab printed settings source as text from the
  lazy-load fields on (missing PHP tags).

= 1.1.0 =
* The pasted Critical CSS is now flagged as possibly stale when the theme
  or plugins change (admin notice with link to the Optimization tab);
  snippet provenance (hash, date, theme) is shown under the fields.
* Saving optimization or CDN settings now purges the page cache, so new
  HTML is rebuilt on the next visit.

= 1.0.3 =
* New opt-in "Body delivery" option: X-Sendfile (Apache) or X-Accel-Redirect
  (Nginx) to send cached page bytes from the web server (zero-copy).
  Fail-open for unknown values and paths outside the cache root; requires
  the configured module/location (FAQ). `tests/integration/sendfile.sh`.

= 1.0.2 =
* New opt-in (experimental) optimizations: JavaScript delay (Delay JS)
  and inline critical CSS (Critical CSS).
* `COMPARISON.md` comparison matrix with the major caching plugins.
* Full empirical verification: byte-identical, REST/block-editor purge,
  uninstall, expired TTL and disk capacity limit.

= 1.0.1 =
* Fix: saving one tab no longer resets the settings of the other tabs.
* Complete Italian localization (EN remains the main language).
* Rewritten field descriptions and `ttl` default set to 24h.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.3 =
Reinstall from this zip if 1.1.2 activation reported a missing drop-in;
otherwise no action required.

= 1.1.2 =
No action required; release automation only.

= 1.1.1 =
No action required; the generator is opt-in and drafts are never applied
automatically.

= 1.1.0 =
Saving optimization settings now purges the cache once (pages re-warm on
next visit). The staleness notice appears only if Critical CSS is in use.

= 1.0.3 =
No action required; the new "Body delivery" option is disabled (PHP) by
default.

= 1.0.2 =
No action required; the new optimizations are disabled by default.

= 1.0.1 =
Fixes persistence across tabs of the admin page; no action required.

= 1.0.0 =
First version.
