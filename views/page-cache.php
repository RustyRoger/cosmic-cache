<?php
/**
 * Page Cache settings tab.
 *
 * @var array<string,mixed> $settings
 * @package cosmic-cache
 */

require __DIR__ . '/field-helpers.php';

?>
<table class="form-table" role="presentation">
	<?php
	cosmic_field_checkbox( 'enabled', __( 'Enable page cache', 'cosmic-cache' ), __( 'Master switch. Serves cached pages before WordPress boots.', 'cosmic-cache' ), $settings );
	cosmic_field_text( 'ttl', __( 'Cache lifetime (seconds)', 'cosmic-cache' ), __( 'How long a cached page stays fresh. After expiry the file is deleted and rebuilt on the next visit. 0 = keep until purged.', 'cosmic-cache' ), $settings, 'number', array( 'min' => '0', 'step' => '1' ) );
	cosmic_field_checkbox( 'cache_mobile', __( 'Cache mobile separately', 'cosmic-cache' ), __( 'Serves a distinct cached page to mobile visitors. Off by default: mobile visitors get the desktop page.', 'cosmic-cache' ), $settings );
	cosmic_field_checkbox( 'cache_logged_in', __( 'Cache for logged-in users', 'cosmic-cache' ), __( 'Caches pages for logged-in users and varies on the auth cookie. Off by default — enabling it means a cached copy per logged-in user.', 'cosmic-cache' ), $settings );
	cosmic_field_list( 'cache_cookies', __( 'Vary on cookies', 'cosmic-cache' ), __( 'e.g. currency, store_id. Presence of a listed cookie gives those visitors their own cache entries.', 'cosmic-cache' ), $settings );
	cosmic_field_list( 'bypass_query', __( 'Bypass query params', 'cosmic-cache' ), __( 'e.g. nocache, preview. These query params force a full cache bypass.', 'cosmic-cache' ), $settings );
	cosmic_field_list( 'cache_whitelist_query', __( 'Whitelisted query params', 'cosmic-cache' ), __( 'Only these params participate in the cache key; all others are ignored, so /?s=abc and / share one cache entry. Defaults are always included.', 'cosmic-cache' ), $settings );
	cosmic_field_textarea_list( 'bypass_user_agents', __( 'Bypass user agents', 'cosmic-cache' ), __( 'Requests from these user agents are never cached and never served from cache. One regex or wildcard pattern per line.', 'cosmic-cache' ), $settings );
	cosmic_field_textarea_list( 'exclude_urls', __( 'Exclude URLs', 'cosmic-cache' ), __( 'Never cache these. Plain paths (/cart) or regex patterns (/checkout/.+). One per line.', 'cosmic-cache' ), $settings );
	cosmic_field_textarea_list( 'exclude_cookies', __( 'Exclude by cookie', 'cosmic-cache' ), __( 'If a listed cookie is present, the request bypasses the cache. Defaults are always included.', 'cosmic-cache' ), $settings );
	cosmic_field_checkbox( 'gzip', __( 'Precompress gzip', 'cosmic-cache' ), __( 'Also store a .gz copy, served to clients advertising gzip. Roughly doubles disk usage; falls back to plain HTML.', 'cosmic-cache' ), $settings );
	cosmic_field_checkbox( 'brotli', __( 'Precompress brotli', 'cosmic-cache' ), __( 'Store .br variants when brotli_compress() is available. Stronger compression than gzip, at a small CPU cost on save.', 'cosmic-cache' ), $settings );
	cosmic_field_checkbox( 'cache_headers', __( 'Emit HTTP caching headers', 'cosmic-cache' ), __( 'Send Cache-Control, ETag and Last-Modified on cache hits so browsers and CDNs can cache too.', 'cosmic-cache' ), $settings );
	cosmic_field_select(
		'serve_method',
		__( 'Body delivery', 'cosmic-cache' ),
		__( 'PHP (universal) streams the file through PHP. X-Sendfile (Apache mod_xsendfile) and X-Accel-Redirect (Nginx) let the web server send the cached file from disk with zero copy — the drop-in still computes the cache key, expiry and encoding, then hands the bytes to the server. The matching module/location must be installed (readme FAQ): otherwise the response has an empty body. Unknown values and paths outside the cache root fall back to PHP (readfile).', 'cosmic-cache' ),
		array(
			'php'               => __( 'PHP (universal, default)', 'cosmic-cache' ),
			'x-sendfile'        => __( 'X-Sendfile (Apache mod_xsendfile)', 'cosmic-cache' ),
			'x-accel-redirect'  => __( 'X-Accel-Redirect (Nginx internal)', 'cosmic-cache' ),
		),
		$settings
	);
	cosmic_field_checkbox( 'purge_on_post', __( 'Purge on post changes', 'cosmic-cache' ), __( 'Invalidate related pages when posts are saved, trashed or unpublished.', 'cosmic-cache' ), $settings );
	cosmic_field_checkbox( 'purge_on_comment', __( 'Purge on comments', 'cosmic-cache' ), __( 'Invalidate a post when its comments change.', 'cosmic-cache' ), $settings );
	cosmic_field_checkbox( 'purge_on_terms', __( 'Purge on term changes', 'cosmic-cache' ), __( 'Invalidate archives when terms change.', 'cosmic-cache' ), $settings );
	cosmic_field_checkbox( 'show_admin_bar_button', __( 'Admin bar clear button', 'cosmic-cache' ), __( 'Shows a “Clear cache” button in the toolbar.', 'cosmic-cache' ), $settings );
	cosmic_field_checkbox( 'preload_enabled', __( 'Preload cache', 'cosmic-cache' ), __( 'Warm the cache on a schedule so the first visitor never pays for a miss.', 'cosmic-cache' ), $settings );
	cosmic_field_text( 'preload_sitemap', __( 'Preload sitemap URL', 'cosmic-cache' ), __( 'Leave empty to crawl the homepage and its links instead (bounded crawl, depth 2).', 'cosmic-cache' ), $settings );
	cosmic_field_text( 'preload_interval', __( 'Preload interval (seconds)', 'cosmic-cache' ), __( 'How often the preload runs. Minimum 60 seconds.', 'cosmic-cache' ), $settings, 'number', array( 'min' => '60', 'step' => '1' ) );
	?>
</table>
