<?php
/**
 * Disk storage: atomic writes, precompression, TTL GC.
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache\Cache\Storage;

final class Disk {

	use \CosmicCache\AtomicWriteTrait;

	/**
	 * Store HTML atomically with precompressed variants.
	 *
	 * @param string              $file Absolute cache file path.
	 * @param string              $html
	 * @param array<string,mixed> $settings
	 * @return bool
	 */
	public static function store( string $file, string $html, array $settings ): bool {
		$dir = dirname( $file );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			return false;
		}

		if ( ! self::atomicWrite( $file, $html ) ) {
			return false;
		}

		if ( ! empty( $settings['gzip'] ) ) {
			self::atomicWrite( $file . '.gz', gzencode( $html, 6 ) );
		}

		if ( ! empty( $settings['brotli'] ) && function_exists( 'brotli_compress' ) ) {
			$mode = defined( 'BROTLI_GENERIC' ) ? BROTLI_GENERIC : 0;
			self::atomicWrite( $file . '.br', brotli_compress( $html, 6, $mode ) );
		}

		return true;
	}

	/**
	 * Delete a cache file and its precompressed variants.
	 *
	 * @param string $file
	 * @return void
	 */
	public static function delete( string $file ): void {
		foreach ( array( $file, $file . '.gz', $file . '.br' ) as $candidate ) {
			if ( is_file( $candidate ) ) {
				@unlink( $candidate );
			}
		}
	}

	/**
	 * Hard cap on cached HTML entries. Beyond this the GC evicts oldest-first.
	 */
	public const MAX_FILES = 50000;

	/**
	 * An orphaned atomic-write temp file older than this is removed.
	 */
	private const TMP_ORPHAN_AGE = 1800;

	/**
	 * Garbage-collect expired files under the cache root (SPEC §7.5), sweep
	 * orphaned temp files, and enforce the disk cap (oldest-first eviction).
	 * All three passes share a per-run budget of max_files deletions.
	 *
	 * @param array<string,mixed> $settings
	 * @param int                 $max_files Budget for one run.
	 * @param string|null         $root Optional explicit root (tests).
	 * @param int                 $cap Highest allowed cache-entry count.
	 * @return int Number of files deleted.
	 */
	public static function gc( array $settings, int $max_files = 1000, ?string $root = null, int $cap = self::MAX_FILES ): int {
		$root = $root ?? \CosmicCache\Cache\Vary::cacheRoot();
		if ( '' === $root || ! is_dir( $root ) ) {
			return 0;
		}

		$now     = time();
		$deleted = 0;
		$ttl     = (int) $settings['ttl'];

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( $deleted >= $max_files ) {
				break;
			}
			if ( ! $file->isFile() ) {
				continue;
			}
			$name = $file->getFilename();
			$path = $file->getPathname();

			// Orphaned temp files from interrupted writes.
			if ( '.tmp' === substr( $name, -4 ) ) {
				if ( ( $now - (int) $file->getMTime() ) > self::TMP_ORPHAN_AGE ) {
					@unlink( $path );
					++$deleted;
				}
				continue;
			}

			// TTL expiry on cache entries (html only; variants share the html
			// mtime they were written from, so deleting the html clears them).
			if ( $ttl > 0 && '.html' === substr( $name, -5 ) && ( $now - (int) $file->getMTime() ) > $ttl ) {
				self::delete( $path );
				++$deleted;
			}
		}

		// Disk cap: evict the oldest entries first when over the cap, bounded
		// by the remaining per-run budget.
		if ( $deleted < $max_files ) {
			foreach ( self::over_cap_entries( $root, $cap ) as $path ) {
				if ( $deleted >= $max_files ) {
					break;
				}
				self::delete( $path );
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * The oldest cache entries that must be evicted to get back under $cap,
	 * or an empty array when already at or below the cap.
	 *
	 * @param string $root
	 * @param int    $cap
	 * @return array<int,string> File paths, oldest first.
	 */
	private static function over_cap_entries( string $root, int $cap ): array {
		$entries = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$name = $file->getFilename();
			if ( '.html' !== substr( $name, -5 ) || 'config.php' === $name || 'debug.log' === $name ) {
				continue;
			}
			$entries[] = array( (int) $file->getMTime(), $file->getPathname() );
		}

		if ( count( $entries ) <= $cap ) {
			return array();
		}

		usort(
			$entries,
			static function ( $a, $b ) {
				return $a[0] <=> $b[0];
			}
		);

		$excess = count( $entries ) - $cap;
		$out    = array();
		for ( $i = 0; $i < $excess; $i++ ) {
			$out[] = $entries[ $i ][1];
		}
		return $out;
	}
}
