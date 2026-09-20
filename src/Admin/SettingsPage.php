<?php
/**
 * Tabbed admin settings page (SPEC §11).
 *
 * Options API integration with sanitize callbacks. Every save regenerates
 * config.php; toggling page-cache settings triggers a purge.
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache\Admin;

use CosmicCache\Config;
use CosmicCache\Optimization\CriticalCSS;

final class SettingsPage {

	/**
	 * Tabs.
	 *
	 * @var array<string,string>
	 */
	private $tabs = array(
		'page-cache'   => 'Page Cache',
		'optimization' => 'Optimization',
		'cdn'          => 'CDN',
		'object-cache' => 'Object Cache',
		'tools'        => 'Tools',
	);

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'update_option_' . Config::OPTION_KEY, array( $this, 'after_save' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu(): void {
		add_options_page(
			__( 'Cosmic Cache', 'cosmic-cache' ),
			__( 'Cosmic Cache', 'cosmic-cache' ),
			'manage_options',
			'cosmic-cache',
			array( $this, 'render_page' )
		);
	}

	public function register_settings(): void {
		register_setting(
			'cosmic_cache',
			Config::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Config::defaults(),
			)
		);
	}

	/**
	 * Settings keys whose change alters stored HTML and must purge the page
	 * cache. Besides the page-cache keys this covers the whole optimization
	 * pipeline (minify/combine/defer/delay/critical/lazyload) and the CDN
	 * rewrite: all of them change the bytes written to disk. Delivery-only
	 * (`serve_method`), scheduling (`preload_*`), display (`show_admin_bar_button`),
	 * debug and object-cache keys are intentionally excluded.
	 *
	 * @return array<int,string>
	 */
	public static function html_purge_keys(): array {
		return array(
			'enabled', 'ttl', 'cache_mobile', 'cache_logged_in', 'cache_cookies',
			'cache_whitelist_query', 'bypass_query', 'exclude_urls', 'exclude_cookies',
			'gzip', 'brotli', 'cache_headers',
			'minify_html', 'minify_css', 'minify_js',
			'combine_css', 'combine_js',
			'defer_js', 'defer_exclude',
			'delay_js', 'delay_js_exclude',
			'critical_css_enabled', 'critical_css', 'critical_css_exclude',
			'exclude_css', 'exclude_js',
			'lazyload', 'lazyload_iframes',
			'remove_emoji', 'dns_prefetch', 'remove_query_strings',
			'cdn_url', 'cdn_exclude',
		);
	}

	/**
	 * Settings keys of the pasted critical CSS snippet. A change here clears
	 * the stale flag and records fresh provenance meta.
	 *
	 * @return array<int,string>
	 */
	public static function critical_keys(): array {
		return array( 'critical_css_enabled', 'critical_css', 'critical_css_exclude' );
	}

	/**
	 * Settings sanitize + side effects (config regen happens in after_save).
	 *
	 * The admin form renders one tab at a time, so the submitted $input only
	 * contains the keys of the active tab (checkboxes always submit their
	 * hidden "0", so they appear too). Merging over the stored settings
	 * preserves the untouched tabs instead of resetting them to defaults.
	 *
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$incoming = is_array( $input ) ? $input : array();
		$stored   = function_exists( 'get_option' ) ? (array) get_option( Config::OPTION_KEY, array() ) : array();

		return Config::sanitize( array_merge( $stored, $incoming ) );
	}

	/**
	 * Post-save: compile config, purge on cache-relevant changes, sync drop-ins.
	 *
	 * @param mixed $old_value
	 * @param mixed $value
	 * @param mixed $option
	 * @return void
	 */
	public function after_save( $old_value, $value, $option ): void {
		// The update_option_ hook can fire from any context that writes the
		// option; only admins may trigger compile/purge side effects here.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$new = is_array( $value ) ? $value : array();
		$old = is_array( $old_value ) ? $old_value : array();

		Config::compile( $new );

		// Browser-caching block follows the enabled flag.
		if ( ! empty( $new['enabled'] ) ) {
			Config::installBrowserCache();
		} else {
			Config::removeBrowserCache();
		}

		$changed = false;
		foreach ( self::html_purge_keys() as $key ) {
			if ( ( $old[ $key ] ?? null ) !== ( $new[ $key ] ?? null ) ) {
				$changed = true;
				break;
			}
		}

		if ( $changed ) {
			\CosmicCache\Plugin::instance()->purger()->purge_all();
		}

		// Fresh critical CSS pasted: the snippet is current again — clear the
		// stale flag and record provenance for the Optimization tab display.
		$critical_changed = false;
		foreach ( self::critical_keys() as $key ) {
			if ( ( $old[ $key ] ?? null ) !== ( $new[ $key ] ?? null ) ) {
				$critical_changed = true;
				break;
			}
		}

		if ( $critical_changed ) {
			CriticalCSS::clearStale();
			CriticalCSS::recordMeta( (string) ( $new['critical_css'] ?? '' ) );
		}

		// Object-cache drop-in lifecycle.
		\CosmicCache\ObjectCache\DropInGenerator::generate( $new );

		// Preload cron.
		$interval = max( 60, (int) ( $new['preload_interval'] ?? 86400 ) );
		if ( ! empty( $new['preload_enabled'] ) ) {
			if ( ! wp_next_scheduled( 'cosmic_cache_preload' ) ) {
				wp_schedule_event( time() + $interval, 'cosmic_preload_cron', 'cosmic_cache_preload' );
			}
		} elseif ( wp_next_scheduled( 'cosmic_cache_preload' ) ) {
			wp_clear_scheduled_hook( 'cosmic_cache_preload' );
		}
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'settings_page_cosmic-cache' !== $hook ) {
			return;
		}
		// plugins_url() only honours the plugin folder when $plugin is a file
		// inside the plugin, so pass the entry file — never the bare dir.
		$entry = dirname( __DIR__, 2 ) . '/cosmic-cache.php';
		wp_enqueue_style( 'cosmic-cache-admin', plugins_url( 'assets/admin.css', $entry ), array(), \CosmicCache\Config::version() );
		wp_enqueue_script( 'cosmic-cache-admin', plugins_url( 'assets/admin.js', $entry ), array(), \CosmicCache\Config::version(), true );
		wp_localize_script(
			'cosmic-cache-admin',
			'cosmicCache',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'cosmic_cache_admin' ),
			)
		);
	}

	/**
	 * Render the settings page with the tab nav + current tab view.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab    = isset( $_GET['tab'] ) && isset( $this->tabs[ $_GET['tab'] ] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'page-cache';
		$url    = admin_url( 'options-general.php?page=cosmic-cache' );
		$active = $tab;

		echo '<div class="wrap cosmic-cache-wrap">';
		echo '<h1>' . esc_html__( 'Cosmic Cache', 'cosmic-cache' ) . '</h1>';

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $this->tabs as $key => $label ) {
			$class = $active === $key ? ' nav-tab-active' : '';
			echo '<a class="nav-tab' . esc_attr( $class ) . '" href="' . esc_url( add_query_arg( 'tab', $key, $url ) ) . '">'
				. esc_html__( $label, 'cosmic-cache' ) . '</a>';
		}
		echo '</h2>';

		echo '<form method="post" action="options.php">';
		settings_fields( 'cosmic_cache' );

		$settings = Config::load();
		$view     = self::view_for( $tab );
		if ( is_file( $view ) ) {
			include $view;
		}

		if ( 'tools' !== $tab ) {
			submit_button();
		}

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Absolute path to a tab's view. Exposed so unit tests can assert every
	 * tab has a real view without booting the WP admin.
	 *
	 * @param string $tab
	 * @return string
	 */
	public static function view_for( string $tab ): string {
		return dirname( __DIR__, 2 ) . '/views/' . $tab . '.php';
	}
}
