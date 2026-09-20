<?php
/**
 * Admin notices (SPEC §11).
 *
 * Warnings for: missing advanced-cache drop-in, unwritable cache dir,
 * PHP < 8.0, unavailable object-cache backend, non-Cosmic object-cache
 * drop-in detected, write failures.
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache\Admin;

use CosmicCache\Config;
use CosmicCache\Optimization\CriticalCSS;

final class Notice {

	public function __construct() {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Render active notices.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Activation soft-fail notice.
		$notice = get_transient( 'cosmic_cache_notice' );
		if ( $notice ) {
			delete_transient( 'cosmic_cache_notice' );
			$this->echo_warning( $this->message_for( (string) $notice ) );
		}

		// PHP version. Runtime check: PHPStan knows the *analysis* PHP, not the
		// WordPress host's — keep the guard for real-world installs.
		if ( version_compare( PHP_VERSION, '8.0', '<' ) ) { // @phpstan-ignore if.alwaysFalse
			$this->echo_warning( __( 'Cosmic Cache requires PHP 8.0 or newer.', 'cosmic-cache' ) );
		}

		// Multisite is single-site only for now.
		if ( ! \CosmicCache\Config::multisiteAllowed() ) {
			$this->echo_warning( __( 'Cosmic Cache does not yet support WordPress multisite. Caching is disabled on this network; define <code>COSMIC_CACHE_ALLOW_MULTISITE</code> to opt in (unsupported).', 'cosmic-cache' ) );
		}

		// Advanced-cache drop-in missing.
		$advanced = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( ! is_file( $advanced ) ) {
			$this->echo_warning( __( 'Cosmic Cache is active but the advanced-cache drop-in is missing. Deactivate and reactivate the plugin.', 'cosmic-cache' ) );
		} elseif ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
			$this->echo_warning( __( 'Cosmic Cache needs <code>WP_CACHE</code> defined as true in wp-config.php to serve the page cache.', 'cosmic-cache' ) );
		}

		// Cache dir writable.
		if ( ! is_dir( Config::cacheDir() ) || ! is_writable( Config::cacheDir() ) ) {
			$this->echo_warning( sprintf(
				/* translators: %s: cache directory. */
				__( 'Cosmic Cache cache directory is not writable: %s', 'cosmic-cache' ),
				'<code>' . esc_html( Config::cacheDir() ) . '</code>'
			) );
		}

		// Object-cache backend unavailable.
		$settings = Config::load();
		if ( ! empty( $settings['object_cache_enabled'] ) ) {
			$backend = $settings['object_cache_backend'];
			$ok = ( 'redis' === $backend && class_exists( 'Redis' ) )
				|| ( 'apcu' === $backend && function_exists( 'apcu_fetch' ) );
			if ( ! $ok ) {
				$this->echo_warning( sprintf(
					/* translators: %s: backend name. */
					__( 'Cosmic Cache object cache is enabled with backend %s, but that extension is not available.', 'cosmic-cache' ),
					'<code>' . esc_html( $backend ) . '</code>'
				) );
			}
		}

		// Non-Cosmic object-cache drop-in present.
		if ( \CosmicCache\ObjectCache\Status::dropin_exists() && ! \CosmicCache\ObjectCache\Status::dropin_is_cosmic() ) {
			$this->echo_warning( __( 'A non-Cosmic object-cache drop-in is installed; Cosmic Cache will not overwrite it.', 'cosmic-cache' ) );
		}

		// Non-Cosmic advanced-cache drop-in present.
		$advanced_file = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( is_file( $advanced_file ) && false === strpos( (string) file_get_contents( $advanced_file ), 'Cosmic Cache — advanced-cache drop-in' ) ) {
			$this->echo_warning( __( 'A non-Cosmic advanced-cache drop-in is installed; Cosmic Cache will not overwrite it.', 'cosmic-cache' ) );
		}

		// Write failures.
		$failures = (int) get_option( 'cosmic_cache_write_failures', 0 );
		if ( $failures > 0 ) {
			if ( $failures >= 100 ) {
				$this->echo_error( sprintf(
					/* translators: %d: number of failures. */
					__( 'Cosmic Cache had %d cache write failures — caching is effectively broken. Check disk space and permissions in the cache directory.', 'cosmic-cache' ),
					$failures
				) );
			} else {
				$this->echo_warning( sprintf(
					/* translators: %d: number of failures. */
					__( 'Cosmic Cache had %d cache write failure(s). Check disk space and permissions. <a href="%s">Tools tab</a> for diagnostics.', 'cosmic-cache' ),
					$failures,
					esc_url( admin_url( 'options-general.php?page=cosmic-cache&tab=tools' ) )
				) );
			}
		}

		// Pasted critical CSS possibly stale (theme/plugin/customizer change).
		// Only nags when the feature is actually in use; saving a fresh
		// snippet clears the flag. Never disables anything (fail-open).
		if ( CriticalCSS::isStale()
			&& ! empty( $settings['critical_css_enabled'] )
			&& '' !== trim( (string) ( $settings['critical_css'] ?? '' ) ) ) {
			$this->echo_warning( sprintf(
				/* translators: %s: link to the Optimization tab. */
				__( 'Your theme or plugins changed — the pasted Critical CSS may be stale (suboptimal paint, no breakage). <a href="%s">Regenerate it in the Optimization tab</a>, then save to clear this notice.', 'cosmic-cache' ),
				esc_url( admin_url( 'options-general.php?page=cosmic-cache&tab=optimization' ) )
			) );
		}
	}

	/**
	 * Translate an activation notice code into a message.
	 *
	 * @param string $code
	 * @return string
	 */
	private function message_for( string $code ): string {
		switch ( $code ) {
			case 'wp_content_readonly':
				return __( 'Cosmic Cache could not write to wp-content. Cache is disabled.', 'cosmic-cache' );
			case 'wp_config_readonly':
				return __( 'Cosmic Cache could not add WP_CACHE to wp-config.php (not writable). The page cache is disabled until you add it manually.', 'cosmic-cache' );
			case 'config_write_failed':
				return __( 'Cosmic Cache could not write its config file.', 'cosmic-cache' );
			case 'advanced_cache_foreign':
				return __( 'Cosmic Cache found an existing advanced-cache.php it did not generate and left it untouched.', 'cosmic-cache' );
			case 'advanced_cache_write_failed':
				return __( 'Cosmic Cache could not write the advanced-cache.php drop-in (missing template or unwritable wp-content). Re-upload the complete plugin folder, then deactivate and reactivate.', 'cosmic-cache' );
			default:
				return __( 'Cosmic Cache needs attention.', 'cosmic-cache' );
		}
	}

	/**
	 * @param string $message
	 * @return void
	 */
	private function echo_warning( string $message ): void {
		echo '<div class="notice notice-warning"><p>Cosmic Cache: ' . wp_kses_post( $message ) . '</p></div>';
	}

	/**
	 * @param string $message
	 * @return void
	 */
	private function echo_error( string $message ): void {
		echo '<div class="notice notice-error is-dismissible"><p>Cosmic Cache: ' . wp_kses_post( $message ) . '</p></div>';
	}
}
