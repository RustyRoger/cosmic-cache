<?php
/**
 * Minify + Cleanup + DeferJS + LazyLoad unit tests.
 *
 * @package cosmic-cache
 */

namespace CosmicCache\Tests;

use CosmicCache\Optimization\Cleanup;
use CosmicCache\Optimization\Combine;
use CosmicCache\Optimization\CriticalCSS;
use CosmicCache\Optimization\DeferJS;
use CosmicCache\Optimization\DelayJS;
use CosmicCache\Optimization\LazyLoad;
use CosmicCache\Optimization\Minify;
use PHPUnit\Framework\TestCase;

class OptimizationTest extends TestCase {

	private function settings( array $overrides = array() ): array {
		return array_merge(
			array(
				'minify_html'         => false,
				'remove_emoji'         => false,
				'dns_prefetch'         => array(),
				'remove_query_strings' => false,
				'minify_css'           => false,
				'minify_js'            => false,
				'defer_js'             => false,
				'defer_exclude'        => array(),
				'delay_js'             => false,
				'delay_js_exclude'     => array(),
				'critical_css_enabled' => false,
				'critical_css'         => '',
				'critical_css_exclude' => array(),
				'lazyload'             => false,
				'lazyload_iframes'     => false,
			),
			$overrides
		);
	}

	private function minify_settings(): array {
		return $this->settings( array( 'minify_html' => true ) );
	}

	// --- Minify ---

	public function test_minify_is_off_by_default(): void {
		$html = "<!-- c --><p>Contact <a href=\"/x\">us</a> today.</p>";
		$min  = ( new Minify() )->process( $html, $this->settings() );
		$this->assertSame( $html, $min );
	}

	public function test_minify_preserves_pre_and_textarea(): void {
		$html  = "<p>   a   </p>\n<pre>  keep   me   </pre>\n<textarea> line\n  break </textarea>\n<p>  b </p>";
		$min   = ( new Minify() )->process( $html, $this->minify_settings() );

		$this->assertStringContainsString( '<pre>  keep   me   </pre>', $min );
		$this->assertStringContainsString( '<textarea> line', $min );
		$this->assertStringContainsString( '<p> a </p>', $min );
	}

	public function test_minify_never_merges_visible_words(): void {
		$html = "<p>Contact <a href=\"/x\">us</a> today. Total: 5.</p>";
		$min  = ( new Minify() )->process( $html, $this->minify_settings() );
		$this->assertStringContainsString( 'Contact <a href="/x">us</a> today. Total: 5.</p>', $min );
		$this->assertStringNotContainsString( 'Contact<a', $min );
		$this->assertStringNotContainsString( 'us</a>today', $min );
	}

