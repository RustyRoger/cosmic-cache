<?php
/**
 * DeferJS: add defer/async to eligible scripts, defer non-essential inline
 * scripts to DOMContentLoaded. Never touches scripts matching defer_exclude.
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache\Optimization;

final class DeferJS {

	/**
	 * @param string              $html
	 * @param array<string,mixed> $settings
	 * @return string
	 */
	public function process( string $html, array $settings ): string {
		if ( empty( $settings['defer_js'] ) ) {
			return $html;
		}

		$excluded = (array) ( $settings['defer_exclude'] ?? array() );

		return (string) preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script>#is',
			static function ( $m ) use ( $excluded ) {
				$attrs = $m[1];
				$body  = $m[2];

				// Never defer JSON-LD / JSON / template data scripts: wrapping
				// them in JS destroys the structured data.
				if ( preg_match( '/\btype\s*=/i', $attrs ) && ! preg_match( '/\btype\s*=\s*(["\']?)(?:text\/)?(?:javascript|ecmascript)\1/i', $attrs ) ) {
					return $m[0];
				}

				// External script.
				if ( preg_match( '/\bsrc\s*=/i', $attrs ) ) {
					if ( self::excluded( $attrs, $excluded ) || preg_match( '/\b(defer|async)\b/i', $attrs ) ) {
						return $m[0];
					}
					return '<script' . $attrs . ' defer></script>';
				}

				// Inline script.
				if ( self::excluded( $attrs, $excluded ) || self::already_deferred( $body ) || '' === trim( $body ) ) {
					return $m[0];
				}

				$wrapped = "document.addEventListener('DOMContentLoaded',function(){/*<![CDATA[*/" . $body . "/*]]>*/});";
				return '<script' . $attrs . '>' . $wrapped . '</script>';
			},
			$html
		);
	}

	/**
	 * Does the tag match an exclusion pattern (src URL or attributes)?
	 *
	 * @param string   $attrs
	 * @param string[] $excluded
	 * @return bool
	 */
	private static function excluded( string $attrs, array $excluded ): bool {
		foreach ( $excluded as $pattern ) {
			if ( \CosmicCache\Cache\Bypass::matches( (string) $pattern, $attrs ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Skip inline scripts that already defer themselves.
	 *
	 * @param string $body
	 * @return bool
	 */
	private static function already_deferred( string $body ): bool {
		foreach ( array( 'DOMContentLoaded', 'jQuery(document).ready', 'jQuery(function', '$(function', 'window.addEventListener(\'load\'', 'window.onload' ) as $needle ) {
			if ( false !== strpos( $body, $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
