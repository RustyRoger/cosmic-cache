<?php
/**
 * Container: wiring, activation/deactivation, drop-in generation.
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache;

final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Plugin directory (absolute, no trailing slash).
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Cache\PageCache
	 *
	 * @var Cache\PageCache|null
	 */
	private $page_cache;

	/**
	 * Cache\Purger
	 *
	 * @var Cache\Purger|null
	 */
	private $purger;

	/**
	 * Get the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->dir = dirname( __DIR__ );
	}

	/**
	 * Run on activation (SPEC §7.1 step 1).
	 *
	 * @return void
	 */
	public function activate(): void {
		if ( ! is_dir( WP_CONTENT_DIR ) || ! is_writable( WP_CONTENT_DIR ) ) {
			set_transient( 'cosmic_cache_notice', 'wp_content_readonly', 300 );
			return;
		}

		// Cache root + compiled config (ownership aligned to the web user so
		// root-activated installs (containers/wp-cli) stay writable).
		\CosmicCache\Config::ensureCacheDir();

		$this->write_advanced_cache();

		// Browser caching for static assets (versioned ?ver= URLs keep a long
		// TTL safe). Writes the block straight into the root .htaccess.
		\CosmicCache\Config::installBrowserCache();

		if ( ! Config::compile() ) {
			set_transient( 'cosmic_cache_notice', 'config_write_failed', 300 );
		}

		$added = $this->enable_wp_cache();
		update_option( 'cosmic_cache_owns_wp_cache', $added ? 1 : 0, false );

		if ( ! $added && ! $this->wp_cache_defined() ) {
			// Soft failure: cache stays disabled (no WP_CACHE) with a notice.
			set_transient( 'cosmic_cache_notice', 'wp_config_readonly', 300 );
		}

		$settings = Config::load();
		if ( ! empty( $settings['object_cache_enabled'] ) ) {
			ObjectCache\DropInGenerator::generate( $settings );
		}

		if ( ! wp_next_scheduled( 'cosmic_cache_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'cosmic_cache_cleanup' );
		}

		// Set schema version on fresh install or upgrade.
		Config::updateSchemaVersion( Config::SCHEMA_VERSION );
	}

	/**
	 * Run on deactivation: restore drop-ins and remove own cache files.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		$advanced = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( is_file( $advanced ) && false !== strpos( (string) @file_get_contents( $advanced ), 'Cosmic Cache' ) ) {
			@unlink( $advanced );
		}

		$object = WP_CONTENT_DIR . '/object-cache.php';
		if ( is_file( $object ) && false !== strpos( (string) @file_get_contents( $object ), 'Cosmic Cache' ) ) {
			@unlink( $object );
		}

		if ( get_option( 'cosmic_cache_owns_wp_cache' ) ) {
			$this->disable_wp_cache();
		}

		// Strip the browser-caching block we may have added to the root
		// .htaccess so deactivation leaves the site as it was found.
		\CosmicCache\Config::removeBrowserCache();

		if ( wp_next_scheduled( 'cosmic_cache_cleanup' ) ) {
			wp_clear_scheduled_hook( 'cosmic_cache_cleanup' );
		}
		if ( wp_next_scheduled( 'cosmic_cache_preload' ) ) {
			wp_clear_scheduled_hook( 'cosmic_cache_preload' );
		}

		// Remove only our own cache files.
		$dir = Config::cacheDir();
		if ( is_dir( $dir ) ) {
			$this->delete_tree( $dir );
		}
	}

	/**
	 * Wire up hooks (runs on plugins_loaded).
	 *
	 * @return void
	 */
	public function boot(): void {
		load_plugin_textdomain( 'cosmic-cache', false, dirname( plugin_basename( $this->dir . '/cosmic-cache.php' ) ) . '/languages' );

		// Run schema upgrades if needed (only in admin to avoid front-end overhead).
		if ( is_admin() ) {
			add_action( 'admin_init', array( '\CosmicCache\Config', 'upgrade' ) );
		}

		$this->page_cache = new Cache\PageCache();
		$this->purger     = new Cache\Purger();

		// Invalidation hooks must reach every save path: admin, cron, CLI,
		// REST (block editor), XML-RPC and the front end (e.g. comment posts).
		// The hooks only fire on the underlying events and are no-ops on an
		// ordinary front-end GET, so registering them unconditionally is safe.
		$this->purger->register_hooks();
		$this->purger->register_admin_bar();

		// Front end: capture the rendered page.
		add_action( 'template_redirect', array( $this->page_cache, 'maybe_start_buffer' ), 0 );

		// Cron: TTL cleanup + preloader.
		add_action( 'cosmic_cache_cleanup', array( $this, 'run_cleanup' ) );
		add_action( 'cosmic_cache_preload', array( $this, 'run_preload' ) );
		$this->schedule_cron();

		$this->register_optimization();
		$this->register_integrations();

		if ( is_admin() ) {
			new Admin\SettingsPage();
			new Admin\PurgeController();
			new Admin\Notice();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'cosmic', Cli\Command::class );
		}
	}

	/**
	 * Schedule preload cron when enabled.
	 *
	 * @return void
	 */
	private function schedule_cron(): void {
		$settings = Config::runtime();
		$interval = max( 60, (int) $settings['preload_interval'] );

		if ( ! empty( $settings['preload_enabled'] ) ) {
			if ( ! wp_next_scheduled( 'cosmic_cache_preload' ) ) {
				wp_schedule_event( time() + $interval, 'cosmic_preload_cron', 'cosmic_cache_preload' );
			}
		} elseif ( wp_next_scheduled( 'cosmic_cache_preload' ) ) {
			wp_clear_scheduled_hook( 'cosmic_cache_preload' );
		}

		// Ensure the custom interval is registered.
		add_filter(
			'cron_schedules',
			static function ( $schedules ) use ( $interval ) {
				$schedules['cosmic_preload_cron'] = array(
					'interval' => $interval,
					'display'  => __( 'Cosmic Cache preload', 'cosmic-cache' ),
				);
				return $schedules;
			}
		);
	}

	/**
	 * GC callback.
	 *
	 * @return void
	 */
	public function run_cleanup(): void {
		$settings = Config::runtime();
		Cache\Storage\Disk::gc( $settings );
	}

	/**
	 * Preload callback.
	 *
	 * @return void
	 */
	public function run_preload(): void {
		$settings  = Config::runtime();
		$preloader = new Cache\Preloader( $settings );
		$preloader->run();
	}

	/**
	 * Register the optimization pipeline stages (SPEC §9).
	 *
	 * @return void
	 */
	private function register_optimization(): void {
		$cleanup   = new Optimization\Cleanup();
		$combine   = new Optimization\Combine();
		$critical  = new Optimization\CriticalCSS();
		$minify    = new Optimization\Minify();
		$defer     = new Optimization\DeferJS();
		$delay     = new Optimization\DelayJS();
		$lazyload  = new Optimization\LazyLoad();
		$cdn       = new Cdn\Cdn();

		add_filter( 'cosmic_cache_html_stage_cleanup', array( $cleanup, 'process' ), 10, 2 );
		add_filter( 'cosmic_cache_html_stage_combine', array( $combine, 'process' ), 10, 2 );
		add_filter( 'cosmic_cache_html_stage_criticalcss', array( $critical, 'process' ), 10, 2 );
		add_filter( 'cosmic_cache_html_stage_minify', array( $minify, 'process' ), 10, 2 );
		add_filter( 'cosmic_cache_html_stage_defer', array( $defer, 'process' ), 10, 2 );
		add_filter( 'cosmic_cache_html_stage_delayjs', array( $delay, 'process' ), 10, 2 );
		add_filter( 'cosmic_cache_html_stage_lazyload', array( $lazyload, 'process' ), 10, 2 );
		add_filter( 'cosmic_cache_html_stage_cdn', array( $cdn, 'process' ), 10, 2 );
	}

	/**
	 * Register integrations through the pluggable filter.
	 *
	 * @return void
	 */
	private function register_integrations(): void {
		$integrations = apply_filters(
			'cosmic_cache_integrations',
			array(
				'woocommerce' => Integration\WooCommerce::class,
				'elementor'   => Integration\Elementor::class,
				'wpml'        => Integration\WPML::class,
			)
		);

		foreach ( (array) $integrations as $integration ) {
			if ( is_string( $integration ) && class_exists( $integration ) ) {
				new $integration();
			}
		}
	}

	/**
	 * Whether the plugin booted (wiring + services ready). Lets settings-time
	 * helpers (e.g. CriticalCSS::applyDraft) purge safely in contexts where
	 * boot() never ran, such as unit tests.
	 *
	 * @return bool
	 */
	public static function booted(): bool {
		return null !== self::$instance && null !== self::$instance->purger;
	}

	/**
	 * Accessors for tests and CLI.
	 *
	 * @return Cache\PageCache
	 */
	public function page_cache(): Cache\PageCache {
		return $this->page_cache;
	}

	/**
	 * Accessors for tests and CLI.
	 *
	 * @return Cache\Purger
	 */
	public function purger(): Cache\Purger {
		return $this->purger;
	}

	/**
	 * Generate wp-content/advanced-cache.php by inlining the hot-path sources
	 * (Vary, Bypass, Serve) into the drop-in template. The drop-in is then
	 * fully self-contained.
	 *
	 * @return bool
	 */
	public function write_advanced_cache(): bool {
		// Suppressed reads: a missing template (e.g. incomplete upload) must
		// fail cleanly with an admin notice, never with "unexpected output"
		// warnings during activation.
		$template = @file_get_contents( $this->dir . '/dropins/advanced-cache.php' );
		if ( false === $template ) {
			if ( function_exists( 'set_transient' ) ) {
				set_transient( 'cosmic_cache_notice', 'advanced_cache_write_failed', 300 );
			}
			return false;
		}

		$replacements = array(
			'/* COSMIC_SRC_VARY */'   => $this->source( $this->dir . '/src/Cache/Vary.php' ),
			'/* COSMIC_SRC_BYPASS */' => $this->source( $this->dir . '/src/Cache/Bypass.php' ),
			'/* COSMIC_SRC_SERVE */'  => $this->source( $this->dir . '/src/Cache/Serve.php' ),
		);

		$dropin = str_replace( array_keys( $replacements ), array_values( $replacements ), $template );

		$target = WP_CONTENT_DIR . '/advanced-cache.php';

		// Never clobber a drop-in that is not generated by Cosmic Cache
		// (SPEC §15.5). The deactivate() path uses the same marker.
		if ( is_file( $target ) && ! $this->advanced_dropin_is_cosmic() ) {
			if ( function_exists( 'set_transient' ) ) {
				set_transient( 'cosmic_cache_notice', 'advanced_cache_foreign', 300 );
			}
			return false;
		}

		// Atomic write (temp + rename): a truncated advanced-cache.php is a
		// PHP ParseError on every request including wp-admin.
		$ok = Config::atomicWrite( $target, $dropin );

		if ( $ok ) {
			@chmod( $target, 0644 );
		}

		return $ok;
	}

	/**
	 * Was wp-content/advanced-cache.php generated by Cosmic Cache?
	 *
	 * @return bool
	 */
	private function advanced_dropin_is_cosmic(): bool {
		$target = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( ! is_file( $target ) ) {
			return false;
		}
		$contents = (string) @file_get_contents( $target );
		return false !== strpos( $contents, 'Cosmic Cache — advanced-cache drop-in' );
	}

	/**
	 * Read a source file, stripped of its opening tag, strict_types and own
	 * namespace (the template provides a bracketed namespace block).
	 *
	 * @param string $file
	 * @return string
	 */
	private function source( string $file ): string {
		$src = @file_get_contents( $file );
		if ( false === $src ) {
			return '';
		}
		$src = preg_replace( '/^<\?php\s*/', '', $src ) ?? $src;
		$src = preg_replace( '/^declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*/m', '', $src ) ?? $src;
		$src = preg_replace( '/^namespace\s+[\w\\\\]+\s*;\s*/m', '', $src ) ?? $src;
		return trim( $src ) . "\n";
	}

	/**
	 * Is WP_CACHE already defined as true in wp-config.php?
	 *
	 * @return bool
	 */
	private function wp_cache_defined(): bool {
		$config = ABSPATH . 'wp-config.php';
		if ( ! is_file( $config ) ) {
			return false;
		}
		$contents = (string) @file_get_contents( $config );
		return (bool) preg_match( '/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*true\s*\)\s*;/i', $contents );
	}

	/**
	 * Add WP_CACHE to wp-config.php only if absent.
	 *
	 * @return bool Whether Cosmic added it.
	 */
	private function enable_wp_cache(): bool {
		if ( $this->wp_cache_defined() ) {
			return false;
		}

		$config = ABSPATH . 'wp-config.php';
		if ( ! is_file( $config ) || ! is_writable( $config ) ) {
			return false;
		}

		$contents = (string) @file_get_contents( $config );

		// Insert right after the opening tag.
		$insert = "define( 'WP_CACHE', true ); // Added by Cosmic Cache\n";
		if ( preg_match( '/<\?php/', $contents, $m, PREG_OFFSET_CAPTURE ) ) {
			$offset = (int) $m[0][1] + strlen( $m[0][0] );
			$contents = substr( $contents, 0, $offset ) . "\n" . $insert . substr( $contents, $offset );
		} else {
			$contents = "<?php\n" . $insert . $contents;
		}

		// Suppressed: an unwritable wp-config must surface as the
		// wp_config_readonly admin notice, not as activation output.
		return false !== @file_put_contents( $config, $contents, LOCK_EX );
	}

	/**
	 * Remove the WP_CACHE define added by Cosmic.
	 *
	 * @return void
	 */
	private function disable_wp_cache(): void {
		$config = ABSPATH . 'wp-config.php';
		if ( ! is_file( $config ) || ! is_writable( $config ) ) {
			return;
		}

		$contents = (string) @file_get_contents( $config );
		$contents = preg_replace( '/^\s*define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*true\s*\)\s*;\s*\/\/\s*Added by Cosmic Cache\s*$/m', '', $contents ) ?? $contents;
		@file_put_contents( $config, $contents, LOCK_EX );
	}

	/**
	 * Recursively delete a directory tree.
	 *
	 * Symlink-safe: a symlink pointing to a directory is unlinked (not
	 * followed), so a malicious link inside the cache root can never make the
	 * deletion escape the root (audit D6).
	 *
	 * @param string $dir
	 * @return void
	 */
	private function delete_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		foreach ( $items ?: array() as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_link( $path ) || ! is_dir( $path ) ) {
				@unlink( $path );
			} else {
				$this->delete_tree( $path );
			}
		}
		@rmdir( $dir );
	}
}
