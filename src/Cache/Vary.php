<?php
/**
 * Vary key computation + cache file path resolution.
 *
 * This class runs on the HOT PATH (before WordPress boots) and therefore must
 * never call WordPress APIs. It is compiled into wp-content/advanced-cache.php
 * by Plugin::writeAdvancedCache(), so keep it self-contained.
 *
 * @package cosmic-cache
 */

namespace CosmicCache\Cache;

final class Vary {

	/**
	 * Default mobile UA regex (SPEC §7.3).
	 */
	private const MOBILE_REGEX = 'iPhone|iPod|iPad|Android|Mobile|Opera Mini|Opera Mobi|IEMobile|Windows Phone|BlackBerry|Kindle|Silk|webOS|Fennec';

	/**
	 * Build a normalized request context from superglobals.
	 *
	 * @return array<string,mixed>
	 */
	public static function requestEnv(): array {
		$server = $_SERVER;

		$scheme = 'http';
		// Honor X-Forwarded-Proto when present (TLS-terminating proxy/LB).
		// Take the first comma-separated value; treat 'https'/'on' as secure.
		$forwarded = isset( $server['HTTP_X_FORWARDED_PROTO'] ) ? (string) $server['HTTP_X_FORWARDED_PROTO'] : '';
		$forwarded = strtolower( trim( strtok( $forwarded, ',' ) ?: $forwarded ) );
		if ( '' !== $forwarded ) {
			$scheme = in_array( $forwarded, array( 'https', 'on' ), true ) ? 'https' : 'http';
		} elseif ( ( ! empty( $server['HTTPS'] ) && 'off' !== $server['HTTPS'] )
			|| ( isset( $server['SERVER_PORT'] ) && 443 === (int) $server['SERVER_PORT'] ) ) {
			$scheme = 'https';
		}

		$host = self::sanitizeHost( isset( $server['HTTP_HOST'] ) ? (string) $server['HTTP_HOST'] : '' );

		$uri   = isset( $server['REQUEST_URI'] ) ? (string) $server['REQUEST_URI'] : '/';
		$path  = (string) parse_url( $uri, PHP_URL_PATH );
		$query = array();
		$qs    = (string) parse_url( $uri, PHP_URL_QUERY );
		if ( '' !== $qs ) {
			parse_str( $qs, $query );
		}

		return array(
			'scheme'  => $scheme,
			'host'    => $host,
			'path'    => ( '' === $path ) ? '/' : '/' . ltrim( $path, '/' ),
			'method'  => isset( $server['REQUEST_METHOD'] ) ? strtoupper( (string) $server['REQUEST_METHOD'] ) : 'GET',
			'ua'      => isset( $server['HTTP_USER_AGENT'] ) ? (string) $server['HTTP_USER_AGENT'] : '',
			'query'   => $query,
			'cookies' => $_COOKIE,
		);
	}

	/**
	 * Sanitize a host header: lowercase, strip port and user/pass. Rejects dot
	 * path components (`.`, `..`) so a crafted Host can never escape the
	 * per-host cache directory.
	 *
	 * @param string $host
	 * @return string
	 */
	public static function sanitizeHost( string $host ): string {
		$host = strtolower( trim( $host ) );
		// Strip any userinfo (scheme-less userinfo is not valid in Host but be safe).
		$at = strrpos( $host, '@' );
		if ( false !== $at ) {
			$host = substr( $host, $at + 1 );
		}
		// Strip port.
		$host = preg_replace( '/:\d+$/', '', $host ) ?? $host;
		// Keep only valid host characters.
		if ( ! preg_match( '/^[a-z0-9.\-]+$/', $host ) ) {
			return '';
		}
		// No leading/trailing dots and no `.`/`..` labels: both would escape
		// $root/scheme/<host> into a parent or the root itself.
		if ( 1 === preg_match( '/\.\./', $host ) || '.' === $host || '..' === $host ) {
			return '';
		}
		return $host;
	}

