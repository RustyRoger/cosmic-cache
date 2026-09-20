<?php
/**
 * PageCache fail-open tests: a throwing settings or HTML-stage filter must
 * never 500 a request — capture() and maybe_start_buffer() degrade to serving
 * WordPress normally (SPEC §7, production audit A5).
 *
 * @package cosmic-cache
 */

namespace CosmicCache\Tests { // phpcs:ignore

	use CosmicCache\Cache\PageCache;
	use PHPUnit\Framework\TestCase;

	class PageCacheTest extends TestCase {

		/**
		 * @var array<string,mixed>
		 */
		private $server;

		protected function setUp(): void {
			GLOBALS_filters_clear();
			$this->server = $_SERVER;
			$_SERVER['HTTP_HOST']    = 'example.com';
			$_SERVER['REQUEST_URI']  = '/';
			$_SERVER['REQUEST_METHOD'] = 'GET';
		}

		protected function tearDown(): void {
			$_SERVER = $this->server;
			GLOBALS_filters_clear();
		}

		public function test_capture_returns_html_when_a_stage_filter_throws(): void {
			add_filter(
				'cosmic_cache_html_stage_cleanup',
				static function () {
					throw new \RuntimeException( 'boom' );
				}
			);

			$html = '<p>hello world</p>';
			$this->assertSame( $html, ( new PageCache() )->capture( $html ) );
		}

		public function test_maybe_start_buffer_survives_a_throwing_settings_filter(): void {
			add_filter(
				'cosmic_cache_settings',
				static function () {
					throw new \RuntimeException( 'boom' );
				}
			);

			$level = ob_get_level();
			( new PageCache() )->maybe_start_buffer();

			// No buffer was opened (the throw happened before ob_start) and the
			// request did not fatal.
			$this->assertSame( $level, ob_get_level() );
		}
	}
}