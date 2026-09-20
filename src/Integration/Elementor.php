<?php
/**
 * Elementor integration (SPEC §13).
 *
 * - Full cache purge on editor save.
 * - Bypass caching for ?elementor-preview= (handled by the query whitelist on
 *   the hot path, and reinforced on the capture path below).
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache\Integration;

use CosmicCache\Optimization\CriticalCSS;

final class Elementor {

	public function __construct() {
		if ( ! did_action( 'elementor/loaded' ) && ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}

		add_action( 'elementor/editor/after_save', array( $this, 'purge_all' ) );
		add_filter( 'cosmic_cache_is_cacheable', array( $this, 'is_cacheable' ), 10, 2 );
	}

	/**
	 * Full purge after Elementor regenerates styles. Elementor rewrites its
	 * CSS files, so the pasted critical CSS snippet may be stale too.
	 *
	 * @return void
	 */
	public function purge_all(): void {
		\CosmicCache\Plugin::instance()->purger()->purge_all();
		CriticalCSS::markStale();
	}

	/**
	 * Never store Elementor previews.
	 *
	 * @param bool                $cacheable
	 * @param array<string,mixed> $settings
	 * @return bool
	 */
	public function is_cacheable( bool $cacheable, array $settings ): bool {
		if ( isset( $_GET['elementor-preview'] ) ) {
			return false;
		}
		return $cacheable;
	}
}