	/**
	 * Is the request variant-dependent (mobile/cookie/lang)?
	 *
	 * @param array<string,mixed> $settings
	 * @return bool
	 */
	public static function hasVariants( array $settings ): bool {
		if ( ! empty( $settings['cache_mobile'] ) ) {
			return true;
		}
		if ( ! empty( $settings['cache_cookies'] ) ) {
			return true;
		}
		if ( ! empty( $settings['cache_logged_in'] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * The cache root directory. Mirrors Config::cacheDir() but without any WP
	 * dependency so it can run on the hot path.
	 *
	 * @return string
	 */
	public static function cacheRoot(): string {
		if ( defined( 'COSMIC_CACHE_DIR' ) && COSMIC_CACHE_DIR ) {
			return rtrim( (string) COSMIC_CACHE_DIR, '/\\' );
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return WP_CONTENT_DIR . '/cache/cosmic';
		}
		return '';
	}

	/**
	 * Resolve the blog id for a host on the hot path. Single-site installs and
	 * unknown hosts resolve to 0 (no blog segment in the file path).
	 *
	 * @param array<string,mixed> $settings Compiled config (may include 'blogs').
	 * @param string              $host
	 * @return int
	 */
	public static function blogId( array $settings, string $host = '' ): int {
		if ( empty( $settings['blogs'] ) || ! is_array( $settings['blogs'] ) ) {
			return 0;
		}
		$host = strtolower( trim( $host ) );
		if ( '' === $host ) {
			return 0;
		}
		return (int) ( $settings['blogs'][ $host ] ?? 0 );
	}

	/**
	 * Hashable key: scheme://host/path?canonical-query.
	 *
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $env
	 * @return string
	 */
	public static function hashKey( array $settings, array $env ): string {
		$path = strtolower( $env['path'] );
		$path = '/' . ltrim( $path, '/' );

		$whitelist = array_flip( (array) ( $settings['cache_whitelist_query'] ?? array() ) );
		$filtered  = array();
		foreach ( (array) ( $env['query'] ?? array() ) as $key => $value ) {
			$key = (string) $key;
			if ( isset( $whitelist[ $key ] ) && is_scalar( $value ) ) {
				$filtered[ $key ] = (string) $value;
			}
		}
		ksort( $filtered );

		$query = '';
		if ( $filtered ) {
			$query = '?' . http_build_query( $filtered );
		}

		return $env['scheme'] . '://' . $env['host'] . $path . $query;
	}

	/**
	 * Vary suffix (`.vary-<sha1>`) or an empty string.
	 *
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $env
	 * @return string
	 */
	public static function varySuffix( array $settings, array $env ): string {
		$parts = array();

		if ( ! empty( $settings['cache_mobile'] ) && self::isMobile( $env['ua'] ?? '' ) ) {
			$parts[] = 'mobile';
		}

		$cookies = (array) ( $env['cookies'] ?? array() );

		foreach ( (array) ( $settings['cache_cookies'] ?? array() ) as $name ) {
			$name = (string) $name;
			if ( '' === $name ) {
				continue;
			}
			if ( isset( $cookies[ $name ] ) ) {
				$parts[] = $name . '=' . (string) $cookies[ $name ];
			}
		}

		if ( ! empty( $settings['cache_logged_in'] ) ) {
			foreach ( $cookies as $name => $value ) {
				if ( 0 === strpos( (string) $name, 'wordpress_logged_in_' ) ) {
					$parts[] = 'logged_in=' . (string) $value;
					break;
				}
			}
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return '.vary-' . sha1( implode( '|', $parts ) );
	}

	/**
	 * Resolve the absolute cache file path for a request.
	 *
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $env
	 * @param int                 $blog_id Capture-path blog id (0 on hot path).
	 * @return string
	 */
	public static function resolveFile( array $settings, array $env, int $blog_id = 0 ): string {
		$root = self::cacheRoot();
		if ( '' === $root || '' === $env['host'] ) {
			return '';
		}

		if ( 0 === $blog_id ) {
			$blog_id = self::blogId( $settings, (string) $env['host'] );
		}

		$key  = self::hashKey( $settings, $env );
		$name = sha1( $key ) . self::varySuffix( $settings, $env ) . '.html';

		$dir = $root . '/' . $env['scheme'] . '/' . $env['host'];
		if ( $blog_id ) {
			$dir .= '/' . $blog_id;
		}

		return $dir . '/' . $name;
	}

	/**
	 * Mobile UA detection using the default mobile regex.
	 *
	 * @param string $ua
	 * @return bool
	 */
	public static function isMobile( string $ua ): bool {
		if ( '' === $ua ) {
			return false;
		}
		return (bool) preg_match( '/(?:' . self::MOBILE_REGEX . ')/i', $ua );
	}
}
