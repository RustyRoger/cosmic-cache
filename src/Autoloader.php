<?php
/**
 * PSR-4 autoloader for the CosmicCache\ namespace.
 *
 * Maps CosmicCache\<Class> to src/<Class>.php. Dependency-free and
 * deliberately simple: the plugin ships with zero runtime composer
 * dependencies.
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache;

final class Autoloader {

	/**
	 * Register the autoloader for the given source directory.
	 *
	 * @param string $base_dir Absolute path to src/.
	 */
	public static function register( string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class ) use ( $base_dir ): void {
				$prefix = 'CosmicCache\\';
				if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
					return;
				}

				$relative = str_replace( '\\', DIRECTORY_SEPARATOR, substr( $class, strlen( $prefix ) ) );
				$file     = rtrim( $base_dir, '/\\' ) . DIRECTORY_SEPARATOR . $relative . '.php';

				if ( is_file( $file ) ) {
					require $file;
				}
			}
		);
	}
}
