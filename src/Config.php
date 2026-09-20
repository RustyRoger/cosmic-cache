<?php
/**
 * Settings model, defaults, and the compiled runtime config writer/reader.
 *
 * Source of truth is the wp_options value `cosmic_cache_settings` (autoloaded).
 * On every save (and on upgrade) the settings are compiled into a plain PHP
 * array file (`cache/cosmic/config.php`) that the hot-path drop-in reads
 * without booting WordPress.
 *
 * Constants (COSMIC_CACHE_DISABLE/ENABLE/TTL/DIR/DEBUG) are NOT baked into the
 * compiled file; they are evaluated at request time by the drop-in.
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache;

final class Config {

	use AtomicWriteTrait;

	public const OPTION_KEY   = 'cosmic_cache_settings';
	public const CONFIG_FILE  = 'config.php';
	public const DEBUG_FILE   = 'debug.log';
	public const MAX_DEBUG_BYTES = 5 * 1024 * 1024;

	/**
	 * Schema version for settings migration.
	 *
	 * Increment this when the settings schema changes. The upgrade routine
	 * in Plugin::upgrade() checks this value and runs any necessary migrations.
	 *
	 * @var int
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Default whitelisted query parameters. Anything not listed here (and not
	 * added via stored settings) forces a hot-path bypass.
	 *
	 * Note: `s`, `preview` and `feed` are deliberately NOT listed. Reflected
	 * marketing parameters (utm_*, gclid, fbclid, ref, mc_cid) are deliberately
	 * excluded: themes and analytics scripts frequently echo them unescaped,
	 * which would turn a cached query string into stored XSS for every visitor.
	 *
	 * @var string[]
	 */
	public const DEFAULT_QUERY_WHITELIST = array(
		'p',
		'page_id',
		'page',
		'cat',
		'tag',
		'author',
		'm',
		'year',
		'monthnum',
		'day',
		'w',
		'cpage',
		'orderby',
		'order',
		'paged',
	);

	/**
	 * Default cookie presence rules that force a bypass.
	 *
	 * @var string[]
	 */
	public const DEFAULT_EXCLUDE_COOKIES = array(
		'wordpress_logged_in_*',
		'comment_author_*',
		'wp_woocommerce_session_*',
	);

	public const DEFAULT_MOBILE_REGEX = 'iPhone|iPod|iPad|Android|Mobile|Opera Mini|Opera Mobi|IEMobile|Windows Phone|BlackBerry|Kindle|Silk|webOS|Fennec';

	/**
	 * The settings schema with defaults (SPEC §6.2).
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			// Page cache.
			'enabled'               => false,
			'ttl'                   => 86400,
			'cache_mobile'          => false,
			'cache_logged_in'       => false,
			'cache_cookies'         => array(),
			'bypass_query'          => array(),
			'cache_whitelist_query' => self::DEFAULT_QUERY_WHITELIST,
			'bypass_user_agents'    => array(),
			'exclude_urls'          => array(),
			'exclude_cookies'       => self::DEFAULT_EXCLUDE_COOKIES,
			'gzip'                  => true,
			'brotli'                => true,
			'cache_headers'         => true,
			'serve_method'          => 'php',
			'preload_enabled'       => false,
			'preload_sitemap'       => '',
			'preload_interval'      => 86400,
			'purge_on_post'         => true,
			'purge_on_comment'      => true,
			'purge_on_terms'        => true,
			'show_admin_bar_button' => true,

			// Optimization.
			'minify_html'           => false,
			'minify_css'            => false,
			'minify_js'             => false,
			'combine_css'           => false,
			'combine_js'            => false,
			'defer_js'              => false,
			'delay_js'              => false,
			'delay_js_exclude'      => array(),
			'critical_css_enabled'  => false,
			'critical_css'          => '',
			'critical_css_exclude'  => array(),
			'exclude_css'           => array(),
			'exclude_js'            => array(),
			'defer_exclude'         => array(),
			'lazyload'              => false,
			'lazyload_iframes'      => false,
			'remove_emoji'          => false,
			'dns_prefetch'          => array(),
			'remove_query_strings'  => false,

			// CDN.
			'cdn_url'               => '',
			'cdn_exclude'           => array(),

			// Object cache.
			'object_cache_enabled'  => false,
			'object_cache_backend'  => 'redis',
			'object_cache_host'     => '127.0.0.1',
			'object_cache_port'     => 6379,
			'object_cache_prefix'   => '',

			// Debug.
			'debug'                 => false,
		);
	}

	/**
	 * Load settings, merging stored values over defaults. List-shaped defaults
	 * are extended (not replaced) by stored values.
	 *
	 * @return array<string,mixed>
	 */
	public static function load(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return self::defaults();
		}

		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return self::applyIntegrationFilters( self::merge( self::defaults(), $stored ) );
	}

	/**
	 * Let integrations adjust the resolved settings (SPEC §13): the
	 * WooCommerce cart-vary/excludes and WPML lang-vary rules are added here,
	 * so they reach both the runtime path and the compiled config. The filter
	 * is deliberately applied AFTER the stored settings so an explicit admin
	 * choice always wins over a plugin default.
	 *
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	private static function applyIntegrationFilters( array $settings ): array {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $settings;
		}
		$filtered = apply_filters( 'cosmic_cache_settings', $settings );
		return is_array( $filtered ) ? $filtered : $settings;
	}

	/**
	 * Runtime settings with constants applied (COSMIC_CACHE_*).
	 *
	 * @return array<string,mixed>
	 */
	public static function runtime(): array {
		$settings = self::load();

		if ( defined( 'COSMIC_CACHE_ENABLE' ) && COSMIC_CACHE_ENABLE ) {
			$settings['enabled'] = true;
		} elseif ( defined( 'COSMIC_CACHE_DISABLE' ) && COSMIC_CACHE_DISABLE ) {
			$settings['enabled'] = false;
		}

		if ( defined( 'COSMIC_CACHE_TTL' ) ) {
			$settings['ttl'] = (int) COSMIC_CACHE_TTL;
		}

		if ( defined( 'COSMIC_CACHE_DEBUG' ) && COSMIC_CACHE_DEBUG ) {
			$settings['debug'] = true;
		}

		// Mirror the compiled config so the capture/purge paths resolve the
		// blog segment exactly like the hot path (which boots before WP).
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$settings['blogs'] = self::blogMap();
		}

		return $settings;
	}

	/**
	 * Whether multisite is supported.
	 *
	 * Cosmic Cache is single-site only for now. The object cache namespaces
	 * keys per blog_id but that value is never initialized to the current site
	 * pre-boot, so enabling the plugin network-wide would let every blog read
	 * site 1's keys (including alloptions). Refuse to cache on multisite unless
	 * the operator explicitly opts in with COSMIC_CACHE_ALLOW_MULTISITE.
	 *
	 * @return bool
	 */
	public static function multisiteAllowed(): bool {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return true;
		}
		return defined( 'COSMIC_CACHE_ALLOW_MULTISITE' ) && COSMIC_CACHE_ALLOW_MULTISITE;
	}

	/**
	 * Persist + sanitize settings, then regenerate the compiled config.
	 *
	 * @param array<string,mixed> $settings Raw settings submitted by the admin.
	 * @return bool True when the option was saved.
	 */
	public static function save( array $settings ): bool {
		$clean = self::sanitize( $settings );
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_KEY, $clean, true );
		}

		return self::compile( $clean );
	}

	/**
	 * Sanitize an incoming settings array against the schema.
	 *
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $settings ): array {
		$defaults = self::defaults();
		$clean    = array();

		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				$clean[ $key ] = $default;
				continue;
			}

			$value = $settings[ $key ];

			if ( is_bool( $default ) ) {
				$clean[ $key ] = (bool) $value;
			} elseif ( is_int( $default ) ) {
				$clean[ $key ] = (int) $value;
			} elseif ( is_array( $default ) ) {
				$items = is_string( $value ) ? preg_split( '/[\n\r,]+/', $value ) ?: array() : (array) $value;
				$items = array_map(
					static function ( $item ) {
						return is_scalar( $item ) ? trim( (string) $item ) : '';
					},
					$items
				);
				$clean[ $key ] = array_values( array_filter( $items, static function ( $item ) {
					return '' !== $item;
				} ) );
			} else {
				$clean[ $key ] = is_scalar( $value ) ? (string) $value : (string) $default;
			}
		}

		// Bounded numeric fields.
		$clean['ttl']              = max( 0, $clean['ttl'] );
		$clean['preload_interval'] = max( 60, (int) $clean['preload_interval'] );

		// serve_method: only known strategies; anything else fails open to php.
		$methods = array( 'php', 'x-sendfile', 'x-accel-redirect' );
		if ( ! in_array( (string) $clean['serve_method'], $methods, true ) ) {
			$clean['serve_method'] = 'php';
		}

		return $clean;
	}

	/**
	 * Merge stored settings over defaults, extending list-shaped defaults.
	 *
	 * @param array<string,mixed> $defaults
	 * @param array<string,mixed> $stored
	 * @return array<string,mixed>
	 */
	private static function merge( array $defaults, array $stored ): array {
		$merged = $defaults;

		foreach ( $stored as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}

			if ( is_array( $defaults[ $key ] ) && is_array( $value ) ) {
				$merged[ $key ] = array_values( array_unique( array_merge( $defaults[ $key ], $value ) ) );
			} else {
				$merged[ $key ] = $value;
			}
		}

		return $merged;
	}

	/**
	 * The cache root directory (SPEC §4/§6.3).
	 *
	 * @return string
	 */
	public static function cacheDir(): string {
		if ( defined( 'COSMIC_CACHE_DIR' ) && COSMIC_CACHE_DIR ) {
			return rtrim( (string) COSMIC_CACHE_DIR, '/\\' );
		}

		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return WP_CONTENT_DIR . '/cache/cosmic';
		}

		return dirname( __DIR__ ) . '/cache';
	}

	/**
	 * Ensure the cache root exists and is writable by the web server.
	 *
	 * When the plugin is activated via wp-cli (often as root in containers) the
	 * directory may end up root-owned, blocking the web server from writing.
	 * Best-effort: re-own to WP_CONTENT_DIR's owner (no-op when already correct
	 * or when chown is not permitted).
	 *
	 * @return bool
	 */
	public static function ensureCacheDir(): bool {
		$dir = self::cacheDir();
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			return false;
		}

		if ( function_exists( 'fileowner' ) && defined( 'WP_CONTENT_DIR' ) && is_dir( WP_CONTENT_DIR ) ) {
			$owner = @fileowner( WP_CONTENT_DIR );
			if ( false !== $owner && @fileowner( $dir ) !== $owner ) {
				@chown( $dir, $owner );
			}
		}

		self::writeWebAccessGuard( $dir );

		return is_dir( $dir );
	}

	/**
	 * Write .htaccess / web.config so the cache root is never served directly
	 * (config.php, debug.log and cached HTML stay off the wire) while combined
	 * CSS/JS assets keep working. Best-effort; failures are ignored.
	 *
	 * @param string $dir Cache root.
	 * @return void
	 */
	private static function writeWebAccessGuard( string $dir ): void {
		if ( '' === $dir ) {
			return;
		}

		$htaccess = "# Cosmic Cache: never serve the cache root directly.\n"
			. "# Only .css/.js (the combined assets) remain publicly fetchable.\n"
			. "<IfModule mod_authz_core.c>\n"
			. "    Require all denied\n"
			. "    <FilesMatch \"\\.(css|js)$\">\n"
			. "        Require all granted\n"
			. "    </FilesMatch>\n"
			. "</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n"
			. "    Order allow,deny\n"
			. "    Deny from all\n"
			. "    <FilesMatch \"\\.(css|js)$\">\n"
			. "        Allow from all\n"
			. "    </FilesMatch>\n"
			. "</IfModule>\n";

		$webconfig = "<configuration>\n"
			. "  <system.webServer>\n"
			. "    <security>\n"
			. "      <authorization>\n"
			. "        <remove users=\"*\" roles=\"\" verbs=\"\" />\n"
			. "        <add accessType=\"Deny\" users=\"*\" />\n"
			. "      </authorization>\n"
			. "    </security>\n"
			. "  </system.webServer>\n"
			. "</configuration>\n";

		@file_put_contents( $dir . '/.htaccess', $htaccess, LOCK_EX );
		@file_put_contents( $dir . '/web.config', $webconfig, LOCK_EX );
	}

	/**
	 * Marker section for the browser-caching block in the WP root .htaccess.
	 */
	private const HTACCESS_BEGIN = '# BEGIN Cosmic Cache';
	private const HTACCESS_END   = '# END Cosmic Cache';

	/**
	 * Compose the browser-caching block (SPEC §7.4 / Lighthouse). Assets carry
	 * a content-versioning query string (?ver=...) so a long TTL is safe: the
	 * URL changes whenever the file is updated. Fonts/JS/CSS 1 year, images 1
	 * month. Nginx users get the equivalent snippet in readme.txt (a PHP plugin
	 * cannot emit server config for Nginx).
	 *
	 * Two mechanisms, same TTLs: ExpiresByType (mod_expires) plus a
	 * mod_headers FilesMatch fallback, because mod_expires is commonly
	 * disabled on shared hosting while mod_headers is not. When both fire,
	 * Cache-Control wins per RFC. Content images stay non-immutable (no
	 * ?ver= on uploads); svg is covered by the fonts rule only, so the two
	 * FilesMatch blocks never overlap.
	 *
	 * @return string
	 */
	public static function browserCacheRules(): string {
		return self::HTACCESS_BEGIN . "\n"
			. "<IfModule mod_expires.c>\n"
			. "    ExpiresActive On\n"
			. "    ExpiresByType text/css \"access plus 1 year\"\n"
			. "    ExpiresByType text/javascript \"access plus 1 year\"\n"
			. "    ExpiresByType application/javascript \"access plus 1 year\"\n"
			. "    ExpiresByType font/woff2 \"access plus 1 year\"\n"
			. "    ExpiresByType font/woff \"access plus 1 year\"\n"
			. "    ExpiresByType application/font-woff2 \"access plus 1 year\"\n"
			. "    ExpiresByType application/font-woff \"access plus 1 year\"\n"
			. "    ExpiresByType application/vnd.ms-fontobject \"access plus 1 year\"\n"
			. "    ExpiresByType image/svg+xml \"access plus 1 month\"\n"
			. "    ExpiresByType image/webp \"access plus 1 month\"\n"
			. "    ExpiresByType image/jpeg \"access plus 1 month\"\n"
			. "    ExpiresByType image/png \"access plus 1 month\"\n"
			. "    ExpiresByType image/gif \"access plus 1 month\"\n"
			. "</IfModule>\n"
			. "<IfModule mod_headers.c>\n"
			. "    <FilesMatch \"\\.(woff2?|ttf|eot|svg)$\">\n"
			. "        Header set Cache-Control \"public, max-age=31536000, immutable\"\n"
			. "    </FilesMatch>\n"
			. "    <FilesMatch \"\\.(css|js)$\">\n"
			. "        Header set Cache-Control \"public, max-age=31536000, immutable\"\n"
			. "    </FilesMatch>\n"
			. "    <FilesMatch \"\\.(jpe?g|png|gif|webp|avif|ico)$\">\n"
			. "        Header set Cache-Control \"public, max-age=2592000\"\n"
			. "    </FilesMatch>\n"
			. "</IfModule>\n"
			. self::HTACCESS_END . "\n";
	}

	/**
	 * Install the browser-caching block into the WP root .htaccess. Idempotent:
	 * an existing "Cosmic Cache" section is replaced in place, everything else
	 * (e.g. the WordPress rewrite section) is preserved. Best-effort.
	 *
	 * @return bool
	 */
	public static function installBrowserCache(): bool {
		return self::updateRootHtaccess( self::browserCacheRules() );
	}

	/**
	 * Remove the browser-caching block from the WP root .htaccess, restoring
	 * the previous content. Best-effort.
	 *
	 * @return bool
	 */
	public static function removeBrowserCache(): bool {
		return self::updateRootHtaccess( '' );
	}

	/**
	 * Insert or strip the "Cosmic Cache" section into ABSPATH/.htaccess while
	 * keeping the surrounding rules intact (atomic write).
	 *
	 * @param string $block '' to remove, rules text to add.
	 * @return bool
	 */
	private static function updateRootHtaccess( string $block ): bool {
		if ( ! defined( 'ABSPATH' ) ) {
			return false;
		}

		$path     = ABSPATH . '.htaccess';
		$existed  = is_file( $path );
		$existing = $existed ? (string) @file_get_contents( $path ) : '';

		if ( false !== strpos( $existing, self::HTACCESS_BEGIN ) ) {
			$head  = substr( $existing, 0, (int) strpos( $existing, self::HTACCESS_BEGIN ) );
			$after = strpos( $existing, self::HTACCESS_END );
			$tail  = ( false !== $after ) ? substr( $existing, (int) $after + strlen( self::HTACCESS_END ) ) : '';
			// Re-join without leftover blank lines around the removed block.
			$existing = rtrim( $head ) . "\n" . ltrim( $tail, "\r\n" );
		}

		if ( '' === $block ) {
			// Removal: keep whatever was outside our section (may be empty).
			// Do not fabricate content for a file we never created.
			if ( ! $existed && '' === trim( $existing ) ) {
				return true;
			}
			return self::atomicWrite( $path, $existing );
		}

		$content = rtrim( $existing ) . "\n\n" . $block;
		return self::atomicWrite( $path, $content );
	}

	/**
	 * Path to the compiled runtime config file.
	 *
	 * @return string
	 */
	public static function configFile(): string {
		return self::cacheDir() . '/' . self::CONFIG_FILE;
	}

	/**
	 * Compile the settings into a plain-PHP runtime config file.
	 *
	 * In multisite the compiled file also carries a host => blog_id map so the
	 * hot path can compute the <blog_id> path segment without booting WP.
	 *
	 * @param array<string,mixed>|null $settings
	 * @return bool
	 */
	public static function compile( ?array $settings = null ): bool {
		// Settings resolved through load() are already integration-filtered
		// (see applyIntegrationFilters); only explicitly-supplied settings
		// (e.g. the admin form) need the filter applied here, so a filter that
		// appends to a list-shaped key is never applied twice.
		$settings = ( null === $settings ) ? self::load() : self::applyIntegrationFilters( $settings );

		// Single-site only: never write an enabled config on multisite unless
		// the operator opted in. The hot path reads this flag pre-boot.
		if ( ! self::multisiteAllowed() ) {
			$settings['enabled']           = false;
			$settings['multisite_disabled'] = true;
		}

		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) ) {
			$settings['blogs'] = self::blogMap();
		}

		$dir = self::cacheDir();
		if ( ! self::ensureCacheDir() ) {
			return false;
		}

		// Defensive: never leak the blog map into the stored option — the map
		// lives only in the compiled runtime file. Strip BEFORE embedding it.
		// Needs a copy so the raw map still reaches var_export below.
		$compiled = $settings;
		unset( $settings['blogs'] );

		$content = "<?php\n"
			. "// Cosmic Cache runtime config. Generated " . gmdate( 'c' ) . ". Do not edit.\n"
			. 'return ' . var_export( $compiled, true ) . ";\n";

		return self::atomicWrite( self::configFile(), $content );
	}

	/**
	 * Read the compiled runtime config (drop-in path). Returns null on failure.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function read(): ?array {
		$file = self::configFile();
		if ( ! is_file( $file ) ) {
			return null;
		}

		$settings = include $file;
		if ( ! is_array( $settings ) ) {
			return null;
		}

		return $settings;
	}

	/**
	 * Multisite host => blog_id map for the compiled config.
	 *
	 * @return array<string,int>
	 */
	private static function blogMap(): array {
		$map = array();
		if ( ! function_exists( 'get_sites' ) ) {
			return $map;
		}

		foreach ( get_sites( array( 'number' => 500 ) ) as $site ) {
			$domain = strtolower( (string) $site->domain );
			if ( '' !== $domain ) {
				$map[ $domain ] = (int) $site->blog_id;
			}
		}

		return $map;
	}

	/**
	 * Plugin version (mirrors COSMIC_CACHE_VERSION).
	 *
	 * @return string
	 */
	public static function version(): string {
		return defined( 'COSMIC_CACHE_VERSION' ) ? COSMIC_CACHE_VERSION : '1.0.0';
	}

	/**
	 * Log a structured debug line to cache/cosmic/debug.log (bounded size).
	 *
	 * @param string $channel
	 * @param string $message
	 */
	public static function debug( string $channel, string $message ): void {
		if ( ! ( defined( 'COSMIC_CACHE_DEBUG' ) && COSMIC_CACHE_DEBUG ) ) {
			$settings = function_exists( 'get_option' ) ? self::load() : array();
			if ( empty( $settings['debug'] ) ) {
				return;
			}
		}

		$file = self::cacheDir() . '/' . self::DEBUG_FILE;
		if ( is_file( $file ) && filesize( $file ) > self::MAX_DEBUG_BYTES ) {
			@file_put_contents( $file, '' );
		}

		$line = gmdate( 'c' ) . " [{$channel}] {$message}\n";
		@file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Get the stored schema version.
	 *
	 * @return int
	 */
	public static function schemaVersion(): int {
		if ( ! function_exists( 'get_option' ) ) {
			return self::SCHEMA_VERSION;
		}
		return (int) get_option( 'cosmic_cache_schema_version', 0 );
	}

	/**
	 * Update the stored schema version.
	 *
	 * @param int $version
	 * @return void
	 */
	public static function updateSchemaVersion( int $version ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( 'cosmic_cache_schema_version', $version, false );
		}
	}

	/**
	 * Run upgrade routines if the schema version is outdated.
	 *
	 * This method is called on every admin_init and checks if the stored
	 * schema version is behind the current SCHEMA_VERSION constant. If so,
	 * it runs any necessary migration routines in order.
	 *
	 * @return void
	 */
	public static function upgrade(): void {
		$stored = self::schemaVersion();
		$current = self::SCHEMA_VERSION;

		if ( $stored >= $current ) {
			return;
		}

		// Future migrations go here:
		// if ( $stored < 2 ) { self::migrate_to_v2(); }
		// if ( $stored < 3 ) { self::migrate_to_v3(); }

		self::updateSchemaVersion( $current );
	}
}