	public function test_minify_preserves_meaningful_comments(): void {
		$html = "<!--#include virtual=\"/ad.html\" -->"
			. "<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->"
			. "<!--more--><!--nextpage-->"
			. "<!-- plain --><p>hi</p>";
		$min  = ( new Minify() )->process( $html, $this->minify_settings() );

		$this->assertStringContainsString( '<!--#include virtual="/ad.html" -->', $min );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $min );
		$this->assertStringContainsString( '<!-- /wp:paragraph -->', $min );
		$this->assertStringContainsString( '<!--more-->', $min );
		$this->assertStringContainsString( '<!--nextpage-->', $min );
		$this->assertStringNotContainsString( 'plain', $min );
	}

	public function test_minify_strips_html_comments_when_enabled(): void {
		$html = "<!-- regular --><p>hi</p><!-- another -->";
		$min  = ( new Minify() )->process( $html, $this->minify_settings() );
		$this->assertStringNotContainsString( 'regular', $min );
		$this->assertStringContainsString( '<p>hi</p>', $min );
	}

	public function test_minify_preserves_ie_conditionals(): void {
		$html = "<!--[if IE]><p>IE</p><![endif]--><p>ok</p>";
		$min  = ( new Minify() )->process( $html, $this->minify_settings() );
		$this->assertStringContainsString( '<!--[if IE]><p>IE</p><![endif]-->', $min );
	}

	public function test_minify_css_preserves_calc(): void {
		$css = ".a{width:calc(100% + 10px);margin:0}";
		$this->assertStringContainsString( 'calc(100% + 10px)', Minify::minify_css( $css ) );
	}

	public function test_minify_js_never_merges_operators_or_breaks_asi(): void {
		$js = "var a = 1 + +b;\nvar r = /^ +$/;\nreturn\n{ x: 1 };";
		$this->assertStringContainsString( '1 + +b', Minify::minify_js( $js ) );
		$this->assertStringContainsString( '/^ +$/', Minify::minify_js( $js ) );
		$this->assertStringContainsString( "return\n{ x: 1 };", Minify::minify_js( $js ) );
	}

	public function test_minify_css_preserves_media_and_strings(): void {
		$css  = "@media (max-width: 600px) { .a { content: 'x y'; } } /* comment */ .b { color: red; }";
		$min  = Minify::minify_css( $css );

		$this->assertStringContainsString( '@media (max-width:600px){.a{content:\'x y\'}}', $min );
		$this->assertStringNotContainsString( 'comment', $min );
		$this->assertStringContainsString( '.b{color:red}', $min );
	}

	public function test_minify_js_preserves_strings(): void {
		$js  = "var a = 'hello // world'; // comment\nvar b = 1 + 1;";
		$min = Minify::minify_js( $js );
		$this->assertStringNotContainsString( 'comment', $min );
		$this->assertStringContainsString( "'hello // world'", $min );
	}

	// --- Cleanup ---

	public function test_cleanup_removes_emoji(): void {
		$html = '<script src="http://example.com/wp-includes/js/wp-emoji-release.min.js?ver=1.0"></script>'
			. '<link rel="stylesheet" id="wp-emoji-styles-css" href="http://example.com/wp-includes/css/emoji.css?ver=1.0">'
			. '<style id="wp-emoji-styles">img.emoji{}</style><p>ok</p>';

		$out = ( new Cleanup() )->process( $html, $this->settings( array( 'remove_emoji' => true ) ) );
		$this->assertStringNotContainsString( 'wp-emoji', $out );
		$this->assertStringContainsString( '<p>ok</p>', $out );
	}

	public function test_cleanup_injects_dns_prefetch(): void {
		$html = '<html><head><title>t</title></head><body>x</body></html>';
		$out  = ( new Cleanup() )->process( $html, $this->settings( array( 'dns_prefetch' => array( 'https://cdn.example.com' ) ) ) );
		$this->assertStringContainsString( '<link rel="dns-prefetch" href="https://cdn.example.com">', $out );
		$this->assertStringContainsString( '<link rel="preconnect" href="https://cdn.example.com">', $out );
	}

	public function test_cleanup_strips_query_strings(): void {
		$html = '<script src="http://example.com/theme.js?ver=5.2.1"></script>';
		$out  = ( new Cleanup() )->process( $html, $this->settings( array( 'remove_query_strings' => true ) ) );
		$this->assertStringContainsString( 'theme.js"', $out );
		$this->assertStringNotContainsString( 'ver=', $out );
	}

	public function test_cleanup_query_strings_keep_trailing_params_and_single_quotes(): void {
		$html = '<link rel="stylesheet" href="https://example.com/a.css?ver=1.2&x=2">'
			. "<script src='https://example.com/b.js?x=1&ver=9&y=2'></script>"
			. '<script src="https://example.com/c.js?ver=1"></script>'
			. '<script src="https://example.com/d.js?x=5"></script>';
		$out  = ( new Cleanup() )->process( $html, $this->settings( array( 'remove_query_strings' => true ) ) );

		// Trailing params survive a leading ?ver=; the separator is restored.
		$this->assertStringContainsString( 'https://example.com/a.css?x=2"', $out );
		// Single-quoted values work; params around the ver= stay intact.
		$this->assertStringContainsString( "'https://example.com/b.js?x=1&y=2'", $out );
		// Last param removed entirely.
		$this->assertStringContainsString( 'https://example.com/c.js"></script>', $out );
		// No ver → untouched.
		$this->assertStringContainsString( 'https://example.com/d.js?x=5"', $out );
		$this->assertStringNotContainsString( 'ver=', $out );
	}

	// --- DeferJS ---

	public function test_defer_adds_defer_to_external_scripts(): void {
		$html = '<script src="http://example.com/app.js"></script><p>x</p>';
		$out  = ( new DeferJS() )->process( $html, $this->settings( array( 'defer_js' => true ) ) );
		$this->assertStringContainsString( '<script src="http://example.com/app.js" defer>', $out );
	}

	public function test_defer_skips_excluded_and_async(): void {
		$html = '<script src="http://example.com/a.js"></script><script async src="http://example.com/b.js"></script>';
		$out  = ( new DeferJS() )->process( $html, $this->settings( array( 'defer_js' => true ) ) );
		$this->assertStringContainsString( 'async', $out );
		$this->assertSame( 1, substr_count( $out, 'defer' ) );
	}

	public function test_defer_wraps_inline_script(): void {
		$html = '<script>alert(1);</script>';
		$out  = ( new DeferJS() )->process( $html, $this->settings( array( 'defer_js' => true ) ) );
		$this->assertStringContainsString( "DOMContentLoaded", $out );
		$this->assertStringContainsString( 'alert(1);', $out );
	}

	public function test_defer_does_not_wrap_inline_jquery_dollar_function(): void {
		// `$(function` must be recognized as already-deferred; the fixed needle
		// (previously the literal `\$(function`) is the regression under test.
		$html = '<script>$(function() { init(); });</script><p>ok</p>';
		$out  = ( new DeferJS() )->process( $html, $this->settings( array( 'defer_js' => true ) ) );
		$this->assertStringNotContainsString( 'DOMContentLoaded', $out );
		$this->assertStringContainsString( '$(function() { init(); });', $out );
	}

	// --- DelayJS ---

	public function test_delayjs_off_leaves_html_untouched(): void {
		$html = '<script src="http://example.com/app.js"></script><p>x</p>';
		$out  = ( new DelayJS() )->process( $html, $this->settings() );
		$this->assertSame( $html, $out );
	}

	public function test_delayjs_parks_external_scripts_and_injects_loader_once(): void {
		$html = '<head></head><body>'
			. '<script src="http://example.com/app.js"></script>'
			. '<script src="http://example.com/analytics.js"></script></body>';
		$out  = ( new DelayJS() )->process( $html, $this->settings( array( 'delay_js' => true ) ) );

		$this->assertStringContainsString( '<script type="cosmiclazyload" src="http://example.com/app.js">', $out );
		$this->assertStringContainsString( '<script type="cosmiclazyload" src="http://example.com/analytics.js">', $out );
		// The restore loader is emitted exactly once.
		$this->assertSame( 1, substr_count( $out, 'cosmiclazyload' ) - substr_count( $out, 'type="cosmiclazyload"' ) );
		$this->assertStringContainsString( 'DOMContentLoaded', $out );
	}

	public function test_delayjs_skips_excluded_async_jsonld_and_inline(): void {
		$html = '<head></head><body>'
			. '<script src="http://example.com/jquery.js"></script>'
			. '<script async src="http://example.com/b.js"></script>'
			. '<script type="application/ld+json">{"@type":"Thing"}</script>'
			. '<script>alert(1);</script></body>';
		$out  = ( new DelayJS() )->process(
			$html,
			$this->settings( array( 'delay_js' => true, 'delay_js_exclude' => array( 'jquery' ) ) )
		);

		$this->assertStringNotContainsString( 'type="cosmiclazyload" src="http://example.com/jquery.js"', $out );
		$this->assertStringContainsString( '<script async src="http://example.com/b.js"></script>', $out );
		$this->assertStringContainsString( '<script type="application/ld+json">', $out );
		$this->assertStringContainsString( '<script>alert(1);</script>', $out );
	}

	public function test_delayjs_honors_defer_exclude_too(): void {
		$html = '<head></head><body><script src="http://example.com/ga.js"></script></body>';
		$out  = ( new DelayJS() )->process(
			$html,
			$this->settings( array( 'delay_js' => true, 'defer_exclude' => array( 'ga.js' ) ) )
		);
		$this->assertStringContainsString( '<script src="http://example.com/ga.js"></script>', $out );
		$this->assertStringNotContainsString( 'cosmiclazyload" src="http://example.com/ga.js"', $out );
	}

	// --- LazyLoad ---

	public function test_lazyload_swaps_image_and_noscript(): void {
		$html = '<img src="http://example.com/wp-content/uploads/a.png" class="wp-image-1" alt="a">';
		$out  = ( new LazyLoad() )->process( $html, $this->settings( array( 'lazyload' => true ) ) );

		$this->assertStringContainsString( 'data-cosmic-src="http://example.com/wp-content/uploads/a.png"', $out );
		$this->assertStringContainsString( 'loading="lazy"', $out );
		$this->assertStringContainsString( '<noscript>', $out );
	}

	public function test_lazyload_skips_eager_and_skips_observer_when_no_swaps(): void {
		$html = '<img src="http://example.com/a.png" loading="eager">';
		$out  = ( new LazyLoad() )->process( $html, $this->settings( array( 'lazyload' => true ) ) );
		$this->assertStringNotContainsString( 'data-cosmic-src', $out );
		$this->assertStringNotContainsString( 'IntersectionObserver', $out );
	}

	public function test_lazyload_injects_observer(): void {
		$html = '<body><img src="http://example.com/a.png" class="x"></body>';
		$out  = ( new LazyLoad() )->process( $html, $this->settings( array( 'lazyload' => true ) ) );
		$this->assertStringContainsString( 'IntersectionObserver', $out );
	}

	public function test_lazyload_never_rewrites_bare_or_srcless_images(): void {
		// Dead guard under audit-C4: only images with BOTH src and class are
		// touched. Previously the guard block was a no-op, so the tracker and
		// the srcless image were rewritten too.
		$html = '<img src="https://example.com/tracker.gif">'
			. '<img class="wp-image-1" alt="no-src">'
			. '<img src="https://example.com/photo.png" class="wp-image-2">';
		$out  = ( new LazyLoad() )->process( $html, $this->settings( array( 'lazyload' => true ) ) );

		$this->assertSame( 1, substr_count( $out, 'data-cosmic-src=' ) );
		$this->assertSame( 1, substr_count( $out, '<noscript>' ) );
		$this->assertStringContainsString( 'data-cosmic-src="https://example.com/photo.png"', $out );
		// The two ineligible images are byte-identical to their input.
		$this->assertStringContainsString( '<img src="https://example.com/tracker.gif">', $out );
		$this->assertStringContainsString( '<img class="wp-image-1" alt="no-src">', $out );
	}

	public function test_lazyload_handles_srcset_and_keeps_sizes(): void {
		$html = '<img src="https://example.com/a.png" srcset="https://example.com/a.png 1x, https://example.com/b.png 2x" sizes="(max-width: 600px) 100vw, 50vw" class="wp-image-1">';
		$out  = ( new LazyLoad() )->process( $html, $this->settings( array( 'lazyload' => true ) ) );

		$this->assertStringContainsString( 'data-cosmic-srcset="https://example.com/a.png 1x, https://example.com/b.png 2x"', $out );
		$this->assertStringContainsString( 'sizes="(max-width: 600px) 100vw, 50vw"', $out );
		$this->assertStringContainsString( 'data-cosmic-src="https://example.com/a.png"', $out );
		// Only the <noscript> fallback keeps a live srcset (the main <img> moved
		// it into data-cosmic-srcset).
		$this->assertSame( 1, substr_count( $out, ' srcset="' ) );
	}

	// --- CriticalCSS ---

	public function test_criticalcss_off_leaves_html_untouched(): void {
		$html = '<head><link rel="stylesheet" href="http://example.com/style.css"></head><p>x</p>';
		$out  = ( new CriticalCSS() )->process( $html, $this->settings() );
		$this->assertSame( $html, $out );
	}

	public function test_criticalcss_empty_snippet_is_a_noop(): void {
		$html = '<head><link rel="stylesheet" href="http://example.com/style.css"></head>';
		$out  = ( new CriticalCSS() )->process( $html, $this->settings( array( 'critical_css_enabled' => true ) ) );
		$this->assertSame( $html, $out );
	}

	public function test_criticalcss_inlines_snippet_and_asyncs_stylesheets(): void {
		$html = '<head><link rel="stylesheet" href="http://example.com/a.css">'
			. '<link rel="stylesheet" href="http://example.com/b.css" media="all"></head><body>x</body>';
		$out  = ( new CriticalCSS() )->process(
			$html,
			$this->settings( array(
				'critical_css_enabled' => true,
				'critical_css'         => 'body{color:#333}',
			) )
		);

		$this->assertStringContainsString( '<style id="cosmic-critical-css">body{color:#333}</style>', $out );
		$this->assertSame( 2, substr_count( $out, 'rel="preload" as="style"' ) );
		$this->assertSame( 2, substr_count( $out, 'onload="this.onload=null;this.rel=\'stylesheet\'"' ) );
		// Every async'd sheet keeps a <noscript> fallback with the original tag.
		$this->assertSame( 2, substr_count( $out, '<noscript><link rel="stylesheet" href="http://example.com/' ) );
		// No live render-blocking stylesheet remains outside noscript.
		$this->assertSame( 2, substr_count( $out, '</noscript>' ) );
	}

	public function test_criticalcss_keeps_excluded_and_non_all_media_links(): void {
		$html = '<head>'
			. '<link rel="stylesheet" href="http://example.com/keep.css" id="vital-css">'
			. '<link rel="stylesheet" href="http://example.com/print.css" media="print">'
			. '</head>';
		$out  = ( new CriticalCSS() )->process(
			$html,
			$this->settings( array(
				'critical_css_enabled' => true,
				'critical_css'         => 'body{color:#333}',
				'critical_css_exclude' => array( 'vital' ),
			) )
		);

		// Excluded + print stays a live render-blocking/enabled stylesheet.
		$this->assertSame( 2, substr_count( $out, '<link rel="stylesheet"' ) );
		$this->assertStringContainsString( 'href="http://example.com/keep.css" id="vital-css"', $out );
		$this->assertStringContainsString( 'media="print"', $out );
		$this->assertStringNotContainsString( 'rel="preload" as="style"', $out );
	}

	public function test_criticalcss_merges_exclude_css_handles(): void {
		$html = '<head><link rel="stylesheet" href="http://example.com/ga.css"></head>';
		$out  = ( new CriticalCSS() )->process(
			$html,
			$this->settings( array(
				'critical_css_enabled' => true,
				'critical_css'         => 'body{color:#333}',
				'exclude_css'          => array( 'ga.css' ),
			) )
		);
		$this->assertSame( 1, substr_count( $out, '<link rel="stylesheet"' ) );
		$this->assertStringNotContainsString( 'preload', $out );
	}

	// --- CriticalCSS stale flag + provenance meta ---

	public function test_criticalcss_stale_flag_roundtrip(): void {
		$GLOBALS['cosmic_test_options'] = array();

		$this->assertFalse( CriticalCSS::isStale() );

		CriticalCSS::markStale();
		$this->assertTrue( CriticalCSS::isStale() );

		CriticalCSS::clearStale();
		$this->assertFalse( CriticalCSS::isStale() );
	}

	public function test_criticalcss_onupgrader_flags_plugin_and_theme_only(): void {
		$GLOBALS['cosmic_test_options'] = array();

		CriticalCSS::onUpgrader( null, array( 'type' => 'plugin', 'action' => 'update' ) );
		$this->assertTrue( CriticalCSS::isStale() );

		CriticalCSS::clearStale();
		CriticalCSS::onUpgrader( null, array( 'type' => 'theme', 'action' => 'update' ) );
		$this->assertTrue( CriticalCSS::isStale() );

		CriticalCSS::clearStale();
		CriticalCSS::onUpgrader( null, array( 'type' => 'core', 'action' => 'update' ) );
		$this->assertFalse( CriticalCSS::isStale() );

		CriticalCSS::onUpgrader( null, 'not-an-array' );
		$this->assertFalse( CriticalCSS::isStale() );
	}

	public function test_criticalcss_record_meta_stores_hash_and_provenance(): void {
		$GLOBALS['cosmic_test_options'] = array();

		$this->assertSame( array(), CriticalCSS::meta() );

		CriticalCSS::recordMeta( 'body{color:#333}' );
		$meta = CriticalCSS::meta();

		$this->assertSame( sha1( 'body{color:#333}' ), $meta['hash'] );
		$this->assertSame( strlen( 'body{color:#333}' ), $meta['length'] );
		$this->assertArrayHasKey( 'time', $meta );
		$this->assertArrayHasKey( 'theme', $meta );
		$this->assertArrayHasKey( 'version', $meta );
	}

	public function test_criticalcss_record_meta_empty_snippet_clears_meta(): void {
		$GLOBALS['cosmic_test_options'] = array();

		CriticalCSS::recordMeta( 'body{color:#333}' );
		$this->assertNotSame( array(), CriticalCSS::meta() );

		CriticalCSS::recordMeta( '' );
		$this->assertSame( array(), CriticalCSS::meta() );

		CriticalCSS::recordMeta( "  \n\t " );
		$this->assertSame( array(), CriticalCSS::meta() );
	}
}
