<?php
/**
 * Object-cache drop-in generator (SPEC §8).
 *
 * Writes wp-content/object-cache.php from the source template, baking in the
 * backend + connection details. Never overwrites a non-Cosmic drop-in without
 * explicit confirmation.
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache\ObjectCache;

final class DropInGenerator {

	/**
	 * Generate (or remove when disabled) the object-cache drop-in.
	 *
	 * @param array<string,mixed> $settings
	 * @param bool                $force Overwrite a non-Cosmic drop-in.
	 * @return bool
	 */
	public static function generate( array $settings, bool $force = false ): bool {
		// Single-site only: refuse to write the object-cache drop-in on
		// multisite (blog_id is never initialized pre-boot, so every blog
		// would share site 1's keys, leaking alloptions across sites).
		if ( ! \CosmicCache\Config::multisiteAllowed() ) {
			return self::remove_if_cosmic();
		}

		if ( empty( $settings['object_cache_enabled'] ) ) {
			return self::remove_if_cosmic();
		}

		$backend = (string) ( $settings['object_cache_backend'] ?? 'redis' );

		if ( 'redis' === $backend && ! class_exists( 'Redis' ) ) {
			\CosmicCache\Config::debug( 'object-cache', 'Redis backend requested but ext-redis is not installed' );
			return false;
		}
		if ( 'apcu' === $backend && ! function_exists( 'apcu_fetch' ) ) {
			\CosmicCache\Config::debug( 'object-cache', 'APCu backend requested but ext-apcu is not installed' );
			return false;
		}

		// Never clobber a non-Cosmic drop-in without explicit confirmation.
		if ( Status::dropin_exists() && ! Status::dropin_is_cosmic() && ! $force ) {
			\CosmicCache\Config::debug( 'object-cache', 'Refusing to overwrite a non-Cosmic object-cache drop-in' );
			return false;
		}

		$config = array(
			'backend' => $backend,
			'host'    => (string) ( $settings['object_cache_host'] ?? '127.0.0.1' ),
			'port'    => (int) ( $settings['object_cache_port'] ?? 6379 ),
			'timeout' => 0.2,
			'prefix'  => self::prefix( $settings ),
		);

		// Suppressed read: a missing template (incomplete upload) fails
		// cleanly instead of printing warnings.
		$template = @file_get_contents( dirname( __DIR__, 2 ) . '/dropins/object-cache.php' );
		if ( false === $template ) {
			return false;
		}

		$config_code = "\$cosmic_config = " . var_export( $config, true ) . ";\n"
			. "global \$cosmic_object_cache, \$wp_object_cache;\n"
			. "\$cosmic_object_cache = new CosmicCache_ObjectCache( \$cosmic_config );\n"
			. "\$GLOBALS['wp_object_cache'] = \$cosmic_object_cache;\n";

		$dropin = str_replace( '/* COSMIC_OC_CONFIG */', $config_code, $template );

		$target = WP_CONTENT_DIR . '/object-cache.php';
		$ok     = \CosmicCache\Config::atomicWrite( $target, $dropin );

		if ( $ok ) {
			@chmod( $target, 0644 );
			// A fresh drop-in must not read stale data left behind while it was
			// absent (e.g. deactivation → reactivation). Drop the prefix.
			self::flush_prefix( $config['prefix'], $settings );
		}

		return $ok;
	}

	/**
	 * Delete every key under the given prefix in the Redis backend.
	 *
	 * Runs with a throwaway connection so it also works when the drop-in was
	 * not loaded (e.g. right after activation re-creates it).
	 *
	 * @param string               $prefix
	 * @param array<string,mixed>  $settings
	 * @return void
	 */
	private static function flush_prefix( string $prefix, array $settings ): void {
		if ( ! class_exists( 'Redis' ) ) {
			return;
		}
		try {
			$redis = new \Redis();
			if ( ! $redis->connect( (string) ( $settings['object_cache_host'] ?? '127.0.0.1' ), (int) ( $settings['object_cache_port'] ?? 6379 ), 1.0 ) ) {
				return;
			}
$it    = null;
		$keys  = array();
		do {
			$batch = $redis->scan( $it, $prefix . '*', 500 );
			if ( false === $batch ) {
				break;
			}
			if ( $batch ) {
				$keys = array_merge( $keys, $batch );
			}
		} while ( 0 !== (int) $it );

		if ( $keys ) {
			$redis->del( $keys );
		}
		$redis->close();
		} catch ( \Exception $e ) {
			\CosmicCache\Config::debug( 'object-cache', 'Could not flush prefix on drop-in regeneration: ' . $e->getMessage() );
		}
	}

	/**
	 * Remove the object-cache drop-in only when Cosmic generated it.
	 *
	 * @return bool
	 */
	public static function remove_if_cosmic(): bool {
		$target = WP_CONTENT_DIR . '/object-cache.php';
		if ( ! is_file( $target ) ) {
			return true;
		}
		if ( ! Status::dropin_is_cosmic() ) {
			return false;
		}
		return @unlink( $target );
	}

	/**
	 * Stable prefix: setting override, else derived from DB_NAME.
	 *
	 * @param array<string,mixed> $settings
	 * @return string
	 */
	private static function prefix( array $settings ): string {
		$prefix = trim( (string) ( $settings['object_cache_prefix'] ?? '' ) );
		if ( '' !== $prefix ) {
			return 'cosmic_' . $prefix;
		}

		$db = defined( 'DB_NAME' ) ? DB_NAME : 'wp';
		return 'cosmic_' . $db;
	}
}
