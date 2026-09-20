<?php
/**
 * WooCommerce integration (SPEC §13).
 *
 * - Never caches cart/checkout/account pages (rely on the standard
 *   exclude_urls mechanism).
 * - Varies pages on cart contents via the `woocommerce_items_in_cart` cookie.
 * - Purges product + shop page on product save.
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache\Integration;

final class WooCommerce {

	public function __construct() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_filter( 'cosmic_cache_settings', array( $this, 'settings' ) );
		add_action( 'woocommerce_product_object_updated', array( $this, 'purge_product' ), 10, 2 );
		add_action( 'save_post_product', array( $this, 'purge_product_save' ), 10, 2 );
		add_action( 'wp_trash_post', array( $this, 'purge_product_trash' ) );
	}

	/**
	 * Add the cart cookie to the vary key + exclude checkout/account URLs.
	 *
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	public function settings( array $settings ): array {
		if ( ! in_array( 'woocommerce_items_in_cart', (array) $settings['cache_cookies'], true ) ) {
			$settings['cache_cookies'][] = 'woocommerce_items_in_cart';
		}

		$wc_paths = array( '/cart', '/checkout', '/my-account' );
		foreach ( $wc_paths as $path ) {
			$found = false;
			foreach ( (array) $settings['exclude_urls'] as $pattern ) {
				if ( false !== strpos( $pattern, $path ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$settings['exclude_urls'][] = $path;
			}
		}

		return $settings;
	}

	/**
	 * Purge product + shop page.
	 *
	 * @param int               $product_id
	 * @param \WC_Product|null  $product
	 * @return void
	 */
	public function purge_product( $product_id, $product ) {
		if ( ! $product ) {
			return;
		}
		$urls   = array();
		$urls[] = (string) get_permalink( $product_id );
		$shop   = (int) wc_get_page_id( 'shop' );
		if ( $shop ) {
			$urls[] = (string) get_permalink( $shop );
		}
		\CosmicCache\Plugin::instance()->purger()->purge_urls( $urls );
	}

	/**
	 * @param int     $post_id
	 * @param \WP_Post $post
	 * @return void
	 */
	public function purge_product_save( $post_id, $post ) {
		if ( 'publish' === get_post_status( $post_id ) ) {
			$product = wc_get_product( $post_id );
			if ( $product ) {
				$this->purge_product( $post_id, $product );
			}
		}
	}

	/**
	 * @param int $post_id
	 * @return void
	 */
	public function purge_product_trash( $post_id ) {
		if ( 'product' === get_post_type( $post_id ) ) {
			\CosmicCache\Plugin::instance()->purger()->purge_urls( array( (string) get_permalink( $post_id ) ) );
		}
	}
}
