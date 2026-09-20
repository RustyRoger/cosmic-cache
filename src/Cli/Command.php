<?php
/**
 * WP-CLI commands (SPEC §12).
 *
 *   wp cosmic status
 *   wp cosmic purge
 *   wp cosmic purge <url>
 *   wp cosmic preload
 *   wp cosmic critical-css [--url=<url>] [--apply] [--force]
 *   wp cosmic debug on|off
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache\Cli;

use CosmicCache\Config;

/**
 * WP-CLI handler.
 */
class Command {

	/**
	 * Show settings, drop-in health and cache stats.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cosmic status
	 */
	public function status( $args, $assoc_args ) {
		$this->assert_permission();

		$settings = Config::runtime();
		$purger   = \CosmicCache\Plugin::instance()->purger();

		\WP_CLI::log( 'Cosmic Cache ' . Config::version() );
		\WP_CLI::log( 'enabled: ' . ( $settings['enabled'] ? 'yes' : 'no' ) );
		\WP_CLI::log( 'ttl: ' . (int) $settings['ttl'] . 's' );

		$advanced = WP_CONTENT_DIR . '/advanced-cache.php';
		\WP_CLI::log( 'advanced-cache drop-in: ' . ( is_file( $advanced ) ? 'present' : 'MISSING' ) );
		\WP_CLI::log( 'WP_CACHE: ' . ( ( defined( 'WP_CACHE' ) && WP_CACHE ) ? 'true' : 'false' ) );

		$oc = \CosmicCache\ObjectCache\Status::active_backend();
		\WP_CLI::log( 'object-cache drop-in: ' . $oc['type'] . ( $oc['version'] ? ' (' . $oc['version'] . ')' : '' ) );

		$root = \CosmicCache\Cache\Vary::cacheRoot();
		$files = 0;
		$size  = 0;
		if ( is_dir( $root ) ) {
			$it = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $it as $file ) {
				if ( $file->isFile() && 1 === preg_match( '/\.html$/', $file->getFilename() ) ) {
					++$files;
					$size += $file->getSize();
				}
			}
		}
		\WP_CLI::log( 'cache files: ' . $files . ' (' . size_format( $size ) . ')' );
	}

	/**
	 * Purge all cached pages.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cosmic purge
	 */
	public function purge( $args, $assoc_args ) {
		$this->assert_permission();

		$purger = \CosmicCache\Plugin::instance()->purger();

		if ( isset( $args[0] ) ) {
			$count = $purger->purge_urls( array( $args[0] ) );
			\WP_CLI::success( sprintf( 'Purged URL (removed %d file(s)).', $count ) );
			return;
		}

		$this->require_confirmation( $assoc_args, 'purge all cached pages' );
		$count = $purger->purge_all();
		\WP_CLI::success( sprintf( 'Purged all cache (removed %d file(s)).', $count ) );
	}

	/**
	 * Run the preloader now.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cosmic preload
	 */
	public function preload( $args, $assoc_args ) {
		$this->assert_permission();

		$this->require_confirmation( $assoc_args, 'start a full preload run' );

		$preloader = new \CosmicCache\Cache\Preloader( Config::runtime() );
		list( $requested, $stored ) = $preloader->run();
		\WP_CLI::success( sprintf( 'Preload finished: %d requested, %d cached.', $requested, $stored ) );
	}

	/**
	 * Generate critical CSS for one or more URLs (heuristic, no browser).
	 *
	 * Saves a draft for review; pass --apply to make it live (backs the
	 * current snippet up for revert). Generation only ever runs when you
	 * invoke it — never automatically.
	 *
	 * ## OPTIONS
	 *
	 * [--url=<url>]
	 * : Page to analyze. Repeatable; defaults to the homepage. Max 5.
	 *
	 * [--apply]
	 * : Apply the draft as the live snippet after generation.
	 *
	 * [--force]
	 * : Apply even when the draft exceeds the inline size cap.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cosmic critical-css
	 *     wp cosmic critical-css --url=https://example.com/ --url=https://example.com/shop/ --apply
	 */
	public function critical_css( $args, $assoc_args ) {
		$this->assert_permission();

		$urls = array();
		if ( ! empty( $assoc_args['url'] ) ) {
			$urls = is_array( $assoc_args['url'] ) ? $assoc_args['url'] : array( $assoc_args['url'] );
		}
		if ( ! $urls && function_exists( 'home_url' ) ) {
			$urls = array( home_url( '/' ) );
		}
		if ( ! $urls ) {
			\WP_CLI::error( 'No URLs to analyze.' );
		}

		$generator = new \CosmicCache\Optimization\CriticalGenerator();
		$result    = $generator->run( $urls, Config::runtime() );

		if ( isset( $result['error'] ) ) {
			\WP_CLI::error( (string) $result['error'] );
		}

		foreach ( (array) ( $result['warnings'] ?? array() ) as $warning ) {
			\WP_CLI::warning( (string) $warning );
		}

		\WP_CLI::log( sprintf(
			'Draft: %d rules kept, %d dropped, %d bytes (%.1f%% of analyzed CSS), %d URL(s), %s s.',
			(int) $result['kept'],
			(int) $result['dropped'],
			(int) $result['bytes'],
			(int) $result['total_bytes'] > 0 ? 100 * (int) $result['kept'] / max( 1, (int) $result['kept'] + (int) $result['dropped'] ) : 0,
			count( (array) $result['urls'] ),
			(string) ( $result['elapsed'] ?? '?' )
		) );

		\CosmicCache\Optimization\CriticalCSS::saveDraft( $result );

		if ( empty( $assoc_args['apply'] ) ) {
			\WP_CLI::success( 'Draft saved for review in Optimization > Critical CSS status.' );
			return;
		}

		if ( ! empty( $result['over_cap'] ) && empty( $assoc_args['force'] ) ) {
			\WP_CLI::error( 'Draft exceeds the inline size cap. Re-run with --force to apply anyway.' );
		}

		$this->require_confirmation( $assoc_args, 'apply the generated critical CSS' );

		if ( \CosmicCache\Optimization\CriticalCSS::applyDraft( Config::load() ) ) {
			\WP_CLI::success( 'Critical CSS applied (previous snippet kept for revert).' );
		} else {
			\WP_CLI::error( 'Could not apply the draft.' );
		}
	}

	/**
	 * Toggle debug logging (COSMIC_CACHE_DEBUG still wins).
	 *
	 * ## EXAMPLES
	 *
	 *     wp cosmic debug on
	 *     wp cosmic debug off
	 */
	public function debug( $args, $assoc_args ) {
		$this->assert_permission();

		$state = isset( $args[0] ) ? strtolower( $args[0] ) : '';
		if ( ! in_array( $state, array( 'on', 'off' ), true ) ) {
			\WP_CLI::error( 'Usage: wp cosmic debug on|off' );
		}

		$settings       = Config::load();
		$settings['debug'] = ( 'on' === $state );
		Config::save( $settings );

		\WP_CLI::success( 'Debug logging ' . ( $settings['debug'] ? 'enabled' : 'disabled' ) . '.' );
	}

	/**
	 * Guard every command behind the manage_options capability (SPEC §15.3).
	 *
	 * Only enforced when a user context exists: server-side runs (wp-cli as
	 * root / cron) have no authenticated user and are treated as privileged.
	 *
	 * @return void
	 */
	private function assert_permission(): void {
		if ( ! function_exists( 'current_user_can' ) || ! function_exists( 'get_current_user_id' ) ) {
			return;
		}
		if ( 0 === (int) get_current_user_id() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			\WP_CLI::error( 'You do not have permission to run this command.' );
		}
	}

	/**
	 * Confirm a destructive/heavy command unless --yes is given.
	 *
	 * Runs free (no prompt) when there is no authenticated user: wp-cli as
	 * root, cron and the integration tests are treated as privileged.
	 *
	 * @param array<string,mixed> $assoc_args
	 * @param string              $what Human-readable action for the prompt.
	 * @return void
	 */
	private function require_confirmation( array $assoc_args, string $what ): void {
		if ( ! empty( $assoc_args['yes'] ) ) {
			return;
		}
		if ( ! function_exists( 'current_user_can' ) || ! function_exists( 'get_current_user_id' ) ) {
			return;
		}
		if ( 0 === (int) get_current_user_id() ) {
			return;
		}
		\WP_CLI::confirm( sprintf( 'Really %s?', $what ) );
	}
}
