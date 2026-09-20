<?php
/**
 * Hot-path serving: encoding negotiation, headers, 304/HEAD handling,
 * readfile() + exit().
 *
 * This is the canonical source that is compiled into
 * wp-content/advanced-cache.php by Plugin::writeAdvancedCache(). It must run
 * before WordPress boots: no WP APIs, no Config, no plugin requires.
 *
 * @package cosmic-cache
 */

namespace CosmicCache\Cache;

final class Serve {

	/** Max debug log size before truncation (bytes). */
	private const MAX_DEBUG_BYTES = 5 * 1024 * 1024;

	/** Internal Nginx location that aliases the cache root (see readme FAQ). */
	private const ACCEL_URI_PREFIX = '/cosmic-cache/';

	/**
	 * Full hot-path dispatch. Returns (without serving) when the request is not
	 * cacheable or no file exists, so WordPress boots normally (fail-open).
	 *
	 * @return void
	 */
	public static function dispatch(): void {
		$root = Vary::cacheRoot();
		if ( '' === $root ) {
			return;
		}

		$config_file = $root . '/config.php';
		if ( ! is_file( $config_file ) || ! self::looksLikeConfig( $config_file ) ) {
			return;
		}

		// Fail-open: anything that throws on the hot path (bad config, hostile
		// request env, IO hiccup) lets WordPress boot normally instead of
		// taking the site down.
		try {
			$settings = include $config_file;
			if ( ! is_array( $settings ) ) {
				return;
			}

			self::applyConstants( $settings );

			// Single-site only: the compiled config carries this flag when the
			// site is multisite without COSMIC_CACHE_ALLOW_MULTISITE. Fail open.
			if ( ! empty( $settings['multisite_disabled'] ) || empty( $settings['enabled'] ) ) {
				return;
			}

			$env = Vary::requestEnv();

			if ( Bypass::shouldBypassHotPath( $settings, $env ) ) {
				return;
			}

			$file = Vary::resolveFile( $settings, $env );
			if ( '' === $file || ! is_file( $file ) ) {
				self::logDebug( $settings, 'miss', $env['path'] );
				return;
			}

			// Serve-time TTL: an entry older than ttl is deleted (all variants)
			// and treated as a miss so WordPress re-renders and re-caches.
			if ( self::expired( $settings, $file ) ) {
				self::expire_variants( $file );
				self::logDebug( $settings, 'miss', $env['path'] );
				return;
			}

			self::serve( $settings, $file );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Is a cached file past its TTL (filemtime + ttl < now)? TTL 0 disables.
	 *
	 * @param array<string,mixed> $settings
	 * @param string              $file
	 * @return bool
	 */
	private static function expired( array $settings, string $file ): bool {
		$ttl = (int) ( $settings['ttl'] ?? 0 );
		if ( $ttl <= 0 ) {
			return false;
		}
		$mtime = (int) @filemtime( $file );
		return $mtime > 0 && ( time() - $mtime ) > $ttl;
	}

	/**
	 * Delete a cache file and its precompressed variants. Self-contained so it
	 * runs pre-boot (Disk is not compiled into the advanced-cache drop-in).
	 *
	 * @param string $file
	 * @return void
	 */
	private static function expire_variants( string $file ): void {
		foreach ( array( $file, $file . '.gz', $file . '.br' ) as $candidate ) {
			if ( is_file( $candidate ) ) {
				@unlink( $candidate );
			}
		}
	}

	/**
	 * Append a structured line to the bounded debug log when the debug flag is
	 * set. Self-contained: runs pre-boot with no WP API or Config dependency.
	 *
	 * @param array<string,mixed> $settings
	 * @param string              $channel
	 * @param string              $message
	 * @return void
	 */
	private static function logDebug( array $settings, string $channel, string $message ): void {
		if ( empty( $settings['debug'] ) ) {
			return;
		}

		if ( defined( 'COSMIC_CACHE_DIR' ) && COSMIC_CACHE_DIR ) {
			// Mirror Config::cacheDir() so a custom cache root (SPEC §4/§6.3)
			// also receives the debug log. Self-contained pre-boot.
			$file = rtrim( (string) COSMIC_CACHE_DIR, '/\\' ) . '/debug.log';
			self::appendDebug( $file, $channel, $message );
			return;
		}

		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$dir = WP_CONTENT_DIR . '/cache/cosmic';
		} elseif ( defined( 'ABSPATH' ) ) {
			$dir = ABSPATH . 'wp-content/cache/cosmic';
		} else {
			return;
		}

		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0755, true );
		}

