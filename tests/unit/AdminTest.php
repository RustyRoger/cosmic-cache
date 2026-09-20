<?php
/**
 * Admin surface unit tests (settings page wiring).
 *
 * Guards against the path-resolution regression that left the settings page
 * empty: every tab must resolve to a real view file and the admin assets must
 * exist next to the plugin root.
 *
 * @package cosmic-cache
 */

namespace CosmicCache\Tests { // phpcs:ignore

	use CosmicCache\Admin\SettingsPage;
	use CosmicCache\Config;
	use PHPUnit\Framework\TestCase;

class AdminTest extends TestCase {

	public function test_every_tab_has_a_view_file(): void {
		$tabs = array( 'page-cache', 'optimization', 'cdn', 'object-cache', 'tools' );

		foreach ( $tabs as $tab ) {
			$this->assertFileExists(
				SettingsPage::view_for( $tab ),
				"View missing for tab '{$tab}' — settings page would render empty."
			);
		}
	}

	public function test_admin_assets_exist_at_plugin_root(): void {
		$root = dirname( __DIR__, 2 );

		$this->assertFileExists( $root . '/assets/admin.css' );
		$this->assertFileExists( $root . '/assets/admin.js' );
		// plugins_url() needs a plugin-internal FILE to derive the folder; a
		// missing entry file here would silently produce .../plugins/assets/...
		$this->assertFileExists( $root . '/cosmic-cache.php' );
	}

	public function test_views_and_assets_resolve_above_src(): void {
		// Regression: SettingsPage lives in src/Admin and used dirname(__DIR__)
		// once, resolving to <root>/src/ instead of <root>/. Views and assets
		// must live at the plugin root, not under src/.
		$root = dirname( __DIR__, 2 );

		$this->assertStringStartsWith( $root . '/views/', SettingsPage::view_for( 'page-cache' ) );
		$this->assertStringNotContainsString( '/src/', SettingsPage::view_for( 'page-cache' ) );
	}

	public function test_sanitize_preserves_untouched_tabs(): void {
		// Regression: the page renders one form per tab, so a save from "Page
		// Cache" only submits that tab's keys. sanitize() must merge over the
		// stored settings; otherwise the untouched "Optimization" keys fall
		// back to defaults and get wiped on every save.
		$stored = Config::sanitize( array(
			'lazyload'    => true,
			'minify_css'  => true,
			'minify_js'   => false,
			'combine_css' => true,
		) );
		$GLOBALS['cosmic_test_options'] = array();
		update_option( Config::OPTION_KEY, $stored );

		$page = new SettingsPage();
		$out  = $page->sanitize( array(
			'enabled'  => '1',
			'ttl'      => '3600',
			'gzip'     => '1',
			'mobile'   => '0',
			'loggedin' => '0',
		) );

		$this->assertTrue( $out['lazyload'] );
		$this->assertTrue( $out['minify_css'] );
		$this->assertFalse( $out['minify_js'] );
		$this->assertTrue( $out['combine_css'] );
		$this->assertTrue( $out['enabled'] );
		$this->assertSame( 3600, $out['ttl'] );
	}

	public function test_html_purge_keys_cover_pipeline_and_critical(): void {
		$keys = SettingsPage::html_purge_keys();

		// Page-cache keys that alter stored bytes.
		foreach ( array( 'enabled', 'ttl', 'gzip', 'brotli', 'cache_headers' ) as $key ) {
			$this->assertContains( $key, $keys );
		}

		// Whole optimization pipeline + CDN rewrite alter stored bytes too.
		foreach ( array(
			'minify_html', 'minify_css', 'minify_js',
			'combine_css', 'combine_js',
			'defer_js', 'defer_exclude',
			'delay_js', 'delay_js_exclude',
			'critical_css_enabled', 'critical_css', 'critical_css_exclude',
			'exclude_css', 'exclude_js',
			'lazyload', 'lazyload_iframes',
			'remove_emoji', 'dns_prefetch', 'remove_query_strings',
			'cdn_url', 'cdn_exclude',
		) as $key ) {
			$this->assertContains( $key, $keys, "Pipeline key '{$key}' must purge the page cache on change." );
		}

		// Delivery-only, scheduling, display, debug and object-cache keys must
		// NOT nuke the page cache.
		foreach ( array(
			'serve_method',
			'preload_enabled', 'preload_sitemap', 'preload_interval',
			'show_admin_bar_button', 'debug',
			'object_cache_enabled', 'object_cache_backend', 'object_cache_host',
			'object_cache_port', 'object_cache_prefix',
		) as $key ) {
			$this->assertNotContains( $key, $keys );
		}

		$this->assertSame(
			array( 'critical_css_enabled', 'critical_css', 'critical_css_exclude' ),
			SettingsPage::critical_keys()
		);
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_optimization_view_renders_fields_not_source(): void {
		// Regression (Fase 2a): helper calls after an HTML block were left
		// outside the PHP tags, printing literal source instead of fields.
		// php -l and PHPCS cannot catch it — only rendering does.
		//
		// Runs isolated: including the view would otherwise pollute
		// get_included_files(), and PHPUnit re-requires every included file
		// in @runInSeparateProcess children (CombineTest/ConfigTest), where
		// the view would execute without $settings and fatal.
		$settings = Config::defaults();

		ob_start();
		include SettingsPage::view_for( 'optimization' );
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="cosmic_cache_settings[lazyload]"', $out );
		$this->assertStringContainsString( 'name="cosmic_cache_settings[critical_css]"', $out );
		$this->assertStringNotContainsString( 'cosmic_field_checkbox(', $out );
		$this->assertStringNotContainsString( 'cosmic_field_textarea(', $out );
		$this->assertStringNotContainsString( '?>', $out );
	}
}
}