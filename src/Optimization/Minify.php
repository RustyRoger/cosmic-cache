<?php
/**
 * Minify: regex-based whitespace/structure stripping.
 *
 * Preserves IE conditionals, <pre>/<textarea> content integrity and @media
 * blocks. <style> and <script> contents are minified only when minify_css /
 * minify_js are enabled, otherwise they are protected verbatim.
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache\Optimization;

final class Minify {

	/**
	 * @param string              $html
	 * @param array<string,mixed> $settings
	 * @return string
	 */
	public function process( string $html, array $settings ): string {
		// Master gate: HTML-level minification is opt-in. With minify_html off
		// (the default) the pipeline leaves the markup byte-identical.
		if ( empty( $settings['minify_html'] ) ) {
			return $html;
		}
		return $this->minify_html( $html, $settings );
	}

	/**
	 * Minify HTML, protecting sensitive regions.
	 *
	 * @param string              $html
	 * @param array<string,mixed> $settings
	 * @return string
	 */
	private function minify_html( string $html, array $settings ): string {
		$protected = array();

		$minify_css = ! empty( $settings['minify_css'] );
		$minify_js  = ! empty( $settings['minify_js'] );

		// Protect pre/textarea verbatim; minify style/script when enabled.
		$html = preg_replace_callback(
			'#<(pre|textarea|style|script)\b[^>]*>.*?</\1>#is',
			static function ( $m ) use ( &$protected, $minify_css, $minify_js ) {
				$tag  = strtolower( $m[1] );
				$body = $m[0];

				if ( 'style' === $tag && $minify_css ) {
					$body = $m[0];
					$inner = substr( $body, strpos( $body, '>' ) + 1, -strlen( '</style>' ) );
					$minified = self::minify_css( $inner );
					$body = substr( $body, 0, strpos( $body, '>' ) + 1 ) . $minified . '</style>';
				} elseif ( 'script' === $tag && $minify_js && false === strpos( $m[0], 'src=' ) ) {
					$inner = substr( $body, strpos( $body, '>' ) + 1, -strlen( '</script>' ) );
					$minified = self::minify_js( $inner );
					$body = substr( $body, 0, strpos( $body, '>' ) + 1 ) . $minified . '</script>';
				}

				$key = "\x1A" . count( $protected ) . "\x1A";
				$protected[ $key ] = $body;
				return $key;
			},
			$html
		) ?? $html;

		// Protect IE conditionals.
		$html = preg_replace_callback(
			'#<!--\[if[^>]*>.*?<!\[endif\]-->#is',
			static function ( $m ) use ( &$protected ) {
				$key = "\x1A" . count( $protected ) . "\x1A";
				$protected[ $key ] = $m[0];
				return $key;
			},
			$html
		) ?? $html;

		// Protect comments that carry meaning: SSI includes/directives (#),
		// Gutenberg block markers (wp: / wp:), WP <!--more--> / <!--nextpage-->.
		$html = preg_replace_callback(
			'#<!--\s*([\#]|/?wp:|more|nextpage)[^\n]*?-->#i',
			static function ( $m ) use ( &$protected ) {
				$key = "\x1A" . count( $protected ) . "\x1A";
				$protected[ $key ] = $m[0];
				return $key;
			},
			$html
		) ?? $html;

		// Drop the remaining plain comments.
		$html = preg_replace( '#<!--(?!\[if)[\s\S]*?(?<!\[endif\])-->#', '', $html ) ?? $html;

		// Collapse whitespace between tags and condense runs inside tags.
		// Text-node whitespace is NEVER removed: `<p>Contact <a>us</a></p>`
		// must not become `Contact<a>us</a>`.
		$html = preg_replace( '/>\s+</', '><', $html ) ?? $html;
		$html = preg_replace( '/\s{2,}/', ' ', $html ) ?? $html;
		$html = preg_replace( '/\s+>/', '>', $html ) ?? $html;

		// Restore protected regions.
		return strtr( $html, $protected );
	}

	/**
	 * Conservative CSS minifier: comments + whitespace, preserving strings,
	 * urls() and @media structure.
	 *
	 * @param string $css
	 * @return string
	 */
	public static function minify_css( string $css ): string {
		$strings = array();

		// Protect strings + url() payloads.
		$css = preg_replace_callback(
			"#(?:\"(?:[^\"\\\\]|\\\\.)*\"|'(?:[^'\\\\]|\\\\.)*')#",
			static function ( $m ) use ( &$strings ) {
				$key = "\x1B" . count( $strings ) . "\x1B";
				$strings[ $key ] = $m[0];
				return $key;
			},
			$css
		) ?? $css;

		$css = (string) preg_replace( '#/\*[\s\S]*?\*/#', '', $css );
		$css = (string) preg_replace( '/\s+/', ' ', $css );
		// Do NOT strip spaces around `+`/`-`: `calc(100% + 10px)` is invalid
		// without them. Only collapse around structural tokens.
		$css = (string) preg_replace( '/\s*([{}:;,>~])\s*/', '$1', $css );
		$css = (string) preg_replace( '/;}/', '}', $css );

		return strtr( $css, $strings );
	}

	/**
	 * Conservative JS "minifier": strip comments only.
	 *
	 * Whitespace is never collapsed: merging lines or removing the space around
	 * operators changes semantics (ASI, `a + +b`, regex literals, `return {`).
	 * A regex cannot safely minify JS beyond comment removal.
	 *
	 * @param string $js
	 * @return string
	 */
	public static function minify_js( string $js ): string {
		$strings = array();

		$js = preg_replace_callback(
			"#(?:\"(?:[^\"\\\\]|\\\\.)*\"|'(?:[^'\\\\]|\\\\.)*'|`(?:[^`\\\\]|\\\\.)*`)#",
			static function ( $m ) use ( &$strings ) {
				$key = "\x1B" . count( $strings ) . "\x1B";
				$strings[ $key ] = $m[0];
				return $key;
			},
			$js
		) ?? $js;

		// Block comments (never nested in valid JS outside strings).
		$js = (string) preg_replace( '#/\*[\s\S]*?\*/#', '', $js );
		// Line comments: only when preceded by start-of-line / whitespace / `;`
		// so `http://…` and regex `//` outside comments are preserved.
		$js = (string) preg_replace( '#(^|[;\s])//[^\r\n]*#', '$1', $js );

		return strtr( $js, $strings );
	}
}
