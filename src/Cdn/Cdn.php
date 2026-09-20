<?php
/**
 * CDN rewrite: rewrite local asset URLs to the configured CDN host.
 *
 * Supports absolute, scheme-relative and `//` CDN URLs; when the CDN URL is
 * scheme-relative the host keeps the original scheme. Skips cdn_exclude paths
 * and extensions, inline styles and application/ld+json blocks.
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache\Cdn;

final class Cdn {

	/**
	 * @param string              $html
	 * @param array<string,mixed> $settings
	 * @return string
	 */
	public function process( string $html, array $settings ): string {
		$cdn = trim( (string) ( $settings['cdn_url'] ?? '' ) );
		if ( '' === $cdn ) {
			return $html;
		}

		$local = $this->local_host();
		if ( '' === $local ) {
			return $html;
		}

		$cdn_host = $this->cdn_host( $cdn );
		if ( '' === $cdn_host ) {
			return $html;
		}

		$excluded = (array) ( $settings['cdn_exclude'] ?? array() );

		// Protect ld+json blocks from rewriting.
		$protected = array();
		$html = preg_replace_callback(
			'#<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>.*?</script>#is',
			static function ( $m ) use ( &$protected ) {
				$key = "\x1C" . count( $protected ) . "\x1C";
				$protected[ $key ] = $m[0];
				return $key;
			},
			$html
		) ?? $html;

		// Rewrite src/href asset attributes. Page-level <link href> values
		// (canonical, next/prev, RSS, wp-json) are NOT assets and must never be
		// pointed at the CDN; only asset rels (stylesheet, icon, preload…) are.
		$html = (string) preg_replace_callback(
			'#<(link|script|img)\b([^>]*)\b(src|href)\s*=\s*(["\'])(.*?)\4#i',
			static function ( $m ) use ( $local, $cdn_host, $excluded ) {
				$tag   = strtolower( $m[1] );
				$attrs = $m[2];
				$quote = $m[4];
				$value = $m[5];

				if ( 'link' === $tag && ! self::is_asset_link( $attrs ) ) {
					return $m[0];
				}

				$rewritten = self::rewrite_value( $value, $local, $cdn_host );

				if ( $value === $rewritten ) {
					return $m[0];
				}

				// Per-asset opt-out.
				if ( has_filter( 'cosmic_cache_cdn_rewrite' ) && ! apply_filters( 'cosmic_cache_cdn_rewrite', true, $value, $m[1] ) ) {
					return $m[0];
				}

				// Exclusions.
				foreach ( $excluded as $pattern ) {
					if ( \CosmicCache\Cache\Bypass::matches( (string) $pattern, $value ) ) {
						return $m[0];
					}
				}

				return str_replace( $quote . $value . $quote, $quote . $rewritten . $quote, $m[0] );
			},
			$html
		);

		// Rewrite srcset URL lists (with their descriptors) on img/source.
		$html = (string) preg_replace_callback(
			'#<(img|source)\b[^>]*?\bsrcset\s*=\s*(["\'])(.*?)\2#i',
			static function ( $m ) use ( $local, $cdn_host, $excluded ) {
				$urls   = array();
				$output = array();
				$changed = false;

				foreach ( preg_split( '/\s*,\s*/', $m[3] ) as $part ) {
					if ( '' === $part ) {
						$output[] = '';
						continue;
					}
					if ( ! preg_match( '/^(\S+)(\s+.*)?$/', $part, $p ) ) {
						$output[] = $part;
						continue;
					}

					$skip = false;
					foreach ( $excluded as $pattern ) {
						if ( \CosmicCache\Cache\Bypass::matches( (string) $pattern, $p[1] ) ) {
							$skip = true;
							break;
						}
					}
					if ( ! $skip && has_filter( 'cosmic_cache_cdn_rewrite' )
						&& ! apply_filters( 'cosmic_cache_cdn_rewrite', true, $p[1], $m[1] ) ) {
						$skip = true;
					}

					if ( $skip ) {
						$output[] = $part;
						continue;
					}

					$candidate = self::rewrite_value( $p[1], $local, $cdn_host );
					$output[]  = $candidate . ( isset( $p[2] ) ? $p[2] : '' );
					if ( $candidate !== $p[1] ) {
						$changed = true;
					}
				}

				$urls = implode( ', ', $output );
				if ( ! $changed || $urls === $m[3] ) {
					return $m[0];
				}

				return substr( $m[0], 0, -( strlen( $m[3] ) + strlen( $m[2] ) ) ) . $urls . $m[2];
			},
			$html
		);

		return strtr( $html, $protected );
	}

	/**
	 * Is a <link> tag an asset link (stylesheet/icon/preload/prefetch/
	 * dns-prefetch/preconnect)? Page links (canonical, next/prev, RSS,
	 * wp-json…) must keep the origin URL.
	 *
	 * @param string $attrs
	 * @return bool
	 */
	private static function is_asset_link( string $attrs ): bool {
		if ( preg_match( '/\brel\s*=\s*(["\'])(.*?)\1/i', $attrs, $m ) ) {
			$rels = strtolower( $m[2] );
			if ( false !== strpos( $rels, 'stylesheet' )
				|| false !== strpos( $rels, 'icon' )
				|| false !== strpos( $rels, 'preload' )
				|| false !== strpos( $rels, 'prefetch' )
				|| false !== strpos( $rels, 'modulepreload' )
				|| false !== strpos( $rels, 'manifest' )
				|| false !== strpos( $rels, 'dns-prefetch' )
				|| false !== strpos( $rels, 'preconnect' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rewrite a single asset URL value to the CDN host.
	 *
	 * @param string $value
	 * @param string $local Local origin, e.g. https://example.com.
	 * @param string $cdn_host CDN origin, e.g. https://cdn.example.com.
	 * @return string
	 */
	private static function rewrite_value( string $value, string $local, string $cdn_host ): string {
		$local_host  = parse_url( $local, PHP_URL_HOST );
		$local_scheme = parse_url( $local, PHP_URL_SCHEME );

		if ( 0 === strpos( $value, $local . '/' ) || $value === $local ) {
			return $cdn_host . substr( $value, strlen( $local ) );
		}

		// Scheme-relative local asset (//host/...).
		if ( $local_scheme && 0 === strpos( $value, '//' . $local_host . '/' ) ) {
			return ( 0 === strpos( $cdn_host, '//' ) ? '' : parse_url( $cdn_host, PHP_URL_SCHEME ) . ':' )
				. $cdn_host . substr( $value, strlen( '//' . $local_host ) );
		}

		// Root-relative path → prefix with CDN host.
		if ( 0 === strpos( $value, '/' ) && 0 !== strpos( $value, '//' ) && '/' !== $value ) {
			return $cdn_host . $value;
		}

		return $value;
	}

	/**
	 * Local origin (scheme + host) of this site.
	 *
	 * @return string
	 */
	private function local_host(): string {
		$home = function_exists( 'home_url' ) ? home_url( '/' ) : '';
		if ( '' === $home ) {
			return '';
		}
		return rtrim( $home, '/' );
	}

	/**
	 * Normalize the CDN URL to a full origin (scheme kept when omitted).
	 *
	 * @param string $cdn
	 * @return string
	 */
	private function cdn_host( string $cdn ): string {
		if ( 0 === strpos( $cdn, '//' ) ) {
			$scheme = function_exists( 'is_ssl' ) && is_ssl() ? 'https' : 'http';
			return $scheme . ':' . $cdn;
		}
		if ( ! preg_match( '#^https?://#i', $cdn ) ) {
			$cdn = 'https://' . ltrim( $cdn, '/' );
		}
		return rtrim( $cdn, '/' );
	}
}