		self::appendDebug( $dir . '/debug.log', $channel, $message );
	}

	/**
	 * Append a bounded debug line to a log file (size-capped + LOCK_EX).
	 *
	 * @param string $file
	 * @param string $channel
	 * @param string $message
	 * @return void
	 */
	private static function appendDebug( string $file, string $channel, string $message ): void {
		if ( is_file( $file ) && filesize( $file ) > self::MAX_DEBUG_BYTES ) {
			@file_put_contents( $file, '' );
		}

		$line = gmdate( 'c' ) . " [cosmic-cache:{$channel}] {$message}\n";
		@file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Cheap fail-open guard: only include files that look like a generated
	 * Cosmic config (PHP opening tag + our header marker). Prevents a foreign
	 * or partially-written file from producing a fatal on the hot path.
	 *
	 * @param string $file Absolute path.
	 * @return bool
	 */
	private static function looksLikeConfig( string $file ): bool {
		$head = (string) @file_get_contents( $file, false, null, 0, 512 );
		if ( 0 !== strpos( $head, '<?php' ) ) {
			return false;
		}
		return false !== strpos( $head, 'Cosmic Cache runtime config' );
	}

	/**
	 * Evaluate COSMIC_CACHE_* constants at request time (SPEC §6.3).
	 *
	 * @param array<string,mixed> $settings
	 * @return void
	 */
	private static function applyConstants( array &$settings ): void {
		if ( defined( 'COSMIC_CACHE_ENABLE' ) && COSMIC_CACHE_ENABLE ) {
			$settings['enabled'] = true;
		} elseif ( defined( 'COSMIC_CACHE_DISABLE' ) && COSMIC_CACHE_DISABLE ) {
			$settings['enabled'] = false;
		}

		if ( defined( 'COSMIC_CACHE_TTL' ) ) {
			$settings['ttl'] = (int) COSMIC_CACHE_TTL;
		}

		if ( defined( 'COSMIC_CACHE_DEBUG' ) && COSMIC_CACHE_DEBUG ) {
			$settings['debug'] = true;
		}
	}

	/**
	 * Emit headers and body for a cache hit.
	 *
	 * @param array<string,mixed> $settings
	 * @param string              $file Absolute path to the .html file.
	 * @return void
	 */
	public static function serve( array $settings, string $file ): void {
		$is_head = 'HEAD' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		$t0      = microtime( true );

		list( $path, $encoding ) = self::negotiate( $settings, $file );

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=UTF-8' );
			if ( $encoding ) {
				header( 'Content-Encoding: ' . $encoding );
			}
			header( self::varyHeader( $settings ) );
			header( self::cacheControl( $settings ) );

			$mtime = (int) @filemtime( $path );
			$size  = (int) @filesize( $path );
			$etag  = sha1( $mtime . ':' . $size );

			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
			header( 'ETag: "' . $etag . '"' );
			header( 'X-Cache: HIT' );

			if ( ! empty( $settings['debug'] ) ) {
				header( 'X-Cosmic-Cache: hit ' . $path );
			}

			if ( self::isNotModified( $mtime, $etag ) ) {
				http_response_code( 304 );
				self::logServeHit( $settings, $path, $t0 );
				exit;
			}

			http_response_code( 200 );
			header( 'Content-Length: ' . $size );
		}

		if ( $is_head ) {
			self::logServeHit( $settings, $path, $t0 );
			exit;
		}

		$sendfile = self::sendfileHeader( $settings, $path );
		if ( null !== $sendfile ) {
			header( $sendfile );
			self::logServeHit( $settings, $path, $t0 );
			exit;
		}

		@readfile( $path );
		self::logServeHit( $settings, $path, $t0 );
		exit;
	}

	/**
	 * Zero-copy body delivery header when the server supports it, or null to
	 * fall back to readfile().
	 *
	 * - serve_method = x-sendfile  → Apache mod_xsendfile, absolute path.
	 * - serve_method = x-accel-redirect → Nginx, internal URI aliased to the
	 *   cache root (see readme FAQ for the location block).
	 *
	 * The path is only ever converted when it lies inside the cache root;
	 * otherwise null (fail-open) so a misconfiguration can never redirect the
	 * server to a file outside the cache directory.
	 *
	 * @param array<string,mixed> $settings
	 * @param string              $path Absolute path to the chosen variant.
	 * @return string|null Header line ("Name: value") or null.
	 */
	public static function sendfileHeader( array $settings, string $path ): ?string {
		$method = (string) ( $settings['serve_method'] ?? 'php' );

		$root = Vary::cacheRoot();
		if ( '' === $root || 0 !== strpos( $path, $root . '/' ) ) {
			return null;
		}

		if ( 'x-sendfile' === $method ) {
			return 'X-Sendfile: ' . $path;
		}

		if ( 'x-accel-redirect' === $method ) {
			$relative = substr( $path, strlen( $root ) );
			return 'X-Accel-Redirect: ' . self::ACCEL_URI_PREFIX . ltrim( $relative, '/' );
		}

		return null;
	}

	/**
	 * Log a served hit with PHP execution time (SPEC §17 perf gate) when
	 * debug logging is enabled.
	 *
	 * @param array<string,mixed> $settings
	 * @param string              $path
	 * @param float               $t0 microtime() at serve() entry.
	 * @return void
	 */
	private static function logServeHit( array $settings, string $path, float $t0 ): void {
		if ( empty( $settings['debug'] ) ) {
			return;
		}
		$ms = ( microtime( true ) - $t0 ) * 1000;
		self::logDebug( $settings, 'serve', 'hit ' . $path . ' in ' . number_format( $ms, 3, '.', '' ) . ' ms' );
	}

	/**
	 * Choose the best precompressed variant for the client.
	 *
	 * @param array<string,mixed> $settings
	 * @param string              $file
	 * @return array{0:string,1:string|null} [path, encoding]
	 */
	private static function negotiate( array $settings, string $file ): array {
		$accept = strtolower( (string) ( $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '' ) );
		$gzip   = ! empty( $settings['gzip'] );
		$brotli = ! empty( $settings['brotli'] ) && function_exists( 'brotli_compress' );

		$prefs = self::encodingPrefs( $accept );

		// Never serve an encoding the client disabled with ;q=0 (SPEC §7 best
		// variant is preferred, so br is checked before gzip as before).
		if ( $brotli && is_file( $file . '.br' ) && self::prefAccept( $prefs, 'br' ) ) {
			return array( $file . '.br', 'br' );
		}

		if ( $gzip && is_file( $file . '.gz' ) && self::prefAccept( $prefs, 'gzip', 'deflate', 'x-gzip' ) ) {
			return array( $file . '.gz', 'gzip' );
		}

		return array( $file, null );
	}

	/**
	 * Parse Accept-Encoding into an ordered q-value map. Handles splats
	 * ("*") and absent values (default q=1). Unknown values default to q=1
	 * so a plain "gzip" header still enables gzip variants.
	 *
	 * @param string $header
	 * @return array<string,int|float>
	 */
	private static function encodingPrefs( string $header ): array {
		$prefs = array();
		if ( '' === $header ) {
			return $prefs;
		}
		foreach ( explode( ',', $header ) as $item ) {
			$item = trim( $item );
			if ( '' === $item ) {
				continue;
			}
			$parts = explode( ';', $item );
			$name  = strtolower( trim( $parts[0] ) );
			$q     = 1;
			for ( $i = 1, $n = count( $parts ); $i < $n; $i++ ) {
				$kv = explode( '=', trim( $parts[ $i ] ) );
				if ( 2 === count( $kv ) && 'q' === strtolower( trim( $kv[0] ) ) && is_numeric( trim( $kv[1] ) ) ) {
					$q = (float) trim( $kv[1] );
				}
			}
			$prefs[ $name ] = $q;
		}
		return $prefs;
	}

	/**
	 * Is any alias accepted (q > 0) in the header?
	 *
	 * @param array<string,int|float> $prefs
	 * @param string                  ...$names
	 * @return bool
	 */
	private static function prefAccept( array $prefs, string ...$names ): bool {
		foreach ( $names as $name ) {
			if ( isset( $prefs[ $name ] ) ) {
				return (float) $prefs[ $name ] > 0;
			}
		}
		// No explicit mention: a bare header like "br" couldn't reach here, and
		// an absent gzip token with a wildcard means "anything". Choose the
		// default only when a wildcard is present; otherwise fall through.
		return isset( $prefs['*'] ) && (float) $prefs['*'] > 0;
	}

	/**
	 * Vary header: Accept-Encoding (+ Cookie/User-Agent when variants active).
	 *
	 * @param array<string,mixed> $settings
	 * @return string
	 */
	private static function varyHeader( array $settings ): string {
		$vary = array();

		if ( ! empty( $settings['gzip'] ) || ( ! empty( $settings['brotli'] ) && function_exists( 'brotli_compress' ) ) ) {
			$vary[] = 'Accept-Encoding';
		}

		if ( Vary::hasVariants( $settings ) ) {
			$vary[] = 'Cookie, User-Agent';
		}

		return 'Vary: ' . implode( ', ', $vary );
	}

	/**
	 * Cache-Control header per TTL (SPEC §7.4).
	 *
	 * @param array<string,mixed> $settings
	 * @return string
	 */
	private static function cacheControl( array $settings ): string {
		if ( ! empty( $settings['cache_headers'] ) ) {
			$ttl = (int) $settings['ttl'];
			if ( $ttl > 0 ) {
				return 'Cache-Control: public, max-age=' . $ttl;
			}
			return 'Cache-Control: public, max-age=0, must-revalidate';
		}

		return 'Cache-Control: no-cache';
	}

	/**
	 * Honor If-Modified-Since / If-None-Match → 304.
	 *
	 * @param int    $mtime
	 * @param string $etag
	 * @return bool
	 */
	private static function isNotModified( int $mtime, string $etag ): bool {
		$if_none_match = trim( (string) ( $_SERVER['HTTP_IF_NONE_MATCH'] ?? '' ), '"' );
		if ( '' !== $if_none_match && $if_none_match === $etag ) {
			return true;
		}

		$if_modified_since = (string) ( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '' );
		if ( '' !== $if_modified_since ) {
			$since = strtotime( $if_modified_since );
			if ( false !== $since && (int) $since === $mtime ) {
				return true;
			}
		}

		return false;
	}
}
