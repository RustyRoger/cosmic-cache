<?php
/**
 * CssParser: minimal dependency-free CSS parser for the critical-CSS generator.
 *
 * Parses a stylesheet into style rules (selector list + declarations +
 * enclosing @media query, if any) plus the at-rules the generator needs:
 * @font-face blocks, @keyframes blocks (kept only when referenced by a kept
 * rule) and a count of @import statements (never followed — recorded as a
 * warning so the operator knows part of the CSS was not analyzed).
 *
 * This is a heuristic parser, not a spec-complete one: exotic syntax it does
 * not understand is skipped, never fatal. It runs in admin/CLI only, never
 * on the frontend hot path.
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache\Optimization;

final class CssParser {

	/**
	 * Parse a stylesheet.
	 *
	 * @param string $css Raw stylesheet content.
	 * @return array{rules:array<int,array{selectors:array<int,string>,declarations:string,media:string}>,font_faces:array<int,array{name:string,css:string}>,keyframes:array<int,array{name:string,css:string}>,imports:int}
	 */
	public static function parse( string $css ): array {
		$css = self::strip_comments( $css );

		$out = array(
			'rules'      => array(),
			'font_faces' => array(),
			'keyframes'  => array(),
			'imports'    => 0,
		);

		foreach ( self::top_blocks( $css ) as $block ) {
			self::collect( $block['prelude'], $block['body'], '', $out );
		}

		return $out;
	}

	/**
	 * Collect one top-level block into the result (recursing into @media and
	 * @supports so nested rules keep their media context).
	 *
	 * @param string $prelude Text before the opening brace.
	 * @param string $body Text between the braces.
	 * @param string $media Enclosing media query ('' when none).
	 * @param array<string,mixed> $out Result accumulator (by reference).
	 * @return void
	 */
	private static function collect( string $prelude, string $body, string $media, array &$out ): void {
		$prelude = trim( $prelude );
		if ( '' === $prelude ) {
			return;
		}

		$lower = strtolower( $prelude );

		if ( 0 === strpos( $lower, '@media' ) ) {
			$query = trim( substr( $prelude, 6 ) );
			foreach ( self::top_blocks( $body ) as $inner ) {
				self::collect( $inner['prelude'], $inner['body'], $query, $out );
			}
			return;
		}

		if ( 0 === strpos( $lower, '@supports' ) ) {
			// Conditional group: keep the rules with the outer media context
			// (conservative — the generator cannot evaluate support queries).
			foreach ( self::top_blocks( $body ) as $inner ) {
				self::collect( $inner['prelude'], $inner['body'], $media, $out );
			}
			return;
		}

		if ( 0 === strpos( $lower, '@font-face' ) ) {
			$out['font_faces'][] = array(
				'name' => self::font_name( $body ),
				'css'  => '@font-face{' . $body . '}',
			);
			return;
		}

		if ( 1 === preg_match( '/^@(?:-[a-z]+-)?keyframes\s+(.+)$/i', $prelude, $m ) ) {
			$out['keyframes'][] = array(
				'name' => trim( $m[1], "\"' \t\n\r\0\x0B" ),
				'css'  => $prelude . '{' . $body . '}',
			);
			return;
		}

		if ( 0 === strpos( $lower, '@import' ) || 0 === strpos( $lower, '@charset' ) || 0 === strpos( $lower, '@namespace' ) ) {
			if ( 0 === strpos( $lower, '@import' ) ) {
				++$out['imports'];
			}
			return;
		}

		if ( 0 === strpos( $prelude, '@' ) ) {
			// Unknown at-rule: skip (conservative callers keep the stylesheet
			// async, so nothing breaks — the rule is just not inlined).
			return;
		}

		$selectors = self::split_selectors( $prelude );
		if ( ! $selectors ) {
			return;
		}

		$out['rules'][] = array(
			'selectors'    => $selectors,
			'declarations' => trim( $body ),
			'media'        => $media,
		);
	}

	/**
	 * Split a stylesheet into top-level prelude/body blocks, tracking brace
	 * depth through quoted strings. At-rules without a block (@import ...;)
	 * are returned with an empty body.
	 *
	 * @param string $css Comment-free CSS.
	 * @return array<int,array{prelude:string,body:string}>
	 */
	private static function top_blocks( string $css ): array {
		$blocks  = array();
		$len     = strlen( $css );
		$start   = 0;
		$depth   = 0;
		$quote   = null;
		$prelude = '';

		for ( $i = 0; $i < $len; $i++ ) {
			$c = $css[ $i ];

			if ( null !== $quote ) {
				if ( '\\' === $c ) {
					++$i;
					continue;
				}
				if ( $c === $quote ) {
					$quote = null;
				}
				continue;
			}

			if ( '"' === $c || "'" === $c ) {
				$quote = $c;
				continue;
			}

			if ( '{' === $c ) {
				if ( 0 === $depth ) {
					$prelude = substr( $css, $start, $i - $start );
					$start   = $i + 1;
				}
				++$depth;
				continue;
			}

			if ( '}' === $c ) {
				if ( $depth > 0 ) {
					--$depth;
					if ( 0 === $depth ) {
						$blocks[] = array(
							'prelude' => $prelude,
							'body'    => substr( $css, $start, $i - $start ),
						);
						$start = $i + 1;
					}
				} else {
					// Stray closing brace: skip it so one broken rule cannot
					// swallow the rest of the stylesheet.
					$start = $i + 1;
				}
				continue;
			}

			if ( ';' === $c && 0 === $depth ) {
				$statement = trim( substr( $css, $start, $i - $start ) );
				$start     = $i + 1;
				if ( '' !== $statement ) {
					$blocks[] = array( 'prelude' => $statement, 'body' => '' );
				}
			}
		}

		return $blocks;
	}

	/**
	 * Split a selector list on top-level commas (ignoring commas inside
	 * functional pseudo-classes like :not(.a, .b)).
	 *
	 * @param string $prelude
	 * @return array<int,string>
	 */
	private static function split_selectors( string $prelude ): array {
		$out     = array();
		$current = '';
		$depth   = 0;
		$quote   = null;
		$len     = strlen( $prelude );

		for ( $i = 0; $i < $len; $i++ ) {
			$c = $prelude[ $i ];

			if ( null !== $quote ) {
				$current .= $c;
				if ( '\\' === $c ) {
					if ( $i + 1 < $len ) {
						$current .= $prelude[ ++$i ];
					}
					continue;
				}
				if ( $c === $quote ) {
					$quote = null;
				}
				continue;
			}

			if ( '"' === $c || "'" === $c ) {
				$quote    = $c;
				$current .= $c;
				continue;
			}

			if ( '(' === $c || '[' === $c ) {
				++$depth;
				$current .= $c;
				continue;
			}

			if ( ( ')' === $c || ']' === $c ) && $depth > 0 ) {
				--$depth;
				$current .= $c;
				continue;
			}

			if ( ',' === $c && 0 === $depth ) {
				$selector = trim( $current );
				if ( '' !== $selector ) {
					$out[] = $selector;
				}
				$current = '';
				continue;
			}

			$current .= $c;
		}

		$selector = trim( $current );
		if ( '' !== $selector ) {
			$out[] = $selector;
		}

		return $out;
	}

	/**
	 * Strip /* ... *\/ comments, ignoring comment markers inside strings.
	 *
	 * @param string $css
	 * @return string
	 */
	private static function strip_comments( string $css ): string {
		$out   = '';
		$len   = strlen( $css );
		$quote = null;

		for ( $i = 0; $i < $len; $i++ ) {
			$c = $css[ $i ];

			if ( null !== $quote ) {
				$out .= $c;
				if ( '\\' === $c && $i + 1 < $len ) {
					$out .= $css[ ++$i ];
					continue;
				}
				if ( $c === $quote ) {
					$quote = null;
				}
				continue;
			}

			if ( '"' === $c || "'" === $c ) {
				$quote = $c;
				$out  .= $c;
				continue;
			}

			if ( '/' === $c && $i + 1 < $len && '*' === $css[ $i + 1 ] ) {
				$end = strpos( $css, '*/', $i + 2 );
				if ( false === $end ) {
					break;
				}
				$i = $end + 1;
				continue;
			}

			$out .= $c;
		}

		return $out;
	}

	/**
	 * Extract the font-family name from an @font-face body.
	 *
	 * @param string $body
	 * @return string
	 */
	private static function font_name( string $body ): string {
		if ( 1 === preg_match( '/font-family\s*:\s*(["\']?)([^;"\']+)\1/i', $body, $m ) ) {
			return strtolower( trim( $m[2] ) );
		}
		return '';
	}
}
