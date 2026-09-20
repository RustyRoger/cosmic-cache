<?php
/**
 * CriticalGenerator: heuristic critical-CSS generator (admin/CLI only).
 *
 * No headless browser, no SaaS, no new dependencies: stylesheets linked by
 * the analyzed pages are parsed with CssParser and their selectors are
 * matched against the first DOM nodes of each page (header/nav/hero plus a
 * bounded prefix of <body>). Anything the heuristic cannot judge (pseudo
 * classes, attribute selectors, complex combinators, @supports) is KEPT, so
 * a generated snippet errs toward bloat, never toward broken paint.
 *
 * Output is always a draft for human review — this class never writes the
 * live `critical_css` setting (see CriticalCSS::applyDraft()). It never runs
 * on the frontend hot path: only the admin Generate button and
 * `wp cosmic critical-css` call it.
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache\Optimization;

use CosmicCache\Cache\Vary;

final class CriticalGenerator {

	use AssetFetch;

	/** Max total CSS bytes analyzed per run (abort past this). */
	public const MAX_CSS_BYTES = 1048576;

	/** Max wall-clock seconds per run (abort past this). */
	public const MAX_TIME = 20.0;

	/** Max URLs analyzed per run (extras are skipped with a warning). */
	public const MAX_URLS = 5;

	/** Inline size cap: drafts past this need explicit force to apply. */
	public const SIZE_CAP = 30720;

	/** DOM nodes (document order) considered above the fold per page. */
	public const ABOVE_FOLD_NODES = 300;

	/**
	 * Run the generator over a list of page URLs.
	 *
	 * A pluggable provider (`cosmic_cache_critical_provider` filter) may
	 * replace the heuristic entirely (e.g. headless Chromium on a VPS): when
	 * it returns an array with a `css` string, that result is used as-is.
	 *
	 * The $fetch_html/$fetch_css callables are extension points for tests and
	 * providers; production passes null (loopback HTTP + disk).
	 *
	 * @param array<int,string>   $urls Page URLs to analyze.
	 * @param array<string,mixed> $settings Plugin settings (excludes honored).
	 * @param callable|null       $fetch_html fn(string $url): ?string.
	 * @param callable|null       $fetch_css fn(string $url): ?string.
	 * @return array<string,mixed> Result (css/kept/dropped/bytes/total_bytes/urls/warnings/elapsed/over_cap) or ['error' => string].
	 */
	public function run( array $urls, array $settings, ?callable $fetch_html = null, ?callable $fetch_css = null ): array {
		$t0 = microtime( true );

		if ( function_exists( 'apply_filters' ) ) {
			$provided = apply_filters( 'cosmic_cache_critical_provider', null, $urls, $settings );
			if ( is_array( $provided ) && isset( $provided['css'] ) && is_string( $provided['css'] ) ) {
				return array_merge(
					array(
						'kept'        => 0,
						'dropped'     => 0,
						'bytes'       => strlen( $provided['css'] ),
						'total_bytes' => 0,
						'urls'        => array_values( $urls ),
						'warnings'    => array(),
						'over_cap'    => strlen( $provided['css'] ) > self::SIZE_CAP,
					),
					$provided
				);
			}
		}

		if ( ! class_exists( 'DOMDocument' ) ) {
			return array( 'error' => __( 'Critical CSS generation needs the PHP DOM extension (ext-dom), which is not available on this server.', 'cosmic-cache' ) );
		}

		$urls = array_values( array_filter( array_map( 'trim', $urls ) ) );
		if ( ! $urls ) {
			return array( 'error' => __( 'No URLs provided.', 'cosmic-cache' ) );
		}

		$warnings = array();
		if ( count( $urls ) > self::MAX_URLS ) {
			$warnings[] = sprintf(
				/* translators: %d: maximum number of URLs. */
				__( 'Only the first %d URLs were analyzed.', 'cosmic-cache' ),
				self::MAX_URLS
			);
			$urls = array_slice( $urls, 0, self::MAX_URLS );
		}

		$kept_rules  = array();
		$seen        = array();
		$kept        = 0;
		$dropped     = 0;
		$total_bytes = 0;
		$analyzed    = array();
		$font_faces  = array();
		$keyframes   = array();

		foreach ( $urls as $url ) {
			if ( microtime( true ) - $t0 > self::MAX_TIME ) {
				return array( 'error' => __( 'Critical CSS generation hit its time budget — no draft was saved. Try fewer URLs.', 'cosmic-cache' ) );
			}

			$html = $this->page_html( $url, $fetch_html );
			if ( null === $html || '' === trim( $html ) ) {
				$warnings[] = sprintf(
					/* translators: %s: page URL. */
					__( 'Skipped %s: page HTML could not be fetched.', 'cosmic-cache' ),
					$url
				);
				continue;
			}

			$index = $this->fold_index( $html );
			if ( ! $index ) {
				$warnings[] = sprintf(
					/* translators: %s: page URL. */
					__( 'Skipped %s: no DOM elements found.', 'cosmic-cache' ),
					$url
				);
				continue;
			}

			$analyzed[] = $url;

			foreach ( $this->page_stylesheets( $html, $settings ) as $sheet ) {
				if ( microtime( true ) - $t0 > self::MAX_TIME ) {
					return array( 'error' => __( 'Critical CSS generation hit its time budget — no draft was saved. Try fewer URLs.', 'cosmic-cache' ) );
				}

				if ( 'remote' === $sheet['kind'] ) {
					$warnings[] = sprintf(
						/* translators: %s: stylesheet URL. */
						__( 'Skipped remote stylesheet %s: only local files are analyzed.', 'cosmic-cache' ),
						$sheet['url']
					);
					continue;
				}

				$css = null !== $fetch_css ? $fetch_css( $sheet['url'] ) : $this->fetch( $sheet['url'] );
				if ( null === $css || '' === trim( $css ) ) {
					$warnings[] = sprintf(
						/* translators: %s: stylesheet URL. */
						__( 'Skipped stylesheet %s: content could not be read.', 'cosmic-cache' ),
						$sheet['url']
					);
					continue;
				}

				$total_bytes += strlen( $css );
				if ( $total_bytes > self::MAX_CSS_BYTES ) {
					return array( 'error' => __( 'Critical CSS generation hit its size budget — no draft was saved. Exclude heavy stylesheets and retry.', 'cosmic-cache' ) );
				}

				$parsed = CssParser::parse( $css );

				if ( $parsed['imports'] > 0 ) {
					$warnings[] = sprintf(
						/* translators: 1: stylesheet URL, 2: number of @import statements. */
						__( 'Stylesheet %1$s uses %2$d @import statement(s), which are not followed — those rules are missing from the draft.', 'cosmic-cache' ),
						$sheet['url'],
						$parsed['imports']
					);
				}

				foreach ( $parsed['font_faces'] as $face ) {
					$font_faces[ $face['name'] ] = $face['css'];
				}
				foreach ( $parsed['keyframes'] as $frame ) {
					$keyframes[ $frame['name'] ] = $frame['css'];
				}

				foreach ( $parsed['rules'] as $rule ) {
					if ( $this->is_print_only( $rule['media'] ) ) {
						++$dropped;
						continue;
					}
					if ( $this->rule_matches( $rule['selectors'], $index ) ) {
						$key = sha1( $rule['media'] . "\n" . implode( ',', $rule['selectors'] ) . "\n" . $rule['declarations'] );
						if ( ! isset( $seen[ $key ] ) ) {
							$seen[ $key ]   = true;
							$kept_rules[]   = $rule;
							++$kept;
						}
					} else {
						++$dropped;
					}
				}
			}
		}

		if ( ! $analyzed ) {
			return array( 'error' => __( 'No page could be analyzed — no draft was saved.', 'cosmic-cache' ) );
		}

		$css = $this->serialize( $kept_rules, $font_faces, $keyframes );

		return array(
			'css'         => $css,
			'kept'        => $kept,
			'dropped'     => $dropped,
			'bytes'       => strlen( $css ),
			'total_bytes' => $total_bytes,
			'urls'        => $analyzed,
			'warnings'    => array_values( array_unique( $warnings ) ),
			'elapsed'     => round( microtime( true ) - $t0, 3 ),
			'over_cap'    => strlen( $css ) > self::SIZE_CAP,
		);
	}

	/**
	 * Fetch page HTML: loopback HTTP first, cached file on disk as fallback.
	 *
	 * @param string        $url
	 * @param callable|null $fetch_html
	 * @return string|null
	 */
	private function page_html( string $url, ?callable $fetch_html ): ?string {
		if ( null !== $fetch_html ) {
			return $fetch_html( $url );
		}

		if ( function_exists( 'wp_remote_get' ) ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'   => 8,
					'sslverify' => false,
				)
			);
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$body = (string) wp_remote_retrieve_body( $response );
				if ( '' !== trim( $body ) ) {
					return $body;
				}
			}
		}

		return $this->cached_html( $url );
	}

	/**
	 * Read the stored cache file for a URL (loopback-blocked hosts fallback).
	 *
	 * Builds the same minimal anonymous env the hot path would hash, so the
	 * resolved file is the plain (non-vary) variant when the page has one.
	 *
	 * @param string $url
	 * @return string|null
	 */
	private function cached_html( string $url ): ?string {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? 'http' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return null;
		}

		$host = Vary::sanitizeHost( (string) $parts['host'] );
		if ( '' === $host ) {
			return null;
		}

		$settings = function_exists( 'get_option' ) ? \CosmicCache\Config::load() : array();
		$env      = array(
			'scheme'  => $scheme,
			'host'    => $host,
			'path'    => '/' . ltrim( (string) ( $parts['path'] ?? '/' ), '/' ),
			'method'  => 'GET',
			'ua'      => '',
			'query'   => array(),
			'cookies' => array(),
		);

		$file = Vary::resolveFile( $settings, $env, Vary::blogId( $settings, $host ) );
		if ( '' === $file || ! is_file( $file ) ) {
			return null;
		}

		$html = file_get_contents( $file );
		return ( false === $html || '' === trim( $html ) ) ? null : $html;
	}

	/**
	 * Stylesheet links of a page: local vs remote, honoring the critical and
	 * CSS exclude lists. Print stylesheets are skipped (never inlined).
	 *
	 * @param string              $html
	 * @param array<string,mixed> $settings
	 * @return array<int,array{url:string,kind:string}> kind is local|remote.
	 */
	private function page_stylesheets( string $html, array $settings ): array {
		$out = array();
		if ( ! preg_match_all( '#<link\b[^>]*>#i', $html, $m ) ) {
			return $out;
		}

		$excluded = array_merge(
			(array) ( $settings['critical_css_exclude'] ?? array() ),
			(array) ( $settings['exclude_css'] ?? array() )
		);

		foreach ( $m[0] as $tag ) {
			if ( ! $this->is_stylesheet( $tag ) ) {
				continue;
			}
			$url = html_entity_decode( $this->attr( $tag, 'href' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( '' === $url ) {
				continue;
			}
			$media = $this->attr( $tag, 'media' );
			if ( '' !== $media && 'all' !== strtolower( trim( $media ) ) && false !== stripos( $media, 'print' ) ) {
				continue;
			}
			foreach ( $excluded as $pattern ) {
				if ( \CosmicCache\Cache\Bypass::matches( (string) $pattern, $tag )
					|| \CosmicCache\Cache\Bypass::matches( (string) $pattern, $url ) ) {
					continue 2;
				}
			}
			$path = (string) parse_url( $url, PHP_URL_PATH );
			if ( 'css' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
				continue;
			}
			if ( false !== strpos( $url, 'combined' ) ) {
				continue;
			}
			$out[] = array(
				'url'  => $url,
				'kind' => $this->is_local( $url ) ? 'local' : 'remote',
			);
		}

		return $out;
	}

	/**
	 * Build the above-the-fold element index for a page.
	 *
	 * Header/nav subtrees (anywhere in the document) plus a bounded prefix of
	 * <body> in document order. Returns tag/id/class lookup tables.
	 *
	 * @param string $html
	 * @return array{by_id:array<string,array{tag:string,id:string,classes:array<int,string>}>,by_class:array<string,array<int,array{tag:string,id:string,classes:array<int,string>}>>,by_tag:array<string,array<int,array{tag:string,id:string,classes:array<int,string>}>>}|array{}
	 */
	private function fold_index( string $html ): array {
		libxml_use_internal_errors( true );
		$doc = new \DOMDocument();
		$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();

		$xpath = new \DOMXPath( $doc );
		$nodes = $xpath->query( '//header//* | //nav//* | //body//*' );
		if ( ! $nodes || 0 === $nodes->length ) {
			return array();
		}

		$index = array( 'by_id' => array(), 'by_class' => array(), 'by_tag' => array() );
		$count = 0;

		foreach ( $nodes as $node ) {
			if ( $count >= self::ABOVE_FOLD_NODES ) {
				break;
			}
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			++$count;

			$record = array(
				'tag'     => strtolower( $node->nodeName ),
				'id'      => $node->hasAttribute( 'id' ) ? (string) $node->getAttribute( 'id' ) : '',
				'classes' => $node->hasAttribute( 'class' )
					? array_values( array_filter( preg_split( '/\s+/', (string) $node->getAttribute( 'class' ) ) ?: array() ) )
					: array(),
			);

			if ( '' !== $record['id'] ) {
				$index['by_id'][ $record['id'] ] = $record;
			}
			foreach ( $record['classes'] as $class ) {
				$index['by_class'][ $class ][] = $record;
			}
			$index['by_tag'][ $record['tag'] ][] = $record;
		}

		return $index;
	}

	/**
	 * Does any selector of a rule match the above-the-fold index?
	 *
	 * Only the rightmost compound selector is tested (heuristic); anything
	 * the matcher cannot judge (pseudo classes/elements, attribute selectors)
	 * is KEPT. Base structural selectors are always kept.
	 *
	 * @param array<int,string> $selectors
	 * @param array<string,mixed> $index
	 * @return bool
	 */
	private function rule_matches( array $selectors, array $index ): bool {
		foreach ( $selectors as $selector ) {
			if ( $this->selector_matches( trim( $selector ), $index ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $selector
	 * @param array<string,mixed> $index
	 * @return bool
	 */
	private function selector_matches( string $selector, array $index ): bool {
		if ( '' === $selector ) {
			return false;
		}

		$parts = self::split_compounds( $selector );
		if ( ! $parts ) {
			return true;
		}
		$right = end( $parts );

		// Pseudos and attribute selectors: cannot judge — keep.
		if ( false !== strpos( $right, ':' ) || false !== strpos( $right, '[' ) ) {
			return true;
		}

		if ( ! preg_match( '/^(?P<tag>\*|[a-zA-Z][a-zA-Z0-9_-]*)?(?P<rest>[#.].*)?$/', $right, $m ) ) {
			return true;
		}

		$tag = $m['tag'] ?? '';
		$rest = $m['rest'] ?? '';

		if ( 1 === preg_match_all( '/#([a-zA-Z0-9_-]+)/', $rest, $ids ) && $ids[1] ) {
			$id = $ids[1][0];
			if ( ! isset( $index['by_id'][ $id ] ) ) {
				return false;
			}
			return $this->record_matches( $index['by_id'][ $id ], $tag, '', $this->compound_classes( $rest ) );
		}

		$classes = $this->compound_classes( $rest );

		if ( $classes ) {
			// Scan the smallest class list: a matching element must carry
			// every class of the compound (verified per candidate below).
			$smallest = null;
			foreach ( $classes as $class ) {
				if ( ! isset( $index['by_class'][ $class ] ) ) {
					return false;
				}
				if ( null === $smallest || count( $index['by_class'][ $class ] ) < count( $smallest ) ) {
					$smallest = $index['by_class'][ $class ];
				}
			}
			foreach ( $smallest as $candidate ) {
				if ( $this->record_matches( $candidate, $tag, '', $classes ) ) {
					return true;
				}
			}
			return false;
		}

		if ( '' === $tag || '*' === $tag ) {
			return true;
		}

		if ( $this->is_base_tag( $tag ) || $this->is_base_class_selector( $right ) ) {
			return true;
		}

		return isset( $index['by_tag'][ strtolower( $tag ) ] );
	}

	/**
	 * Split a selector into compounds on combinators, ignoring combinator
	 * characters inside brackets/parens (attribute values).
	 *
	 * @param string $selector
	 * @return array<int,string>
	 */
	private static function split_compounds( string $selector ): array {
		$parts   = array();
		$current = '';
		$depth   = 0;
		$len     = strlen( $selector );

		for ( $i = 0; $i < $len; $i++ ) {
			$c = $selector[ $i ];

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

			if ( 0 === $depth && ( '>' === $c || '+' === $c || '~' === $c || ' ' === $c || "\t" === $c || "\n" === $c ) ) {
				$compound = trim( $current );
				if ( '' !== $compound ) {
					$parts[] = $compound;
				}
				$current = '';
				continue;
			}

			$current .= $c;
		}

		$compound = trim( $current );
		if ( '' !== $compound ) {
			$parts[] = $compound;
		}

		return $parts;
	}

	/**
	 * @param string $rest Compound remainder after the tag (e.g. ".a.b#i").
	 * @return array<int,string>
	 */
	private function compound_classes( string $rest ): array {
		if ( 1 !== preg_match_all( '/\.([a-zA-Z0-9_-]+)/', $rest, $m ) ) {
			return array();
		}
		return array_values( array_unique( $m[1] ) );
	}

	/**
	 * @param array{tag:string,id:string,classes:array<int,string>} $record
	 * @param string $tag
	 * @param string $id
	 * @param array<int,string> $classes
	 * @return bool
	 */
	private function record_matches( array $record, string $tag, string $id, array $classes ): bool {
		if ( '' !== $tag && '*' !== $tag && strtolower( $tag ) !== $record['tag'] ) {
			return false;
		}
		if ( '' !== $id && $id !== $record['id'] ) {
			return false;
		}
		foreach ( $classes as $class ) {
			if ( ! in_array( $class, $record['classes'], true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Structural tags always worth inlining (tiny, page-wide).
	 *
	 * @param string $tag
	 * @return bool
	 */
	private function is_base_tag( string $tag ): bool {
		static $base = array( 'html', 'body', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'a', 'ul', 'ol', 'li', 'img', 'header', 'nav', 'main' );
		return in_array( strtolower( $tag ), $base, true );
	}

	/**
	 * Layout-utility classes always worth inlining.
	 *
	 * @param string $compound Rightmost compound selector.
	 * @return bool
	 */
	private function is_base_class_selector( string $compound ): bool {
		if ( 1 !== preg_match( '/^\.([a-zA-Z0-9_-]+)$/', trim( $compound ), $m ) ) {
			return false;
		}
		$class = strtolower( $m[1] );
		return 'container' === $class
			|| 'row' === $class
			|| false !== strpos( $class, 'grid' )
			|| 1 === preg_match( '/^col(-|$)/', $class );
	}

	/**
	 * Print-only media query (mirrors CriticalCSS::skip()).
	 *
	 * @param string $media
	 * @return bool
	 */
	private function is_print_only( string $media ): bool {
		if ( '' === trim( $media ) ) {
			return false;
		}
		$media = strtolower( $media );
		return false !== strpos( $media, 'print' ) && false === strpos( $media, 'screen' );
	}

	/**
	 * Serialize kept rules (grouped by media, original order) plus referenced
	 * @font-face/@keyframes blocks.
	 *
	 * @param array<int,array{selectors:array<int,string>,declarations:string,media:string}> $rules
	 * @param array<string,string> $font_faces name => css.
	 * @param array<string,string> $keyframes name => css.
	 * @return string
	 */
	private function serialize( array $rules, array $font_faces, array $keyframes ): string {
		$groups = array();
		$order  = array();

		foreach ( $rules as $rule ) {
			$media = $rule['media'];
			if ( ! isset( $groups[ $media ] ) ) {
				$groups[ $media ] = array();
				$order[]          = $media;
			}
			$groups[ $media ][] = implode( ',', $rule['selectors'] ) . '{' . $rule['declarations'] . '}';
		}

		$css = '';
		foreach ( $order as $media ) {
			$block = implode( '', $groups[ $media ] );
			$css  .= '' === $media ? $block : '@media ' . $media . '{' . $block . '}';
		}

		if ( $font_faces || $keyframes ) {
			$lower = strtolower( $css );
			foreach ( $font_faces as $name => $block ) {
				if ( '' !== $name && false !== strpos( $lower, $name ) ) {
					$css .= $block;
				}
			}
			foreach ( $keyframes as $name => $block ) {
				if ( '' !== $name && false !== strpos( $lower, $name ) ) {
					$css .= $block;
				}
			}
		}

		return $css;
	}

	/**
	 * Does this <link> tag reference a stylesheet?
	 *
	 * @param string $tag
	 * @return bool
	 */
	private function is_stylesheet( string $tag ): bool {
		if ( preg_match( '/\brel\s*=\s*(["\'])(.*?)\1/i', $tag, $m ) ) {
			return false !== stripos( $m[2], 'stylesheet' );
		}
		return false;
	}

	/**
	 * Extract an attribute value.
	 *
	 * @param string $tag
	 * @param string $name
	 * @return string
	 */
	private function attr( string $tag, string $name ): string {
		if ( preg_match( '/\b' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/i', $tag, $m ) ) {
			return $m[2];
		}
		return '';
	}
}
