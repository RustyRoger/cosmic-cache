<?php
/**
 * Shared trait for atomic file writes: temp file + flock + rename.
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache;

/**
 * Trait for atomic file operations.
 *
 * Provides a reliable write method that uses temp file + rename to prevent
 * corruption from interrupted writes.
 */
trait AtomicWriteTrait {

	/**
	 * Atomic write: temp file + flock + rename.
	 *
	 * @param string $file
	 * @param string $content
	 * @return bool
	 */
	public static function atomicWrite( string $file, string $content ): bool {
		$dir = dirname( $file );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			return false;
		}

		$tmp = $file . '.' . random_int( 100000, 999999 ) . '.tmp';
		$fp  = @fopen( $tmp, 'wb' );
		if ( false === $fp ) {
			return false;
		}

		$ok = true;
		if ( flock( $fp, LOCK_EX ) ) {
			$ok = ( false !== fwrite( $fp, $content ) );
			fflush( $fp );
			flock( $fp, LOCK_UN );
		}
		fclose( $fp );

		if ( ! $ok || ! @rename( $tmp, $file ) ) {
			@unlink( $tmp );
			return false;
		}

		@chmod( $file, 0644 );

		// The file is included/required on every request (runtime config, both
		// drop-ins). If opcache is enabled, a replaced file would keep serving
		// the stale opcode until its next invalidation — force it.
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $file, true );
		}

		return true;
	}
}
