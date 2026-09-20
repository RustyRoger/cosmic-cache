<?php
/**
 * Authenticated AJAX purge handlers (SPEC §11).
 *
 * Actions: cosmic_purge_all, cosmic_purge_urls, cosmic_preload.
 * Guarded by capability manage_options + nonce cosmic_cache_admin.
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache\Admin;

final class PurgeController {

	public function __construct() {
		add_action( 'wp_ajax_cosmic_purge_all', array( $this, 'purge_all' ) );
		add_action( 'wp_ajax_cosmic_purge_urls', array( $this, 'purge_urls' ) );
		add_action( 'wp_ajax_cosmic_preload', array( $this, 'preload' ) );
		add_action( 'wp_ajax_cosmic_critical_generate', array( $this, 'critical_generate' ) );
		add_action( 'wp_ajax_cosmic_critical_apply', array( $this, 'critical_apply' ) );
		add_action( 'wp_ajax_cosmic_critical_discard', array( $this, 'critical_discard' ) );
		add_action( 'wp_ajax_cosmic_critical_revert', array( $this, 'critical_revert' ) );
	}

	/**
	 * Verify capability + nonce; wp_send_json on failure.
	 *
	 * @return void
	 */
	private function authorize(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'cosmic-cache' ) ), 403 );
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_key( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'cosmic_cache_admin' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'cosmic-cache' ) ), 403 );
		}
	}

	public function purge_all(): void {
		$this->authorize();
		$count = \CosmicCache\Plugin::instance()->purger()->purge_all();
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: files removed. */
					__( 'Cache purged (%d files removed).', 'cosmic-cache' ),
					$count
				),
			)
		);
	}

	public function purge_urls(): void {
		$this->authorize();

		$raw = isset( $_POST['urls'] ) ? (string) wp_unslash( $_POST['urls'] ) : '';
		$urls = array_values( array_filter( array_map( 'trim', preg_split( '/\R|,/', $raw ) ?: array() ) ) );

		if ( ! $urls ) {
			wp_send_json_error( array( 'message' => __( 'No URLs provided.', 'cosmic-cache' ) ), 400 );
		}

		$count = \CosmicCache\Plugin::instance()->purger()->purge_urls( $urls );
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: URLs purged. */
					__( 'Purged %d URLs.', 'cosmic-cache' ),
					$count
				),
			)
		);
	}

	public function preload(): void {
		$this->authorize();

		$settings  = \CosmicCache\Config::runtime();
		$preloader = new \CosmicCache\Cache\Preloader( $settings );
		list( $requested, $stored ) = $preloader->run();

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: requested, 2: stored. */
					__( 'Preload finished: %1$d requested, %2$d cached.', 'cosmic-cache' ),
					$requested,
					$stored
				),
			)
		);
	}

	/**
	 * Generate a critical-CSS draft for review (never applies it).
	 *
	 * v1 analyzes the homepage only; the generator supports more URLs via
	 * WP-CLI. May take several seconds — admin/CLI context only.
	 *
	 * @return void
	 */
	public function critical_generate(): void {
		$this->authorize();

		if ( ! class_exists( 'DOMDocument' ) ) {
			wp_send_json_error( array( 'message' => __( 'Critical CSS generation needs the PHP DOM extension (ext-dom), which is not available on this server.', 'cosmic-cache' ) ), 500 );
		}

		$urls = function_exists( 'home_url' ) ? array( home_url( '/' ) ) : array();
		$generator = new \CosmicCache\Optimization\CriticalGenerator();
		$result    = $generator->run( $urls, \CosmicCache\Config::runtime() );

		if ( isset( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => (string) $result['error'] ), 500 );
		}

		\CosmicCache\Optimization\CriticalCSS::saveDraft( $result );

		wp_send_json_success( $this->draft_payload( $result ) );
	}

	/**
	 * Apply the reviewed draft as the live snippet (backs up for revert).
	 *
	 * @return void
	 */
	public function critical_apply(): void {
		$this->authorize();

		$draft = \CosmicCache\Optimization\CriticalCSS::draft();
		if ( ! $draft ) {
			wp_send_json_error( array( 'message' => __( 'No generated draft to apply.', 'cosmic-cache' ) ), 400 );
		}

		$force = ! empty( $_POST['force'] );
		if ( ! empty( $draft['over_cap'] ) && ! $force ) {
			wp_send_json_error(
				array(
					'message'  => __( 'Draft exceeds the inline size cap. Confirm to apply it anyway.', 'cosmic-cache' ),
					'over_cap' => true,
				),
				409
			);
		}

		if ( \CosmicCache\Optimization\CriticalCSS::applyDraft( \CosmicCache\Config::load() ) ) {
			wp_send_json_success( array( 'message' => __( 'Critical CSS applied (previous snippet kept for revert).', 'cosmic-cache' ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'Could not apply the draft.', 'cosmic-cache' ) ), 500 );
	}

	/**
	 * Discard the draft without applying it.
	 *
	 * @return void
	 */
	public function critical_discard(): void {
		$this->authorize();

		\CosmicCache\Optimization\CriticalCSS::discardDraft();
		wp_send_json_success( array( 'message' => __( 'Draft discarded.', 'cosmic-cache' ) ) );
	}

	/**
	 * Restore the pre-apply snippet.
	 *
	 * @return void
	 */
	public function critical_revert(): void {
		$this->authorize();

		if ( \CosmicCache\Optimization\CriticalCSS::revert() ) {
			wp_send_json_success( array( 'message' => __( 'Critical CSS reverted to the previous snippet.', 'cosmic-cache' ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'No previous snippet to revert to.', 'cosmic-cache' ) ), 400 );
	}

	/**
	 * Review payload shared by generate (fresh draft) responses.
	 *
	 * @param array<string,mixed> $result Generator result array.
	 * @return array<string,mixed>
	 */
	private function draft_payload( array $result ): array {
		return array(
			'message'     => sprintf(
				/* translators: 1: rules kept, 2: rules dropped, 3: draft bytes. */
				__( 'Draft ready: %1$d rules kept, %2$d dropped, %3$d bytes. Review it below, then Apply or Discard.', 'cosmic-cache' ),
				(int) ( $result['kept'] ?? 0 ),
				(int) ( $result['dropped'] ?? 0 ),
				(int) ( $result['bytes'] ?? 0 )
			),
			'kept'        => (int) ( $result['kept'] ?? 0 ),
			'dropped'     => (int) ( $result['dropped'] ?? 0 ),
			'bytes'       => (int) ( $result['bytes'] ?? 0 ),
			'total_bytes' => (int) ( $result['total_bytes'] ?? 0 ),
			'urls'        => array_values( (array) ( $result['urls'] ?? array() ) ),
			'warnings'    => array_values( (array) ( $result['warnings'] ?? array() ) ),
			'over_cap'    => ! empty( $result['over_cap'] ),
		);
	}
}
