<?php
/**
 * Cleanup: emoji removal, DNS-prefetch/preconnect, query-string strip.
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache\Optimization;

final class Cleanup {

	/**
	 * @param string              $html
	 * @param array<string,mixed> $settings
	 * @return string
	 */
	public function process( string $html, array $settings ): string {
		if ( ! empty( $settings['remove_emoji'] ) ) {
			$html = $this->remove_emoji( $html );
		}

		if ( ! empty( $settings['dns_prefetch'] ) ) {
			$html = $this->dns_prefetch( $html, (array) $settings['dns_prefetch'] );
		}

		if ( ! empty( $settings['remove_query_strings'] ) ) {
			$html = $this->remove_query_strings( $html );
		}

		return $html;
	}

	/**
	 * Strip the WP emoji script + style.
	 *
	 * @param string $html
	 * @return string
	 */
	private function remove_emoji( string $html ): string {
		$html = preg_replace(
			'#<script[^>]+src=[^>]*wp-emoji-release[^>]*>\s*</script>#is',
			'',
			$html
		) ?? $html;

		$html = preg_replace(
			'#<link[^>]+rel=["\']?stylesheet["\']?[^>]*wp-emoji-styles[^>]*/?>#is',
			'',
			$html
		) ?? $html;

		$html = preg_replace(
			'#<style[^>]*id=["\']wp-emoji-styles["\'][^>]*>.*?</style>#is',
			'',
			$html
		) ?? $html;

		return $html;
	}

	/**
	 * Inject dns-prefetch / preconnect links before </head>.
	 *
	 * @param string   $html
	 * @param string[] $hosts
	 * @return string
	 */
	private function dns_prefetch( string $html, array $hosts ): string {
		$links = '';
		foreach ( array_unique( $hosts ) as $host ) {
			$host = esc_url( trim( $host ) );
			if ( '' === $host ) {
				continue;
			}
			$links .= '<link rel="dns-prefetch" href="' . esc_attr( $host ) . '">' . "\n";
			$links .= '<link rel="preconnect" href="' . esc_attr( $host ) . '">' . "\n";
		}

		if ( '' === $links ) {
			return $html;
		}

		return ( false !== stripos( $html, '</head>' ) )
			? preg_replace( '/<\/head>/i', $links . '</head>', $html, 1 ) ?? $html
			: $html;
	}

	/**
	 * Strip the WP assign_files ver query string from static asset URLs.
	 *
	 * Handles single- or double-quoted attribute values and preserves any
	 * query params that follow the removed ver (e.g. `?ver=1&x=2` becomes
	 * `?x=2`), instead of swallowing the rest of the attribute.
	 *
	 * @param string $html
	 * @return string
	 */
	private function remove_query_strings( string $html ): string {
		return preg_replace_callback(
			'#<(script|link)\b[^>]*?\b(src|href)\s*=\s*(["\'])(.*?)\3#i',
			static function ( $m ) {
				if ( ! preg_match( '/[?&]ver=/i', $m[4] ) ) {
					return $m[0];
				}

				$value = preg_replace( '/[?&]ver=[^&"\']*/i', '', $m[4] ) ?? $m[4];
				// A leading `?ver` param leaves the next one glued on with `&`.
				$value = preg_replace( '/^([^?]*?)&/', '$1?', $value ) ?? $value;

				// Drop the original value AND its closing quote; keep the quotes
				// consistent for single- or double-quoted attributes.
				return substr( $m[0], 0, -( strlen( $m[4] ) + strlen( $m[3] ) ) ) . $value . $m[3];
			},
			$html
		) ?? $html;
	}
}
