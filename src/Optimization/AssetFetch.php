<?php
/**
 * AssetFetch: disk-first asset retrieval shared by the optimization stages.
 *
 * Local files are read from disk (realpath-normalized and verified to stay
 * inside WP_CONTENT_DIR, so `..` segments can never read arbitrary files);
 * anything else falls back to HTTP. Shared by Combine (capture path) and the
 * critical-CSS generator (admin/CLI only, never the hot path).
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache\Optimization;

trait AssetFetch {

	/**
	 * Fetch asset content from disk (preferred) or via HTTP.
	 *
	 * @param string $url
	 * @return string|null
	 */
	private function fetch( string $url ): ?string {
		$path = $this->url_to_path( $url );
		if ( $path && is_file( $path ) ) {
			$content = file_get_contents( $path );
			return false === $content ? null : $content;
		}

		if ( ! function_exists( 'wp_remote_get' ) ) {
			return null;
		}
		$response = wp_remote_get( $url, array( 'timeout' => 5 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Map an asset URL to a filesystem path when it lives under WP_CONTENT.
	 *
	 * The result is realpath()-normalized and verified to stay inside
	 * WP_CONTENT_DIR, so `..` segments can never read arbitrary files.
	 *
	 * @param string $url
	 * @return string|null
	 */
	private function url_to_path( string $url ): ?string {
		$url_no_query = strtok( $url, '?' ) ?: $url;
		$content_url  = defined( 'WP_CONTENT_URL' ) ? WP_CONTENT_URL : '';
		if ( '' === $content_url || 0 !== strpos( $url_no_query, $content_url ) ) {
			return null;
		}

		$base = realpath( WP_CONTENT_DIR );
		$rel  = rawurldecode( substr( $url_no_query, strlen( $content_url ) ) );
		$path = realpath( $base . '/' . ltrim( $rel, '/\\' ) );

		if ( false === $base || false === $path ) {
			return null;
		}

		// Containment check: resolved path must be inside WP_CONTENT_DIR.
		if ( $path !== $base && 0 !== strpos( $path, rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR ) ) {
			return null;
		}

		return $path;
	}

	/**
	 * Local assets are those hosted on this site.
	 *
	 * @param string $url
	 * @return bool
	 */
	private function is_local( string $url ): bool {
		$host = (string) parse_url( $url, PHP_URL_HOST );
		if ( '' === $host ) {
			return true; // scheme-relative or root-relative local file.
		}
		if ( ! function_exists( 'home_url' ) ) {
			return false;
		}
		$home_host = (string) parse_url( home_url( '/' ), PHP_URL_HOST );
		return $host === $home_host;
	}
}
