<?php
/**
 * Combine asset-eligibility + path-traversal regression tests.
 *
 * url_to_path() previously joined the URL onto WP_CONTENT_DIR without
 * resolving `..`, so a crafted href like
 * /wp-content/../../etc/passwd could read arbitrary files. Both url_to_path()
 * and eligible() must reject traversal.
 *
 * @package cosmic-cache
 */

namespace CosmicCache\Tests;

use CosmicCache\Optimization\Combine;
use PHPUnit\Framework\TestCase;

class CombineTest extends TestCase {

	protected function setUp(): void {
		if ( ! defined( 'WP_CONTENT_URL' ) ) {
			define( 'WP_CONTENT_URL', 'https://example.com/wp-content' );
		}
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_url_to_path_rejects_traversal_outside_content_dir(): void {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/cosmic-tests/wp-content' );
		}
		if ( ! defined( 'WP_CONTENT_URL' ) ) {
			define( 'WP_CONTENT_URL', 'https://example.com/wp-content' );
		}

		$method = new \ReflectionMethod( Combine::class, 'url_to_path' );
		$method->setAccessible( true );
		$combine = new Combine();

		// A non-traversing local file resolves.
		@mkdir( WP_CONTENT_DIR . '/uploads', 0777, true );
		file_put_contents( WP_CONTENT_DIR . '/uploads/a.css', 'x' );
		$this->assertSame(
			realpath( WP_CONTENT_DIR . '/uploads/a.css' ),
			$method->invoke( $combine, 'https://example.com/wp-content/uploads/a.css' )
		);

		// Traversal that escapes WP_CONTENT resolves to null.
		$this->assertNull(
			$method->invoke( $combine, 'https://example.com/wp-content/../../etc/passwd' )
		);
		$this->assertNull(
			$method->invoke( $combine, 'https://example.com/wp-content/uploads/../../../passwd' )
		);
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_url_to_path_rejects_absolute_or_other_host(): void {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/cosmic-tests/wp-content' );
		}
		if ( ! defined( 'WP_CONTENT_URL' ) ) {
			define( 'WP_CONTENT_URL', 'https://example.com/wp-content' );
		}

		$method = new \ReflectionMethod( Combine::class, 'url_to_path' );
		$method->setAccessible( true );
		$combine = new Combine();

		$this->assertNull( $method->invoke( $combine, '/etc/passwd' ) );
		$this->assertNull( $method->invoke( $combine, 'https://evil.example.com/wp-content/x.css' ) );
	}

	public function test_eligible_rejects_traversal_and_bad_extensions(): void {
		$method = new \ReflectionMethod( Combine::class, 'eligible' );
		$method->setAccessible( true );
		$combine = new Combine();

		// `..` segments in the path are never eligible, even with a valid ext.
		$this->assertFalse(
			$method->invoke( $combine, 'https://example.com/wp-content/../../secret.css', 'css', array() )
		);

		// Wrong extension is never eligible.
		$this->assertFalse(
			$method->invoke( $combine, 'https://example.com/wp-content/uploads/a.jpg', 'css', array() )
		);
		$this->assertFalse(
			$method->invoke( $combine, 'https://example.com/wp-content/uploads/a.php', 'js', array() )
		);
	}

	private function combine_settings(): array {
		return array(
			'combine_css' => true,
			'exclude_css' => array(),
			'combine_js'  => false,
			'exclude_js'  => array(),
		);
	}

	private function css_files(): void {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/cosmic-tests/wp-content' );
		}
		if ( ! defined( 'WP_CONTENT_URL' ) ) {
			define( 'WP_CONTENT_URL', 'https://example.com/wp-content' );
		}
		@mkdir( WP_CONTENT_DIR . '/themes/t', 0777, true );
		file_put_contents( WP_CONTENT_DIR . '/themes/t/a.css', "body{color:red}\n" );
		file_put_contents( WP_CONTENT_DIR . '/themes/t/b.css', ".b{margin:0}\n" );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_process_combines_adjacent_same_dir_css(): void {
		$this->css_files();
		if ( ! defined( 'COSMIC_CACHE_DIR' ) ) {
			define( 'COSMIC_CACHE_DIR', WP_CONTENT_DIR . '/cache/cosmic' );
		}

		$html = '<link rel="stylesheet" href="https://example.com/wp-content/themes/t/a.css" media="all">'
			. '<link rel="stylesheet" href="https://example.com/wp-content/themes/t/b.css" media="all">';
		$out = ( new Combine() )->process( $html, $this->combine_settings() );

		$this->assertMatchesRegularExpression( '#https://example\.com/wp-content/cache/cosmic/combined/[a-f0-9]{40}\.css#', $out );
		$this->assertSame( 1, substr_count( $out, '<link rel="stylesheet"' ) );
		$this->assertStringNotContainsString( 'themes/t/a.css', $out );
		$this->assertStringNotContainsString( 'themes/t/b.css', $out );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_process_never_swallows_inline_content_between_links(): void {
		$this->css_files();
		if ( ! defined( 'COSMIC_CACHE_DIR' ) ) {
			define( 'COSMIC_CACHE_DIR', WP_CONTENT_DIR . '/cache/cosmic' );
		}

		// A <style> printed between two eligible links must NOT be swallowed by
		// the replacement; the run is split instead (audit C2).
		$html = '<link rel="stylesheet" href="https://example.com/wp-content/themes/t/a.css" media="all">'
			. '<style>.inline{color:blue}</style>'
			. '<link rel="stylesheet" href="https://example.com/wp-content/themes/t/b.css" media="all">';
		$out = ( new Combine() )->process( $html, $this->combine_settings() );

		$this->assertStringContainsString( '<style>.inline{color:blue}</style>', $out );
		$this->assertSame( 2, substr_count( $out, 'themes/t/' ) );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_process_skips_combining_when_cache_root_outside_content_dir(): void {
		$this->css_files();
		// COSMIC_CACHE_DIR outside WP_CONTENT_DIR: no web-addressable URL, so
		// combining must be skipped entirely rather than emit a dead link
		// (audit C2).
		if ( ! defined( 'COSMIC_CACHE_DIR' ) ) {
			define( 'COSMIC_CACHE_DIR', sys_get_temp_dir() . '/cosmic-tests-external-cache' );
		}

		$html = '<link rel="stylesheet" href="https://example.com/wp-content/themes/t/a.css" media="all">'
			. '<link rel="stylesheet" href="https://example.com/wp-content/themes/t/b.css" media="all">';
		$out = ( new Combine() )->process( $html, $this->combine_settings() );

		$this->assertSame( $html, $out );
	}
}
