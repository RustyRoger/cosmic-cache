<?php
/**
 * Bypass rules — two intentionally separated layers (SPEC §7.2).
 *
 * Hot path: runs in the drop-in before WordPress boots; URI/regex/superglobals
 * only, no WP API. Capture path: runs after boot and can consult WP state; the
 * `cosmic_cache_is_cacheable` filter can override its result.
 *
 * This class is compiled into wp-content/advanced-cache.php, so keep it
 * self-contained (no Config, no WP functions).
 *
 * @package cosmic-cache
 */

namespace CosmicCache\Cache;

final class Bypass {

	/**
	 * Hot-path decision: should this request skip the cache?
	 *
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $env Normalized request context (see Vary::requestEnv()).
	 * @return bool
	 */
	public static function shouldBypassHotPath( array $settings, array $env ): bool {
		$method = strtoupper( (string) ( $env['method'] ?? 'GET' ) );
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return true;
		}

		$query   = (array) ( $env['query'] ?? array() );
		$bypass  = array_flip( array_map( 'strval', (array) ( $settings['bypass_query'] ?? array() ) ) );
		$allowed = array_flip( array_map( 'strval', (array) ( $settings['cache_whitelist_query'] ?? array() ) ) );

		foreach ( $query as $key => $value ) {
			$key = (string) $key;

			if ( '_wpnonce' === $key ) {
				return true;
			}
			if ( isset( $bypass[ $key ] ) ) {
				return true;
			}
			// Feed/preview are deliberately never whitelisted (SPEC §7.2).
			if ( 'feed' === $key || 'preview' === $key ) {
				return true;
			}
			if ( ! isset( $allowed[ $key ] ) ) {
				return true;
			}
		}

		// Cache-bust parameter.
		if ( isset( $query['nocache'] ) ) {
			return true;
		}

		$cookies = (array) ( $env['cookies'] ?? array() );

		// When caching logged-in users, the auth cookie moves to the vary key.
		$exclude = (array) ( $settings['exclude_cookies'] ?? array() );
		if ( ! empty( $settings['cache_logged_in'] ) ) {
			$exclude = array_values(
				array_filter(
					$exclude,
					static function ( $pattern ) {
						return 0 !== strpos( (string) $pattern, 'wordpress_logged_in_' );
					}
				)
			);
		}

		foreach ( array_keys( $cookies ) as $name ) {
			foreach ( $exclude as $pattern ) {
				if ( self::matches( (string) $pattern, (string) $name ) ) {
					return true;
				}
			}
		}

		$path = (string) ( $env['path'] ?? '/' );

		if ( self::isProtectedPath( $path, $query ) ) {
			return true;
		}

		foreach ( (array) ( $settings['exclude_urls'] ?? array() ) as $pattern ) {
			if ( self::matches( (string) $pattern, $path ) ) {
				return true;
			}
		}

		$ua = (string) ( $env['ua'] ?? '' );
		foreach ( (array) ( $settings['bypass_user_agents'] ?? array() ) as $pattern ) {
			if ( self::matches( (string) $pattern, $ua ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Hardcoded protected paths that are never cached.
	 *
	 * @param string $path
	 * @param array  $query
	 * @return bool
	 */
	private static function isProtectedPath( string $path, array $query ): bool {
		if ( false !== strpos( $path, 'wp-admin' ) ) {
			return true;
		}
		if ( false !== strpos( $path, 'wp-login.php' ) ) {
			return true;
		}
		if ( false !== strpos( $path, 'wp-json' ) ) {
			return true;
		}
		if ( preg_match( '#/robots\.txt$#', $path ) ) {
			return true;
		}
		if ( preg_match( '#/feed/?$#', $path ) ) {
			return true;
		}
		// Post preview / feed query params.
		if ( isset( $query['feed'] ) || ( isset( $query['preview'] ) && 'false' !== (string) $query['preview'] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Capture-path decision: should this (booted) request be served live and
	 * never stored? Runs inside WordPress, so WP APIs are available.
	 *
	 * @param array<string,mixed> $settings
	 * @return bool
	 */
	public static function shouldBypassCapture( array $settings ): bool {
		if ( empty( $settings['cache_logged_in'] ) && function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			return true;
		}

		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return true;
		}
		if ( function_exists( 'is_feed' ) && is_feed() ) {
			return true;
		}
		if ( function_exists( 'is_robots' ) && is_robots() ) {
			return true;
		}
		if ( function_exists( 'is_404' ) && is_404() ) {
			return true;
		}
		if ( function_exists( 'is_search' ) && is_search() ) {
			return true;
		}
		if ( function_exists( 'is_trackback' ) && is_trackback() ) {
			return true;
		}
		if ( function_exists( 'is_preview' ) && is_preview() ) {
			return true;
		}

		// REST.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		if ( function_exists( 'post_password_required' ) && post_password_required() ) {
			return true;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return true;
		}

		if ( ! empty( $_POST['_wpnonce'] ) ) {
			return true;
		}

		// Non-200 responses (redirect/404/error) are never stored.
		if ( function_exists( 'http_response_code' ) ) {
			$code = http_response_code();
			if ( false !== $code && 200 !== (int) $code ) {
				return true;
			}
		}

		// Allow override via filter (capture path only).
		if ( has_filter( 'cosmic_cache_is_cacheable' ) ) {
			$cacheable = apply_filters( 'cosmic_cache_is_cacheable', true, $settings );
			return ! $cacheable;
		}

		return false;
	}

	/**
	 * Pattern matcher: supports regexes (delimited) and * wildcard strings.
	 *
	 * @param string $pattern
	 * @param string $subject
	 * @return bool
	 */
	public static function matches( string $pattern, string $subject ): bool {
		if ( '' === $pattern ) {
			return false;
		}

		if ( self::isRegex( $pattern ) ) {
			$result = @preg_match( $pattern, $subject );
			return false !== $result && 1 === $result;
		}

		// Wildcard match, case-insensitive.
		$quoted  = preg_quote( $pattern, '#' );
		$quoted  = str_replace( '\\*', '.*', $quoted );
		return (bool) preg_match( '#' . $quoted . '#i', $subject );
	}

	/**
	 * Is a pattern a delimited regex (e.g. /^\/product/ or ~foo~i)?
	 *
	 * @param string $pattern
	 * @return bool
	 */
	public static function isRegex( string $pattern ): bool {
		$len = strlen( $pattern );
		if ( $len < 3 ) {
			return false;
		}

		$first = $pattern[0];
		if ( ctype_alnum( $first ) || '\\' === $first || '.' === $first || '*' === $first ) {
			return false;
		}

		// Find the closing delimiter by scanning from the end (allow modifiers).
		$close = strrpos( $pattern, $first );
		if ( $close <= 0 ) {
			return false;
		}

		$modifiers = substr( $pattern, $close + 1 );
		return '' === $modifiers || (bool) preg_match( '/^[imsxuADSUXJU]*$/', $modifiers );
	}
}
