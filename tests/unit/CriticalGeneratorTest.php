<?php
/**
 * CssParser + CriticalGenerator unit tests.
 *
 * The generator is exercised with injected HTML/CSS fetchers (no network):
 * production passes null and uses loopback HTTP + disk.
 *
 * @package cosmic-cache
 */

namespace CosmicCache\Tests;

use CosmicCache\Config;
use CosmicCache\Optimization\CriticalCSS;
use CosmicCache\Optimization\CriticalGenerator;
use CosmicCache\Optimization\CssParser;
use PHPUnit\Framework\TestCase;

class CriticalGeneratorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cosmic_test_options'] = array();
		if ( function_exists( 'GLOBALS_filters_clear' ) ) {
			GLOBALS_filters_clear();
		}
	}

	private function html(): string {
		$fillers = str_repeat( '<p class="filler">x</p>', 400 );
		return '<!DOCTYPE html><html><head>'
			. '<link rel="stylesheet" href="https://example.com/wp-content/themes/t/style.css">'
			. '<link rel="stylesheet" href="https://fonts.example.com/css/fonts.css">'
			. '<link rel="stylesheet" href="https://example.com/wp-content/themes/t/print.css" media="print">'
			. '</head><body>'
			. '<header class="site-header"><nav id="main-nav"><a href="/">Home</a></nav></header>'
			. '<main><section class="hero"><h1>Title</h1><p class="lead">hi</p></section></main>'
			. $fillers
			. '<footer><p class="colophon">bye</p></footer>'
			. '</body></html>';
	}

	private function css(): string {
		return "/* theme */\n"
			. "body{margin:0}\n"
			. ".site-header{background:#fff}\n"
			. "#main-nav a{color:blue}\n"
			. ".hero h1{font-size:2em}\n"
			. ".lead{color:#333}\n"
			. ".colophon{color:#999}\n"
			. "a:hover{color:red}\n"
			. "@media screen and (min-width:800px){.hero{padding:2em}}\n"
			. "@media print{.hero{color:#000}}\n"
			. "@font-face{font-family:'Brand';src:url(brand.woff2)}\n"
			. "h1{font-family:'Brand',sans-serif}\n"
			. "@keyframes fade{from{opacity:0}to{opacity:1}}\n"
			. ".hero{animation:fade 1s}\n";
	}

	private function settings(): array {
		return array(
			'critical_css_exclude' => array(),
			'exclude_css'          => array(),
		);
	}

	// --- CssParser ---

	public function test_parser_rules_media_and_at_rules(): void {
		$parsed = CssParser::parse( $this->css() );

		$this->assertSame( 0, $parsed['imports'] );
		$this->assertCount( 0, array_filter( $parsed['rules'], static function ( $r ) {
			return '' !== $r['media'] && false === strpos( $r['media'], 'screen' ) && false === strpos( $r['media'], 'print' );
		} ) );

		$medias = array_unique( array_column( $parsed['rules'], 'media' ) );
		$this->assertContains( '', $medias );
		$this->assertContains( 'screen and (min-width:800px)', $medias );
		$this->assertContains( 'print', $medias );

		$this->assertSame( 'brand', $parsed['font_faces'][0]['name'] );
		$this->assertSame( 'fade', $parsed['keyframes'][0]['name'] );
	}

	public function test_parser_imports_and_stray_brace(): void {
		$parsed = CssParser::parse( "@import url(a.css);\n}\n.lead{color:#333}\n" );
		$this->assertSame( 1, $parsed['imports'] );
		$this->assertCount( 1, $parsed['rules'] );
		$this->assertSame( array( '.lead' ), $parsed['rules'][0]['selectors'] );
	}

	public function test_parser_comma_in_functional_pseudo(): void {
		$parsed = CssParser::parse( ":not(.a, .b){color:red}\n" );
		$this->assertCount( 1, $parsed['rules'] );
		$this->assertSame( array( ':not(.a, .b)' ), $parsed['rules'][0]['selectors'] );
	}

	// --- CriticalGenerator ---

	public function test_run_keeps_fold_and_drops_below_and_print(): void {
		$gen = new CriticalGenerator();
		$result = $gen->run(
			array( 'https://example.com/' ),
			$this->settings(),
			function () {
				return $this->html();
			},
			function () {
				return $this->css();
			}
		);

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertStringContainsString( 'body{margin:0}', $result['css'] );
		$this->assertStringContainsString( '.site-header{background:#fff}', $result['css'] );
		$this->assertStringContainsString( '.lead{color:#333}', $result['css'] );
		$this->assertStringContainsString( 'a:hover', $result['css'] );
		$this->assertStringContainsString( '@media screen and (min-width:800px)', $result['css'] );
		$this->assertStringContainsString( "@font-face{font-family:'Brand'", $result['css'] );
		$this->assertStringContainsString( '@keyframes fade', $result['css'] );

		// Below-the-fold-only + print-only rules are dropped.
		$this->assertStringNotContainsString( '.colophon', $result['css'] );
		$this->assertStringNotContainsString( '@media print', $result['css'] );

		$this->assertGreaterThan( 0, $result['kept'] );
		$this->assertGreaterThanOrEqual( 2, $result['dropped'] );
		$this->assertSame( array( 'https://example.com/' ), $result['urls'] );
		$this->assertFalse( $result['over_cap'] );

		// Remote stylesheet produces a warning, not a failure.
		$this->assertNotEmpty( $result['warnings'] );
	}

	public function test_run_empty_urls_errors(): void {
		$gen = new CriticalGenerator();
		$result = $gen->run( array(), $this->settings() );
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_run_unfetchable_page_errors(): void {
		$gen = new CriticalGenerator();
		$result = $gen->run(
			array( 'https://example.com/' ),
			$this->settings(),
			function () {
				return null;
			}
		);
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_run_truncates_urls_with_warning(): void {
		$gen = new CriticalGenerator();
		$urls = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$urls[] = 'https://example.com/page-' . $i . '/';
		}
		$result = $gen->run(
			$urls,
			$this->settings(),
			function () {
				return $this->html();
			},
			function () {
				return $this->css();
			}
		);

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertCount( CriticalGenerator::MAX_URLS, $result['urls'] );
		$this->assertNotEmpty( $result['warnings'] );
	}

	public function test_run_provider_filter_overrides(): void {
		add_filter(
			'cosmic_cache_critical_provider',
			static function () {
				return array( 'css' => 'a{color:red}' );
			}
		);

		$gen = new CriticalGenerator();
		$result = $gen->run( array( 'https://example.com/' ), $this->settings() );

		$this->assertSame( 'a{color:red}', $result['css'] );
		$this->assertArrayNotHasKey( 'error', $result );
	}

	// --- Draft / apply / revert ---

	public function test_draft_apply_revert_roundtrip(): void {
		CriticalCSS::saveDraft( array(
			'css'     => 'body{color:#333}',
			'kept'    => 3,
			'dropped' => 1,
		) );
		$draft = CriticalCSS::draft();
		$this->assertSame( 'body{color:#333}', $draft['css'] );

		$this->assertTrue( CriticalCSS::applyDraft( Config::defaults() ) );
		$this->assertSame( array(), CriticalCSS::draft() );

		$stored = get_option( Config::OPTION_KEY );
		$this->assertSame( 'body{color:#333}', $stored['critical_css'] );
		$this->assertFalse( CriticalCSS::isStale() );

		$this->assertTrue( CriticalCSS::canRevert() );
		$this->assertTrue( CriticalCSS::revert() );
		$stored = get_option( Config::OPTION_KEY );
		$this->assertSame( '', $stored['critical_css'] );
	}

	public function test_apply_empty_draft_fails(): void {
		CriticalCSS::discardDraft();
		$this->assertFalse( CriticalCSS::applyDraft( Config::defaults() ) );
		$this->assertFalse( CriticalCSS::revert() );
		$this->assertFalse( CriticalCSS::canRevert() );
	}
}
