<?php
/**
 * CriticalCSS: inline above-the-fold CSS and load the rest asynchronously.
 *
 * Experimental, off by default. When enabled and a critical CSS snippet is
 * provided, it is inlined in <head> as <style id="cosmic-critical-css"> and
 * every other stylesheet link becomes non-render-blocking: rel=preload
 * as=style with an onload swap to stylesheet plus a <noscript> fallback.
 *
 * Never touches: stylesheets already preloaded/async (onload present),
 * stylesheets with a non-"all" media attribute, and anything listed in
 * critical_css_exclude or exclude_css. Links that are excluded keep the
 * render-blocking rel=stylesheet.
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache\Optimization;

final class CriticalCSS {

	/**
	 * Option flag (timestamp) marking the pasted snippet as possibly stale.
	 * Set when the theme/plugins/customizer change; cleared when the operator
	 * saves a fresh snippet. Never disables anything by itself.
	 *
	 * @var string
	 */
	public const STALE_OPTION = 'cosmic_cache_critical_stale';

	/**
	 * Option holding snippet provenance (hash/time/theme/version) shown in
	 * the Optimization tab so the operator knows what the snippet was made for.
	 *
	 * @var string
	 */
	public const META_OPTION = 'cosmic_cache_critical_meta';

	/**
	 * Option holding a generated draft awaiting review (never live).
	 *
	 * @var string
	 */
	public const DRAFT_OPTION = 'cosmic_cache_critical_draft';

	/**
	 * Option holding the pre-apply snippet for one-click revert.
	 *
	 * @var string
	 */
	public const PREV_OPTION = 'cosmic_cache_critical_prev';

	/**
	 * Mark the pasted snippet as possibly stale (theme/plugin/customizer
	 * change). Fail-open: only sets a flag consumed by the admin notice.
	 *
	 * @return void
	 */
	public static function markStale(): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( self::STALE_OPTION, time(), false );
		}
	}

	/**
	 * Clear the stale flag (fresh snippet saved).
	 *
	 * @return void
	 */
	public static function clearStale(): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::STALE_OPTION );
		}
	}

	/**
	 * Is the pasted snippet flagged as possibly stale?
	 *
	 * @return bool
	 */
	public static function isStale(): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}
		return (bool) get_option( self::STALE_OPTION, false );
	}

	/**
	 * Record snippet provenance for the Optimization tab display.
	 *
	 * An empty snippet carries no provenance: any recorded meta is removed
	 * so the tab never shows a hash for zero bytes.
	 *
	 * @param string $snippet The saved critical CSS snippet.
	 * @return void
	 */
	public static function recordMeta( string $snippet ): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}

		if ( '' === trim( $snippet ) ) {
			if ( function_exists( 'delete_option' ) ) {
				delete_option( self::META_OPTION );
			}
			return;
		}

		$theme   = '';
		$version = '';
		if ( function_exists( 'get_stylesheet' ) ) {
			$theme = (string) get_stylesheet();
		}
		if ( function_exists( 'wp_get_theme' ) ) {
			// wp_get_theme() always returns a WP_Theme in a booted WP admin;
			// the function_exists() guard above keeps unit tests (no WP) safe.
			$version = (string) wp_get_theme()->get( 'Version' );
		}

		update_option(
			self::META_OPTION,
			array(
				'hash'    => sha1( $snippet ),
				'length'  => strlen( $snippet ),
				'time'    => time(),
				'theme'   => $theme,
				'version' => $version,
			),
			false
		);
	}

	/**
	 * Snippet provenance meta, or empty array when never recorded.
	 *
	 * @return array<string,mixed>
	 */
	public static function meta(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}
		$meta = get_option( self::META_OPTION, array() );
		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Store a generated draft for review. Drafts are never live.
	 *
	 * @param array<string,mixed> $draft Generator result array.
	 * @return void
	 */
	public static function saveDraft( array $draft ): void {
		if ( function_exists( 'update_option' ) ) {
			$draft['time'] = time();
			update_option( self::DRAFT_OPTION, $draft, false );
		}
	}

	/**
	 * Generated draft awaiting review, or empty array when none exists.
	 *
	 * @return array<string,mixed>
	 */
	public static function draft(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}
		$draft = get_option( self::DRAFT_OPTION, array() );
		return is_array( $draft ) ? $draft : array();
	}

	/**
	 * Discard the draft without applying it.
	 *
	 * @return void
	 */
	public static function discardDraft(): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::DRAFT_OPTION );
		}
	}

	/**
	 * Apply the reviewed draft as the live snippet.
	 *
	 * Backs the current snippet up for one-click revert, saves through
	 * Config::save() (sanitize + recompile), purges the page cache (new HTML
	 * embeds the snippet), then clears the stale flag and records provenance
	 * like a manual paste. Never flips `critical_css_enabled` — enabling
	 * stays the operator's explicit choice.
	 *
	 * @param array<string,mixed> $settings Current plugin settings.
	 * @return bool
	 */
	public static function applyDraft( array $settings ): bool {
		$draft = self::draft();
		$css   = trim( (string) ( $draft['css'] ?? '' ) );
		if ( '' === $css || ! function_exists( 'update_option' ) ) {
			return false;
		}

		update_option( self::PREV_OPTION, (string) ( $settings['critical_css'] ?? '' ), false );
		$settings['critical_css'] = $css;

		if ( ! \CosmicCache\Config::save( $settings ) ) {
			return false;
		}

		if ( \CosmicCache\Plugin::booted() ) {
			\CosmicCache\Plugin::instance()->purger()->purge_all();
		}

		self::clearStale();
		self::recordMeta( $css );
		self::discardDraft();
		return true;
	}

	/**
	 * Restore the pre-apply snippet (one-click revert).
	 *
	 * @return bool False when no backup exists or the save failed.
	 */
	public static function revert(): bool {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return false;
		}
		$prev = get_option( self::PREV_OPTION, null );
		if ( ! is_string( $prev ) ) {
			return false;
		}

		$settings = \CosmicCache\Config::load();
		$settings['critical_css'] = $prev;
		if ( ! \CosmicCache\Config::save( $settings ) ) {
			return false;
		}

		if ( \CosmicCache\Plugin::booted() ) {
			\CosmicCache\Plugin::instance()->purger()->purge_all();
		}

		self::clearStale();
		self::recordMeta( $prev );
		return true;
	}

	/**
	 * Is a pre-apply backup available for revert?
	 *
	 * @return bool
	 */
	public static function canRevert(): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}
		return is_string( get_option( self::PREV_OPTION, null ) );
	}

	/**
	 * `upgrader_process_complete` callback: flag stale on plugin/theme
	 * updates or installs (CSS sources may have changed).
	 *
	 * @param mixed $upgrader Unused.
	 * @param mixed $options Upgrader options array.
	 * @return void
	 */
	public static function onUpgrader( $upgrader, $options ): void {
		if ( ! is_array( $options ) ) {
			return;
		}
		$type = (string) ( $options['type'] ?? '' );
		if ( 'plugin' === $type || 'theme' === $type ) {
			self::markStale();
		}
	}

	/**
	 * @param string              $html
	 * @param array<string,mixed> $settings
	 * @return string
	 */
	public function process( string $html, array $settings ): string {
		if ( empty( $settings['critical_css_enabled'] ) || '' === trim( (string) ( $settings['critical_css'] ?? '' ) ) ) {
			return $html;
		}

		$excluded = array_merge(
			(array) ( $settings['critical_css_exclude'] ?? array() ),
			(array) ( $settings['exclude_css'] ?? array() )
		);

		if ( ! preg_match_all( '#<link\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}

		$first    = null;
		$rewrites = array();

		foreach ( $m[0] as $match ) {
			$tag   = $match[0];
			$start = (int) $match[1];

			if ( ! $this->is_stylesheet( $tag ) ) {
				continue;
			}
			if ( $this->skip( $tag, $excluded ) ) {
				continue;
			}

			$end        = $start + strlen( $tag );
			$rewrites[] = array( $start, $end, $this->async_link( $tag ) );
			$first      = ( null === $first ) ? $start : $first;
		}

		if ( ! $rewrites ) {
			return $html;
		}

		// Replace right-to-left so earlier offsets stay valid.
		foreach ( array_reverse( $rewrites ) as $r ) {
			$html = substr_replace( $html, $r[2], $r[0], $r[1] - $r[0] );
		}

		// Inline the critical CSS just before the first stylesheet.
		$style = '<style id="cosmic-critical-css">' . $settings['critical_css'] . '</style>' . "\n";

		return substr_replace( $html, $style, $first, 0 );
	}

	/**
	 * Is this <link> tag a render-blocking stylesheet we can defer?
	 *
	 * @param string   $tag
	 * @param string[] $excluded
	 * @return bool
	 */
	private function skip( string $tag, array $excluded ): bool {
		// Already async / preloaded: leave alone.
		if ( preg_match( '/\bonload\s*=/i', $tag ) || preg_match( '/\brel\s*=\s*(["\'])preload\1/i', $tag ) ) {
			return true;
		}

		// Non-"all" media is already non-render-blocking.
		$media = $this->attr( $tag, 'media' );
		if ( '' !== $media && 'all' !== strtolower( trim( $media ) ) ) {
			return true;
		}

		$url = $this->attr( $tag, 'href' );
		if ( '' === $url ) {
			return true;
		}

		foreach ( $excluded as $pattern ) {
			if ( \CosmicCache\Cache\Bypass::matches( (string) $pattern, $tag )
				|| \CosmicCache\Cache\Bypass::matches( (string) $pattern, $url ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert a render-blocking stylesheet link into a non-blocking preload
	 * with an onload swap and a <noscript> fallback that keeps it enabled.
	 *
	 * @param string $tag
	 * @return string
	 */
	private function async_link( string $tag ): string {
		$attrs   = $this->attr( $tag, 'rel' );
		$stripped = '' === $attrs ? $tag : (string) preg_replace( '/\brel\s*=\s*(["\'])(.*?)\1/i', '', $tag, 1 );

		$preload = (string) preg_replace(
			'/^<link\b/i',
			'<link rel="preload" as="style" onload="this.onload=null;this.rel=\'stylesheet\'"',
			$stripped,
			1
		);

		return $preload . '<noscript>' . $tag . '</noscript>';
	}

	/**
	 * Does this <link> tag reference a stylesheet?
	 *
	 * @param string $tag
	 * @return bool
	 */
	private function is_stylesheet( string $tag ): bool {
		if ( preg_match( '/\brel\s*=\s*(["\'])(.*?)\1/i', $tag, $m ) ) {
			return false !== stripos( $m[2], 'stylesheet' );
		}
		return false;
	}

	/**
	 * Extract an attribute value.
	 *
	 * @param string $tag
	 * @param string $name
	 * @return string
	 */
	private function attr( string $tag, string $name ): string {
		if ( preg_match( '/\b' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/i', $tag, $m ) ) {
			return html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return '';
	}
}
