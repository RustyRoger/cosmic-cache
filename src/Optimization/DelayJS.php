<?php
/**
 * DelayJS: delay non-critical scripts until the visitor interacts.
 *
 * Experimental, off by default. External scripts get their type rewritten to
 * "cosmiclazyload" so they are not executed during initial rendering; a tiny
 * injected loader restores and executes them on the first interaction event
 * (scroll/mousedown/touchstart/keydown) or after a short timeout fallback.
 *
 * Never touches: JSON-LD/JSON data scripts, scripts already async/defer,
 * inline scripts that already wait for DOMContentLoaded, and anything listed
 * in delay_js_exclude or defer_exclude.
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache\Optimization;

final class DelayJS {

	/**
	 * @var string Script type used to park a delayed script.
	 */
	private const DELAYED_TYPE = 'cosmiclazyload';

	/**
	 * @param string              $html
	 * @param array<string,mixed> $settings
	 * @return string
	 */
	public function process( string $html, array $settings ): string {
		if ( empty( $settings['delay_js'] ) ) {
			return $html;
		}

		$excluded = array_merge(
			(array) ( $settings['delay_js_exclude'] ?? array() ),
			(array) ( $settings['defer_exclude'] ?? array() )
		);

		$rewritten = (string) preg_replace_callback(
			'#<script\b([^>]*)>\s*</script>#is',
			static function ( $m ) use ( $excluded ) {
				$attrs = $m[1];
				$open  = '<script' . $attrs . '>';

				// Only external scripts (with a src) are delayed; inline
				// scripts are left to the classic defer pipeline.
				if ( ! preg_match( '/\bsrc\s*=/i', $attrs ) ) {
					return $m[0];
				}

				// Never touch JSON-LD / typed data scripts.
				if ( preg_match( '/\btype\s*=/i', $attrs ) && ! preg_match( '/\btype\s*=\s*(["\']?)(?:text\/)?(?:javascript|ecmascript)\1/i', $attrs ) ) {
					return $m[0];
				}

				// Never touch explicit async/defer scripts.
				if ( preg_match( '/\b(?:async|defer)\b/i', $attrs ) ) {
					return $m[0];
				}

				if ( DelayJS::excluded( $attrs, $excluded ) ) {
					return $m[0];
				}

				return str_replace( '<script', '<script type="' . DelayJS::DELAYED_TYPE . '"', $open ) . '</script>';
			},
			$html
		);

		if ( $rewritten === $html ) {
			return $html;
		}

		return $this->inject( $rewritten );
	}

	/**
	 * Emit the one-time loader that restores delayed scripts on interaction.
	 *
	 * @param string $html
	 * @return string
	 */
	private function inject( string $html ): string {
		$loader = <<<JS
<script>
(function(){var d=document,L='%1\$s';function e(){var s=d.querySelectorAll('script[type="'+L+'"]');for(var i=0;i<s.length;i++){var t=s[i];t.type='text/javascript';if(t.src){var n=d.createElement('script');n.src=t.src;n.async=t.async;n.defer=t.defer;t.parentNode.replaceChild(n,t);}}}var evts=['scroll','mousedown','touchstart','keydown'];for(var j=0;j<evts.length;j++){window.addEventListener(evts[j],e,{passive:true});}d.addEventListener('DOMContentLoaded',function(){setTimeout(e,3000);});})();
</script>
JS;
		$loader = sprintf( $loader, self::DELAYED_TYPE );

		$injected = (string) preg_replace(
			'#</head>#i',
			$loader . "\n</head>",
			$html,
			1
		);

		return $injected;
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
}
