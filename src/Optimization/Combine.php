<?php
/**
 * Combine: merge same-directory local CSS/JS into content-hashed single files.
 *
 * Preserves document order, honors the media attribute, drops already-combined
 * files, and rewrites relative url() references in combined CSS to absolute.
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache\Optimization;

final class Combine {

	use AssetFetch;

	/**
	 * Combined asset directory relative to the cache root.
	 */
	private const COMBINED_DIR = 'combined';

	/**
	 * @param string              $html
	 * @param array<string,mixed> $settings
	 * @return string
	 */
	public function process( string $html, array $settings ): string {
		if ( ! empty( $settings['combine_css'] ) ) {
			$html = $this->combine_css( $html, $settings );
		}
		if ( ! empty( $settings['combine_js'] ) ) {
			$html = $this->combine_js( $html, $settings );
		}
		return $html;
	}

	/**
	 * Combine consecutive same-directory stylesheet links.
	 *
	 * @param string              $html
	 * @param array<string,mixed> $settings
	 * @return string
	 */
	private function combine_css( string $html, array $settings ): string {
		if ( ! preg_match_all( '#<link\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}

		$excluded = (array) ( $settings['exclude_css'] ?? array() );
		$meta     = array();

		foreach ( $m[0] as $match ) {
			$tag = $match[0];
			if ( ! $this->is_stylesheet( $tag ) ) {
				continue;
			}
			$url   = $this->href( $tag );
			$start = $match[1];
			$meta[] = array(
				'tag'      => $tag,
				'start'    => $start,
				'end'      => $start + strlen( $tag ),
				'url'      => $url,
				'media'    => $this->attr( $tag, 'media' ),
				'eligible' => $this->eligible( $url, 'css', $excluded ),
			);
		}

		// Group consecutive eligible links. A run is only joined when the gap
		// between two tags is pure whitespace, so an inline <style>/<script> or
		// a comment that was printed between the links can never be swallowed
		// by the replacement (audit C2).
		$groups  = array();
		$current = array();
		$last_dir = null;
		$prev_end = null;

		foreach ( $meta as $info ) {
			$dir     = $info['eligible'] ? $this->asset_dir( $info['url'] ) : null;
			$gap     = ( null === $prev_end ) ? '' : substr( $html, $prev_end, $info['start'] - $prev_end );
			$joined  = '' === trim( $gap ) && null !== $dir && $dir === $last_dir && 'all' === $info['media'];

			if ( $joined ) {
				$current[] = $info;
			} else {
				if ( count( $current ) >= 2 ) {
					$groups[] = $current;
				}
				$current  = $dir ? array( $info ) : array();
				$last_dir = $dir;
			}
			$prev_end = $info['end'];
		}
		if ( count( $current ) >= 2 ) {
			$groups[] = $current;
		}

		if ( ! $groups ) {
			return $html;
		}

		// Replace groups right-to-left so earlier offsets stay valid.
		foreach ( array_reverse( $groups ) as $group ) {
			$combined_url = $this->build_combined( array_column( $group, 'url' ), 'css', $group[0]['url'] );
			if ( null === $combined_url ) {
				continue;
			}
			$attrs = $this->carry_attrs( array_column( $group, 'tag' ), array( 'media' ) );
			$replacement = '<link rel="stylesheet" href="' . esc_attr( $combined_url ) . '"' . $attrs . '>';
			$html        = $this->replace_group( $html, $group, $replacement );
		}

		return $html;
	}

	/**
	 * Combine consecutive same-directory local scripts.
	 *
	 * @param string              $html
	 * @param array<string,mixed> $settings
	 * @return string
	 */
	private function combine_js( string $html, array $settings ): string {
		if ( ! preg_match_all( '#<script\b[^>]*src=["\'][^"\']+["\'][^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}

		$excluded = (array) ( $settings['exclude_js'] ?? array() );
		$meta     = array();

		foreach ( $m[0] as $match ) {
			$tag = $match[0];
			$url = $this->src( $tag );
			$start = $match[1];
			$meta[] = array(
				'tag'      => $tag,
				'start'    => $start,
				'end'      => $start + strlen( $tag ),
				'url'      => $url,
				'eligible' => $this->eligible( $url, 'js', $excluded ),
			);
		}

		// Group consecutive eligible scripts; joins require a pure-whitespace
		// gap so inline script content between them is never swallowed.
		$groups  = array();
		$current = array();
		$last_dir = null;
		$prev_end = null;

		foreach ( $meta as $info ) {
			$dir     = $info['eligible'] ? $this->asset_dir( $info['url'] ) : null;
			$gap     = ( null === $prev_end ) ? '' : substr( $html, $prev_end, $info['start'] - $prev_end );
			$joined  = '' === trim( $gap ) && null !== $dir && $dir === $last_dir;

			if ( $joined ) {
				$current[] = $info;
			} else {
				if ( count( $current ) >= 2 ) {
					$groups[] = $current;
				}
				$current  = $dir ? array( $info ) : array();
				$last_dir = $dir;
			}
			$prev_end = $info['end'];
		}
		if ( count( $current ) >= 2 ) {
			$groups[] = $current;
		}

		if ( ! $groups ) {
			return $html;
		}

		foreach ( array_reverse( $groups ) as $group ) {
			$combined_url = $this->build_combined( array_column( $group, 'url' ), 'js', $group[0]['url'] );
			if ( null === $combined_url ) {
				continue;
			}
			$attrs = $this->carry_attrs( array_column( $group, 'tag' ), array( 'defer', 'async', 'type' ) );
			$replacement = '<script src="' . esc_attr( $combined_url ) . '"' . $attrs . '></script>';
			$html        = $this->replace_group( $html, $group, $replacement );
		}

		return $html;
	}

	/**
	 * Build a combined asset file, returning its absolute URL.
	 *
	 * @param string[] $urls
	 * @param string   $type css|js
	 * @param string   $first Base URL for relative css url() resolution.
	 * @return string|null
	 */
	private function build_combined( array $urls, string $type, string $first ): ?string {
		$dir = \CosmicCache\Cache\Vary::cacheRoot() . '/' . self::COMBINED_DIR;

		// Refuse to combine when the cache root cannot be exposed over HTTP
		// (e.g. COSMIC_CACHE_DIR lives outside WP_CONTENT_DIR): writing a
		// combined file we cannot serve would leave the page with dead links.
		$public_dir = $this->public_combined_dir( $dir );
		if ( null === $public_dir ) {
			return null;
		}

		$contents = array();
		foreach ( $urls as $url ) {
			$content = $this->fetch( $url );
			if ( null === $content ) {
				return null;
			}
			if ( 'css' === $type ) {
				$content = $this->rewrite_css_urls( $content, $url );
			}
			$contents[] = $content;
		}

		$hash    = sha1( implode( "\n", $contents ) );
		$file    = $dir . '/' . $hash . '.' . $type;
		// Join with ";\n" so a file ending without a semicolon cannot change
		// the meaning of the next one (ASI hazard: `var a=1\n(function(){})()`).
		$content = implode( ";\n", $contents );

		if ( ! is_file( $file ) ) {
			\CosmicCache\Cache\Storage\Disk::atomicWrite( $file, $content );
		}

		return $public_dir . '/' . $hash . '.' . $type;
	}

	/**
	 * Map a directory inside the cache root to its public URL prefix, or null
	 * when it is not web-addressable (outside WP_CONTENT_DIR).
	 *
	 * @param string $dir
	 * @return string|null
	 */
	private function public_combined_dir( string $dir ): ?string {
		$wp_content = rtrim( WP_CONTENT_DIR, '/\\' );
		$dir_norm   = str_replace( '\\', '/', $dir );
		$content_norm = str_replace( '\\', '/', $wp_content );

		if ( 0 !== strpos( $dir_norm, $content_norm ) ) {
			return null;
		}

		$rel       = ltrim( substr( $dir_norm, strlen( $content_norm ) ), '/' );
		$base_url  = defined( 'WP_CONTENT_URL' ) ? WP_CONTENT_URL : home_url( '/wp-content' );

		return rtrim( $base_url, '/' ) . ( '' === $rel ? '' : '/' . $rel );
	}

	/**
	 * Rewrite relative url() references in CSS to root-absolute paths.
	 *
	 * @param string $css
	 * @param string $base_url
	 * @return string
	 */
	private function rewrite_css_urls( string $css, string $base_url ): string {
		$base_dir = $this->asset_dir( $base_url );

		return (string) preg_replace_callback(
			'#url\(\s*(["\']?)([^)"\']+)(["\']?)\s*\)#i',
			static function ( $m ) use ( $base_dir ) {
				$url = trim( $m[2] );
				if ( '' === $url || 0 === strpos( $url, 'data:' ) || 0 === strpos( $url, '#' )
					|| preg_match( '#^https?://#i', $url ) || 0 === strpos( $url, '//' )
					|| 0 === strpos( $url, '/' ) ) {
					return $m[0];
				}
				return 'url(' . $m[1] . '/' . ltrim( $base_dir, '/' ) . '/' . $url . $m[3] . ')';
			},
			$css
		);
	}

	/**
	 * Is an asset eligible (local, not excluded, not already combined)?
	 *
	 * @param string   $url
	 * @param string   $type css|js
	 * @param string[] $excluded
	 * @return bool
	 */
	private function eligible( string $url, string $type, array $excluded ): bool {
		if ( '' === $url ) {
			return false;
		}
		// Only real asset URLs with a matching extension (and, for CSS, only
		// stylesheet rel — enforced by the caller for <link>). Prevents a
		// crafted href that traverses the filesystem from being fetched.
		$path = (string) parse_url( $url, PHP_URL_PATH );
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( 'css' === $type && 'css' !== $ext ) {
			return false;
		}
		if ( 'js' === $type && 'js' !== $ext ) {
			return false;
		}
		if ( preg_match( '#(^|/)\.\.(/|$)#', $path ) ) {
			return false;
		}
		if ( ! $this->is_local( $url ) ) {
			return false;
		}
		if ( false !== strpos( $url, self::COMBINED_DIR ) ) {
			return false;
		}
		foreach ( $excluded as $pattern ) {
			if ( \CosmicCache\Cache\Bypass::matches( (string) $pattern, $url ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Is this <link> tag a stylesheet?
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
	 * Carry over attributes shared by every tag in a group (media for CSS;
	 * defer/async/type for JS). Keeps execution semantics close to the source.
	 *
	 * @param string[] $tags
	 * @param string[] $names
	 * @return string "" or " name=\"value\" name2"
	 */
	private function carry_attrs( array $tags, array $names ): string {
		$carried = array();
		foreach ( $names as $name ) {
			$common = null;
			$value  = null;
			foreach ( $tags as $tag ) {
				$v = $this->attr( $tag, $name );
				if ( null === $common ) {
					$common = true;
					$value  = $v;
				} elseif ( $v !== $value ) {
					$common = false;
					break;
				}
			}
			// Only carry an attribute that is present and identical on all tags.
			if ( true === $common && '' !== $value ) {
				$carried[] = ' ' . $name . '="' . esc_attr( $value ) . '"';
			}
		}
		return implode( '', $carried );
	}

	/**
	 * Replace the exact source span of a group (first tag start to last tag
	 * end). Uses captured offsets, so content literally printed between the two
	 * tags is never part of a group (groups only join across whitespace) and
	 * an identical tag string elsewhere in the page can never be grabbed.
	 *
	 * @param string         $html
	 * @param array<int,array<string,mixed>> $group
	 * @param string         $replacement
	 * @return string
	 */
	private function replace_group( string $html, array $group, string $replacement ): string {
		$start = (int) $group[0]['start'];
		$end   = (int) $group[ count( $group ) - 1 ]['end'];

		if ( $end <= $start ) {
			return $html;
		}

		return substr_replace( $html, $replacement, $start, $end - $start );
	}

	/**
	 * Directory of an asset URL (path dirname without the query).
	 *
	 * @param string $url
	 * @return string
	 */
	private function asset_dir( string $url ): string {
		$path = (string) parse_url( $url, PHP_URL_PATH );
		$dir  = dirname( $path );
		return ( '/' === $dir ) ? '' : $dir;
	}

	/**
	 * Extract href attribute.
	 *
	 * @param string $tag
	 * @return string
	 */
	private function href( string $tag ): string {
		return html_entity_decode( $this->attr( $tag, 'href' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Extract src attribute.
	 *
	 * @param string $tag
	 * @return string
	 */
	private function src( string $tag ): string {
		return html_entity_decode( $this->attr( $tag, 'src' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
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
