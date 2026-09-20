<?php
/**
 * CDN rewrite unit tests.
 *
 * @package cosmic-cache
 */

namespace CosmicCache\Tests;

use CosmicCache\Cdn\Cdn;
use PHPUnit\Framework\TestCase;

class CdnTest extends TestCase {

	private function settings( array $overrides = array() ): array {
		return array_merge(
			array(
				'cdn_url'     => 'https://cdn.example.com',
				'cdn_exclude' => array(),
			),
			$overrides
		);
	}

	public function test_rewrites_absolute_local_assets(): void {
		$html = '<img src="https://example.com/wp-content/uploads/a.png"><link rel="stylesheet" href="https://example.com/wp-content/themes/x/style.css">';
		$out  = ( new Cdn() )->process( $html, $this->settings() );

		$this->assertStringContainsString( 'https://cdn.example.com/wp-content/uploads/a.png', $out );
		$this->assertStringContainsString( 'https://cdn.example.com/wp-content/themes/x/style.css', $out );
	}

	public function test_rewrites_scheme_relative_local(): void {
		$html = '<script src="//example.com/wp-includes/js/app.js"></script>';
		$out  = ( new Cdn() )->process( $html, $this->settings() );
		$this->assertStringContainsString( 'https://cdn.example.com/wp-includes/js/app.js', $out );
	}

	public function test_rewrites_root_relative_assets(): void {
		$html = '<img src="/wp-content/uploads/logo.png">';
		$out  = ( new Cdn() )->process( $html, $this->settings() );
		$this->assertStringContainsString( 'https://cdn.example.com/wp-content/uploads/logo.png', $out );
	}

	public function test_keeps_local_page_links_untouched(): void {
		$html = '<a href="https://example.com/about/">about</a>';
		$out  = ( new Cdn() )->process( $html, $this->settings() );
		$this->assertStringContainsString( 'href="https://example.com/about/"', $out );
	}

	public function test_skips_excluded_extensions(): void {
		$html = '<img src="https://example.com/wp-content/uploads/a.gif">';
		$out  = ( new Cdn() )->process( $html, $this->settings( array( 'cdn_exclude' => array( '.gif' ) ) ) );
		$this->assertStringContainsString( 'https://example.com/wp-content/uploads/a.gif', $out );
	}

	public function test_protects_ld_json(): void {
		$html = '<script type="application/ld+json">{"url":"https://example.com/wp-content/uploads/a.png"}</script>';
		$out  = ( new Cdn() )->process( $html, $this->settings() );
		$this->assertStringContainsString( 'https://example.com/wp-content/uploads/a.png', $out );
	}

	public function test_does_not_touch_data_uris(): void {
		$html = '<img src="data:image/png;base64,AAAA">';
		$out  = ( new Cdn() )->process( $html, $this->settings() );
		$this->assertStringContainsString( 'data:image/png;base64,AAAA', $out );
	}

	public function test_noop_without_cdn_url(): void {
		$html = '<img src="https://example.com/a.png">';
		$out  = ( new Cdn() )->process( $html, $this->settings( array( 'cdn_url' => '' ) ) );
		$this->assertSame( $html, $out );
	}

	public function test_page_level_link_tags_are_not_rewritten(): void {
		$html = '<link rel="canonical" href="https://example.com/hello-world/">'
			. '<link rel="next" href="https://example.com/page/2/">'
			. '<link rel="alternate" type="application/rss+xml" title="Feed" href="https://example.com/feed/">'
			. '<link rel="https://api.w.org/" href="https://example.com/wp-json/">';
		$out  = ( new Cdn() )->process( $html, $this->settings() );

		$this->assertStringContainsString( 'rel="canonical" href="https://example.com/hello-world/"', $out );
		$this->assertStringContainsString( 'rel="next" href="https://example.com/page/2/"', $out );
		$this->assertStringContainsString( 'href="https://example.com/feed/"', $out );
		$this->assertStringContainsString( 'href="https://example.com/wp-json/"', $out );
		$this->assertStringNotContainsString( 'cdn.example.com', $out );
	}

	public function test_asset_link_tags_are_rewritten(): void {
		$html = '<link rel="stylesheet" href="https://example.com/wp-content/themes/x/style.css">'
			. '<link rel="icon" href="https://example.com/favicon.ico">'
			. '<link rel="preload" as="script" href="https://example.com/wp-includes/js/x.js">';
		$out  = ( new Cdn() )->process( $html, $this->settings() );

		$this->assertStringContainsString( 'https://cdn.example.com/wp-content/themes/x/style.css', $out );
		$this->assertStringContainsString( 'https://cdn.example.com/favicon.ico', $out );
		$this->assertStringContainsString( 'https://cdn.example.com/wp-includes/js/x.js', $out );
	}

	public function test_script_with_src_rewrites_inline_scripts_do_not(): void {
		$html = '<script src="https://example.com/wp-includes/js/app.js"></script>'
			. '<script>var x = "https://example.com/wp-content/uploads/a.png";</script>';
		$out  = ( new Cdn() )->process( $html, $this->settings() );

		$this->assertStringContainsString( 'https://cdn.example.com/wp-includes/js/app.js', $out );
		$this->assertStringContainsString( 'var x = "https://example.com/wp-content/uploads/a.png";', $out );
	}

	public function test_rewrites_srcset_preserving_descriptors(): void {
		$html = '<img src="https://example.com/a.png" srcset="https://example.com/a.png 1x, https://example.com/b.png 2x" sizes="100vw">';
		$out  = ( new Cdn() )->process( $html, $this->settings() );

		$this->assertStringContainsString( 'srcset="https://cdn.example.com/a.png 1x, https://cdn.example.com/b.png 2x"', $out );
		$this->assertStringContainsString( 'https://cdn.example.com/a.png', $out );
	}

	public function test_rewrites_source_srcset_inside_picture(): void {
		$html = '<picture><source media="(min-width: 1200px)" srcset="/wp-content/uploads/hero.jpg 1x, /wp-content/uploads/hero@2x.jpg 2x"><img src="/wp-content/uploads/hero.jpg" alt="h"></picture>';
		$out  = ( new Cdn() )->process( $html, $this->settings() );

		$this->assertStringContainsString( 'srcset="https://cdn.example.com/wp-content/uploads/hero.jpg 1x, https://cdn.example.com/wp-content/uploads/hero@2x.jpg 2x"', $out );
	}

	public function test_srcset_skips_only_excluded_candidates(): void {
		$html = '<img src="https://example.com/a.png" srcset="https://example.com/a.png 1x, https://example.com/b.gif 2x">';
		$out  = ( new Cdn() )->process( $html, $this->settings( array( 'cdn_exclude' => array( '.gif' ) ) ) );

		$this->assertStringContainsString( 'https://cdn.example.com/a.png 1x', $out );
		$this->assertStringContainsString( 'https://example.com/b.gif 2x', $out );
	}

	public function test_rewrites_modulepreload_and_manifest_links(): void {
		$html = '<link rel="modulepreload" href="https://example.com/wp-includes/js/mod.mjs">'
			. '<link rel="manifest" href="https://example.com/manifest.webmanifest">';
		$out  = ( new Cdn() )->process( $html, $this->settings() );

		$this->assertStringContainsString( 'https://cdn.example.com/wp-includes/js/mod.mjs', $out );
		$this->assertStringContainsString( 'https://cdn.example.com/manifest.webmanifest', $out );
	}
}
