<?php
/**
 * Config unit tests (defaults, sanitize, compile/read round-trip).
 *
 * @package cosmic-cache
 */

namespace CosmicCache\Tests { // phpcs:ignore

	use CosmicCache\Config;
	use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase {

	protected function setUp(): void {
		GLOBALS_filters_clear();
	}

	public function test_defaults_schema(): void {
		$defaults = Config::defaults();

		$this->assertArrayHasKey( 'enabled', $defaults );
		$this->assertArrayHasKey( 'ttl', $defaults );
		$this->assertArrayHasKey( 'exclude_cookies', $defaults );
		$this->assertArrayHasKey( 'cache_whitelist_query', $defaults );
		$this->assertArrayHasKey( 'minify_html', $defaults );
		$this->assertFalse( $defaults['enabled'] );
		$this->assertFalse( $defaults['minify_html'] );
		$this->assertContains( 'wordpress_logged_in_*', $defaults['exclude_cookies'] );
		$this->assertContains( 'paged', $defaults['cache_whitelist_query'] );
		$this->assertNotContains( 's', $defaults['cache_whitelist_query'] );
	}

	public function test_query_whitelist_excludes_reflected_marketing_params(): void {
		$defaults = Config::defaults();
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'gclid', 'fbclid', 'ref', 'mc_cid' ) as $param ) {
			$this->assertNotContains( $param, $defaults['cache_whitelist_query'] );
		}
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_multisite_compile_bakes_disabled_flag(): void {
		$dir = sys_get_temp_dir() . '/cosmic-config-' . bin2hex( random_bytes( 4 ) );
		define( 'COSMIC_CACHE_DIR', $dir );
		$GLOBALS['cosmic_test_multisite'] = true;

		$defaults          = Config::defaults();
		$defaults['enabled'] = true;

		$this->assertTrue( Config::compile( $defaults ) );
		$read = Config::read();
		$this->assertIsArray( $read );
		$this->assertFalse( $read['enabled'] );
		$this->assertTrue( $read['multisite_disabled'] );

		$this->cleanup( $dir );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_multisite_opt_in_allows_caching(): void {
		$dir = sys_get_temp_dir() . '/cosmic-config-' . bin2hex( random_bytes( 4 ) );
		define( 'COSMIC_CACHE_DIR', $dir );
		define( 'COSMIC_CACHE_ALLOW_MULTISITE', true );
		$GLOBALS['cosmic_test_multisite'] = true;

		$this->assertTrue( Config::multisiteAllowed() );

		$defaults          = Config::defaults();
		$defaults['enabled'] = true;
		Config::compile( $defaults );

		$read = Config::read();
		$this->assertTrue( $read['enabled'] );
		$this->assertArrayNotHasKey( 'multisite_disabled', $read );

		$this->cleanup( $dir );
	}

	public function test_single_site_is_always_allowed(): void {
		$GLOBALS['cosmic_test_multisite'] = false;
		$this->assertTrue( Config::multisiteAllowed() );
	}

	public function test_sanitize_types(): void {
		$clean = Config::sanitize(
			array(
				'enabled'          => '1',
				'ttl'              => 'abc',
				'cache_cookies'    => 'a,b',
				'bypass_query'     => array( 'x', 5, array() ),
				'cdn_url'          => array( 'bad' ),
				'preload_interval' => 10,
			)
		);

		$this->assertTrue( $clean['enabled'] );
		$this->assertSame( 0, $clean['ttl'] );
		$this->assertSame( array( 'a', 'b' ), $clean['cache_cookies'] );
		$this->assertSame( array( 'x', '5' ), $clean['bypass_query'] );
		$this->assertSame( '', $clean['cdn_url'] );
		$this->assertSame( 60, $clean['preload_interval'] );
	}

	public function test_sanitize_keeps_defaults_for_missing_keys(): void {
		$clean = Config::sanitize( array() );
		$this->assertSame( 86400, $clean['ttl'] );
		$this->assertTrue( $clean['gzip'] );
		$this->assertSame( 'php', $clean['serve_method'] );
	}

	public function test_sanitize_serve_method_only_accepts_known_values(): void {
		$this->assertSame( 'php', Config::sanitize( array( 'serve_method' => 'php' ) )['serve_method'] );
		$this->assertSame( 'x-sendfile', Config::sanitize( array( 'serve_method' => 'x-sendfile' ) )['serve_method'] );
		$this->assertSame( 'x-accel-redirect', Config::sanitize( array( 'serve_method' => 'x-accel-redirect' ) )['serve_method'] );
		$this->assertSame( 'php', Config::sanitize( array( 'serve_method' => '../etc/passwd' ) )['serve_method'] );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_compile_and_read_round_trip(): void {
		$dir = sys_get_temp_dir() . '/cosmic-config-' . bin2hex( random_bytes( 4 ) );
		define( 'COSMIC_CACHE_DIR', $dir );

		$defaults          = Config::defaults();
		$defaults['enabled'] = true;

		$this->assertTrue( Config::compile( $defaults ) );
		$this->assertFileExists( Config::configFile() );

		$read = Config::read();
		$this->assertIsArray( $read );
		$this->assertTrue( $read['enabled'] );

		$this->cleanup( $dir );
	}

	public function test_browser_cache_rules_look_sane(): void {
		$rules = Config::browserCacheRules();

		$this->assertStringContainsString( '# BEGIN Cosmic Cache', $rules );
		$this->assertStringContainsString( '# END Cosmic Cache', $rules );
		$this->assertStringContainsString( 'mod_expires', $rules );
		$this->assertStringContainsString( 'mod_headers', $rules );
		$this->assertStringContainsString( 'woff2', $rules );
		$this->assertStringContainsString( 'javascript', $rules );

		// mod_headers fallback must cover what ExpiresByType alone cannot
		// guarantee (mod_expires is commonly off on shared hosting): CSS/JS
		// immutable for a year, versionless content images for a month.
		$this->assertStringContainsString( 'max-age=31536000, immutable', $rules );
		$this->assertStringContainsString( 'max-age=2592000', $rules );
		$this->assertMatchesRegularExpression( '/FilesMatch[^\\n]*jpe\\?g/', $rules );
		$this->assertMatchesRegularExpression( '/FilesMatch[^\\n]*webp/', $rules );
		$this->assertMatchesRegularExpression( '/FilesMatch[^\\n]*\\(css\\|js\\)/', $rules );
	}

	public function test_browser_cache_install_is_idempotent_and_preserves_foreign(): void {
		$path = ABSPATH . '.htaccess';
		$original = is_file( $path ) ? (string) file_get_contents( $path ) : null;

		try {
			$foreign = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n";
			file_put_contents( $path, $foreign );

			// Install twice: block present exactly once, foreign rules intact.
			$this->assertTrue( Config::installBrowserCache() );
			$this->assertTrue( Config::installBrowserCache() );

			$content = (string) file_get_contents( $path );
			$this->assertSame( 1, substr_count( $content, '# BEGIN Cosmic Cache' ) );
			$this->assertSame( 1, substr_count( $content, '# END Cosmic Cache' ) );
			$this->assertStringContainsString( 'RewriteEngine On', $content );
			$this->assertStringContainsString( '# BEGIN WordPress', $content );

			// Removal restores the foreign content.
			$this->assertTrue( Config::removeBrowserCache() );
			$after = (string) file_get_contents( $path );
			$this->assertStringNotContainsString( '# BEGIN Cosmic Cache', $after );
			$this->assertStringContainsString( 'RewriteEngine On', $after );
		} finally {
			// Restore whatever was there before (other tests share ABSPATH).
			if ( null !== $original ) {
				file_put_contents( $path, $original );
			} elseif ( is_file( $path ) ) {
				@unlink( $path );
			}
		}
	}

	public function test_runtime_returns_enabled_key(): void {
		$this->assertArrayHasKey( 'enabled', Config::runtime() );
		$this->assertArrayHasKey( 'ttl', Config::runtime() );
	}

	public function test_integration_filters_apply_to_loaded_settings(): void {
		add_filter( 'cosmic_cache_settings', static function ( $settings ) {
			$settings['cache_cookies'][] = 'woocommerce_items_in_cart';
			$settings['exclude_urls'][]  = '/checkout';
			return $settings;
		} );

		$loaded = Config::load();
		$this->assertContains( 'woocommerce_items_in_cart', $loaded['cache_cookies'] );
		$this->assertContains( '/checkout', $loaded['exclude_urls'] );

		// Runtime path (capture/purge) sees the same rules.
		$runtime = Config::runtime();
		$this->assertContains( 'woocommerce_items_in_cart', $runtime['cache_cookies'] );
	}

	public function test_integration_filter_non_array_result_is_ignored(): void {
		add_filter( 'cosmic_cache_settings', static function () {
			return 'not-an-array';
		} );

		$loaded = Config::load();
		$this->assertIsArray( $loaded );
		$this->assertArrayHasKey( 'enabled', $loaded );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_integration_filters_bake_into_compiled_config(): void {
		$dir = sys_get_temp_dir() . '/cosmic-config-' . bin2hex( random_bytes( 4 ) );
		define( 'COSMIC_CACHE_DIR', $dir );

		add_filter( 'cosmic_cache_settings', __NAMESPACE__ . '\add_lang_cookie_filter' );

		$defaults          = Config::defaults();
		$defaults['enabled'] = true;
		$this->assertTrue( Config::compile( $defaults ) );

		$read = Config::read();
		$this->assertIsArray( $read );
		$this->assertContains( 'lang', $read['cache_cookies'] );

		$this->cleanup( $dir );
	}

	/**
	 * compile() must not re-apply the integration filter when settings come
	 * from load() (which already applies it): appending filters would run
	 * twice and duplicate list entries such as 'lang'.
	 *
	 * @runInSeparateProcess
	 */
	public function test_compile_without_args_does_not_double_apply_filters(): void {
		$dir = sys_get_temp_dir() . '/cosmic-config-' . bin2hex( random_bytes( 4 ) );
		define( 'COSMIC_CACHE_DIR', $dir );

		add_filter( 'cosmic_cache_settings', __NAMESPACE__ . '\add_lang_cookie_filter' );

		$this->assertTrue( Config::compile() );

		$read = Config::read();
		$this->assertIsArray( $read );
		$this->assertSame( array( 'lang' ), $read['cache_cookies'] );

		$this->cleanup( $dir );
	}

	/**
	 * Recursively remove a temp cache dir created by a test.
	 *
	 * @param string $dir
	 */
	private function cleanup( string $dir ): void {
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $f ) {
			$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
		}
		@rmdir( $dir );
	}
}

	/**
	 * Adds the WPML `lang` vary cookie to the resolved settings. Declared as a
	 * named function (not a closure) so it survives PHPUnit separate-process
	 * global-state serialization.
	 *
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	function add_lang_cookie_filter( array $settings ): array {
		$settings['cache_cookies'][] = 'lang';
		return $settings;
	}
}
