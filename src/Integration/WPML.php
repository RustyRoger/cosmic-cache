<?php
/**
 * WPML integration (SPEC §13).
 *
 * - Vary the cache key on the `lang` cookie when present (baked into config at
 *   compile time so the hot path can compute it).
 * - Purge per-language URL variants on change.
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache\Integration;

final class WPML {

	public function __construct() {
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return;
		}

		add_filter( 'cosmic_cache_settings', array( $this, 'settings' ) );
		add_action( 'wpml_pro_translation_completed', array( $this, 'purge_all' ), 10, 1 );
		add_action( 'wpml_switch_language', array( $this, 'purge_all' ), 10, 1 );
	}

	/**
	 * Add the lang cookie to the vary key.
	 *
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	public function settings( array $settings ): array {
		if ( ! in_array( 'lang', (array) $settings['cache_cookies'], true ) ) {
			$settings['cache_cookies'][] = 'lang';
		}
		return $settings;
	}

	/**
	 * Full purge is the safest per-language invalidation.
	 *
	 * @return void
	 */
	public function purge_all(): void {
		\CosmicCache\Plugin::instance()->purger()->purge_all();
	}
}
