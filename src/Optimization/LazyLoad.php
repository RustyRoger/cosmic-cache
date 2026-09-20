<?php
/**
 * LazyLoad: swap images/iframes to data-cosmic-src + transparent placeholder,
 * inject an IntersectionObserver bootstrap, and wrap swaps in <noscript>
 * fallbacks so content stays visible without JS.
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache\Optimization;

final class LazyLoad {

	/**
	 * Transparent placeholder (1x1 gif).
	 */
	private const PLACEHOLDER = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';

	/**
	 * @param string              $html
	 * @param array<string,mixed> $settings
	 * @return string
	 */
	public function process( string $html, array $settings ): string {
		if ( empty( $settings['lazyload'] ) && empty( $settings['lazyload_iframes'] ) ) {
			return $html;
		}

		$do_images = ! empty( $settings['lazyload'] );
		$do_iframes = ! empty( $settings['lazyload_iframes'] );

		if ( $do_images ) {
			$html = $this->lazy_images( $html );
		}
		if ( $do_iframes ) {
			$html = $this->lazy_iframes( $html );
		}

		if ( ( $do_images || $do_iframes ) && false !== strpos( $html, 'data-cosmic-src' ) ) {
			$html = $this->inject_observer( $html );
		}

		return $html;
	}

	/**
	 * Swap lazy-loadable images.
	 *
	 * @param string $html
	 * @return string
	 */
	private function lazy_images( string $html ): string {
		return (string) preg_replace_callback(
			'#<img\b[^>]*>#i',
			static function ( $m ) {
				$tag = $m[0];

				// Skip already processed / forced eager / placeholder images.
				if ( preg_match( '/\b(data-cosmic-src|loading=["\']eager|class=["\'][^"\']*skip-lazy)/i', $tag ) ) {
					return $tag;
				}

				// Dead guard: only swap imgs that carry BOTH src and class, so
				// a bare/tracker <img> is never rewritten (audit C4).
				if ( ! preg_match( '/\bsrc\s*=/i', $tag ) || ! preg_match( '/\bclass\s*=/i', $tag ) ) {
					return $tag;
				}
				if ( false !== strpos( $tag, 'data:image' ) ) {
					return $tag;
				}

				$src = self::attr( $tag, 'src' );
				if ( '' === $src ) {
					return $tag;
				}

				$swapped = str_replace(
					array( 'src="' . $src . '"', "src='" . $src . "'" ),
					'src="' . self::PLACEHOLDER . '" data-cosmic-src="' . $src . '" loading="lazy"',
					$tag
				);

				// Move srcset into data-cosmic-srcset so the browser only
				// resolves it once in view. sizes stays on the <img>: the
				// browser needs it for correct layout before the swap.
				$srcset = self::attr( $tag, 'srcset' );
				if ( '' !== $srcset ) {
					$swapped = str_replace(
						array( 'srcset="' . $srcset . '"', "srcset='" . $srcset . "'" ),
						'data-cosmic-srcset="' . $srcset . '"',
						$swapped
					);
				}

				return $swapped . '<noscript>' . $tag . '</noscript>';
			},
			$html
		);
	}

	/**
	 * Swap lazy-loadable iframes.
	 *
	 * @param string $html
	 * @return string
	 */
	private function lazy_iframes( string $html ): string {
		return (string) preg_replace_callback(
			'#<iframe\b[^>]*>.*?</iframe>#is',
			static function ( $m ) {
				$tag = $m[0];

				if ( preg_match( '/\b(data-cosmic-src|loading=["\']eager)/i', $tag ) ) {
					return $tag;
				}

				$src = self::attr( $tag, 'src' );
				if ( '' === $src || 0 === strpos( $src, 'about:blank' ) ) {
					return $tag;
				}

				$swapped = str_replace(
					array( 'src="' . $src . '"', "src='" . $src . "'" ),
					'src="about:blank" data-cosmic-src="' . $src . '" loading="lazy"',
					$tag
				);

				return $swapped . '<noscript>' . $tag . '</noscript>';
			},
			$html
		);
	}

	/**
	 * Inject the IntersectionObserver bootstrap before </body>.
	 *
	 * @param string $html
	 * @return string
	 */
	private function inject_observer( string $html ): string {
		$script = '<script type="text/javascript">'
			. "(function(){var t=[].slice.call(document.querySelectorAll('[data-cosmic-src],[data-cosmic-srcset]'));"
			. 'if(!("IntersectionObserver"in window)){t.forEach(function(e){var s=e.getAttribute("data-cosmic-src");'
			. 'var ss=e.getAttribute("data-cosmic-srcset");if(ss){e.setAttribute("srcset",ss);}'
			. 'if(s){e.setAttribute("src",s);}});return;}'
			. 'var o=new IntersectionObserver(function(es){es.forEach(function(en){if(en.isIntersecting){'
			. 'var e=en.target,s=e.getAttribute("data-cosmic-src"),ss=e.getAttribute("data-cosmic-srcset");'
			. 'if(ss){e.setAttribute("srcset",ss);}if(s){e.setAttribute("src",s);}o.unobserve(e);}});},'
			. '{rootMargin:"200px"});t.forEach(function(e){o.observe(e);});})();'
			. '</script>';

		return ( false !== stripos( $html, '</body>' ) )
			? (string) preg_replace( '/<\/body>/i', $script . '</body>', $html, 1 )
			: $html . $script;
	}

	/**
	 * Extract an attribute value.
	 *
	 * @param string $tag
	 * @param string $name
	 * @return string
	 */
	private static function attr( string $tag, string $name ): string {
		if ( preg_match( '/\b' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/i', $tag, $m ) ) {
			return html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return '';
	}
}
