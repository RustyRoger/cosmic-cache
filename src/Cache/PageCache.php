<?php
/**
 * Capture path: buffer a rendered page, run the optimization pipeline, and
 * store it to disk (SPEC §7.1).
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache\Cache;

final class PageCache {

	/**
	 * Begin buffering on template_redirect when the request is cacheable.
	 *
	 * @return void
	 */
	public function maybe_start_buffer(): void {
		try {
			$this->start_buffer();
		} catch ( \Throwable $e ) {
			// Fail open: a throwing settings filter / env parse must never 500
			// the front end — serve WP normally instead.
			return;
		}
	}

	/**
	 * Wrap the buffer setup so maybe_start_buffer() can fail open.
	 *
	 * @return void
	 */
	private function start_buffer(): void {
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		$settings = \CosmicCache\Config::runtime();

		if ( ! \CosmicCache\Config::multisiteAllowed() || empty( $settings['enabled'] ) ) {
			return;
		}

		$env = Vary::requestEnv();

		if ( Bypass::shouldBypassHotPath( $settings, $env ) ) {
			return;
		}
		if ( Bypass::shouldBypassCapture( $settings ) ) {
			return;
		}

		ob_start( array( $this, 'capture' ), 0, PHP_OUTPUT_HANDLER_STDFLAGS );
	}

	/**
	 * Output-buffer callback: optimize + store, then return HTML unchanged.
	 *
	 * Never throws: any unexpected error in the pipeline or storage degrades
	 * to returning the unmodified HTML (fail-open), so a broken filter or a
	 * full disk can never 500 a rendered page.
	 *
	 * @param string $html
	 * @return string
	 */
	public function capture( string $html ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}

		try {
			$settings = \CosmicCache\Config::runtime();
			$env      = Vary::requestEnv();

			// Resolve the blog segment exactly like the hot path so stored and
			// served files match (blogId() returns 0 on single-site → no segment).
			$file = Vary::resolveFile( $settings, $env, Vary::blogId( $settings, (string) $env['host'] ) );

			if ( '' === $file ) {
				return $html;
			}

			$html = $this->optimize( $html, $settings );

			// Preloader requests are stored but must not schedule a new crawl.
			$is_preload = isset( $_SERVER['HTTP_X_COSMIC_PRELOAD'] ) && '1' === $_SERVER['HTTP_X_COSMIC_PRELOAD'];

			$stored = Storage\Disk::store( $file, $html, $settings );

			if ( $stored ) {
				if ( ! $is_preload ) {
					do_action( 'cosmic_cache_stored', $file, $settings );
				}
				// A successful store resets the failure counter.
				if ( function_exists( 'delete_option' )
					&& false !== get_option( 'cosmic_cache_write_failures', false ) ) {
					delete_option( 'cosmic_cache_write_failures' );
				}
			} elseif ( function_exists( 'set_transient' ) ) {
				// Count write failures so an admin Notice can surface them.
				// Cap the counter so a burnt cache directory cannot saturate
				// the options table with an ever-growing value.
				$failures = (int) get_option( 'cosmic_cache_write_failures', 0 );
				update_option( 'cosmic_cache_write_failures', min( 1000, $failures + 1 ), false );
			}
		} catch ( \Throwable $e ) {
			// Fail open: return the original page, uncached.
		}

		return $html;
	}

	/**
	 * Run the optimization pipeline in fixed order (SPEC §9).
	 *
	 * @param string              $html
	 * @param array<string,mixed> $settings
	 * @return string
	 */
	public function optimize( string $html, array $settings ): string {
		$html = apply_filters( 'cosmic_cache_html_stage_cleanup', $html, $settings );
		$html = apply_filters( 'cosmic_cache_html_stage_combine', $html, $settings );
		$html = apply_filters( 'cosmic_cache_html_stage_criticalcss', $html, $settings );
		$html = apply_filters( 'cosmic_cache_html_stage_minify', $html, $settings );
		$html = apply_filters( 'cosmic_cache_html_stage_defer', $html, $settings );
		$html = apply_filters( 'cosmic_cache_html_stage_delayjs', $html, $settings );
		$html = apply_filters( 'cosmic_cache_html_stage_lazyload', $html, $settings );
		$html = apply_filters( 'cosmic_cache_html_stage_cdn', $html, $settings );

		return apply_filters( 'cosmic_cache_html', $html, $settings );
	}
}
