<?php
/**
 * Optimization settings tab.
 *
 * @var array<string,mixed> $settings
 * @package cosmic-cache
 */

require __DIR__ . '/field-helpers.php';

?>
<div class="notice notice-info inline">
	<p>
		<strong><?php esc_html_e( 'Experimental:', 'cosmic-cache' ); ?></strong>
		<?php esc_html_e( 'Lazy-loading, query-string stripping, and the CDN rewrite are marked experimental and are off by default. Test each on a staging copy before enabling in production.', 'cosmic-cache' ); ?>
	</p>
</div>
<table class="form-table" role="presentation">
	<?php
	cosmic_field_checkbox( 'minify_html', __( 'Minify HTML', 'cosmic-cache' ), __( 'Strip plain comments and collapse whitespace between tags. Off by default; never touches text-node whitespace.', 'cosmic-cache' ), $settings );
	cosmic_field_checkbox( 'minify_css', __( 'Minify CSS', 'cosmic-cache' ), __( 'Strip comments and whitespace from stylesheet content (requires Minify HTML).', 'cosmic-cache' ), $settings );
	cosmic_field_checkbox( 'minify_js', __( 'Minify JS', 'cosmic-cache' ), __( 'Strip comments from inline scripts (requires Minify HTML).', 'cosmic-cache' ), $settings );
	cosmic_field_checkbox( 'combine_css', __( 'Combine CSS', 'cosmic-cache' ), __( 'Merge same-directory stylesheets into one cached file — fewer requests, but can break order-dependent rules. Test on staging.', 'cosmic-cache' ), $settings );
	cosmic_field_checkbox( 'combine_js', __( 'Combine JS', 'cosmic-cache' ), __( 'Merge same-directory local scripts into one cached file. Test on staging — execution order can break inline dependencies.', 'cosmic-cache' ), $settings );
	cosmic_field_checkbox( 'defer_js', __( 'Defer JavaScript', 'cosmic-cache' ), __( 'Add defer to eligible scripts to remove render-blocking. JSON-LD/JSON scripts are never touched.', 'cosmic-cache' ), $settings );
	cosmic_field_checkbox( 'delay_js', __( 'Delay JavaScript until interaction', 'cosmic-cache' ), __( 'Experimental. Non-critical scripts load only after the visitor scrolls or clicks (3s timeout fallback). Handles in defer_exclude / delay_js_exclude always load normally. Can break scripts that must run early — test on staging.', 'cosmic-cache' ), $settings );
	cosmic_field_textarea_list( 'delay_js_exclude', __( 'Delay JS excludes', 'cosmic-cache' ), __( 'Handles or patterns never delayed. One per line.', 'cosmic-cache' ), $settings );
	cosmic_field_checkbox( 'critical_css_enabled', __( 'Inline critical CSS', 'cosmic-cache' ), __( 'Experimental. Inline your above-the-fold CSS and load remaining stylesheets asynchronously (non-render-blocking). Can cause FOUC — test on staging.', 'cosmic-cache' ), $settings );
	cosmic_field_textarea( 'critical_css', __( 'Critical CSS', 'cosmic-cache' ), __( 'Paste the above-the-fold CSS here — the rules a browser needs before first paint. Everything outside it loads after. Empty disables the feature.', 'cosmic-cache' ), $settings );
	cosmic_field_textarea_list( 'critical_css_exclude', __( 'Critical CSS excludes', 'cosmic-cache' ), __( 'Stylesheets never loaded asynchronously (kept render-blocking). One per line.', 'cosmic-cache' ), $settings );

	$critical_snippet = isset( $settings['critical_css'] ) ? (string) $settings['critical_css'] : '';
	$critical_meta    = \CosmicCache\Optimization\CriticalCSS::meta();
	$critical_stale   = \CosmicCache\Optimization\CriticalCSS::isStale();
	if ( '' !== trim( $critical_snippet ) || ! empty( $critical_meta ) ) {
		$critical_hash = substr( (string) ( $critical_meta['hash'] ?? sha1( $critical_snippet ) ), 0, 12 );
		if ( ! empty( $critical_meta['time'] ) ) {
			$critical_when = function_exists( 'date_i18n' )
				? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $critical_meta['time'] )
				: gmdate( 'Y-m-d H:i', (int) $critical_meta['time'] );
		} else {
			$critical_when = __( 'before this tracking existed — re-save to record it', 'cosmic-cache' );
		}
		if ( ! empty( $critical_meta['theme'] ) ) {
			$critical_src = (string) $critical_meta['theme'];
			if ( ! empty( $critical_meta['version'] ) ) {
				$critical_src .= ' ' . (string) $critical_meta['version'];
			}
		} else {
			$critical_src = __( 'unknown theme', 'cosmic-cache' );
		}
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Critical CSS status', 'cosmic-cache' ); ?></th>
			<td>
				<p class="description">
					<?php
					echo esc_html( sprintf(
						/* translators: 1: snippet hash, 2: snippet size in bytes, 3: date, 4: theme name and version. */
						__( 'Snippet %1$s (%2$d bytes), recorded %3$s for %4$s.', 'cosmic-cache' ),
						$critical_hash,
						strlen( $critical_snippet ),
						$critical_when,
						$critical_src
					) );
					if ( $critical_stale ) {
						echo ' <strong>' . esc_html__( 'Possibly stale — regenerate after the theme/plugin change.', 'cosmic-cache' ) . '</strong>';
					}
					?>
				</p>
			</td>
		</tr>
		<?php
	}
	?>
	<tr>
		<th scope="row"><?php esc_html_e( 'Generate critical CSS', 'cosmic-cache' ); ?></th>
		<td>
			<?php if ( class_exists( 'DOMDocument' ) ) : ?>
				<p>
					<button type="button" class="button" data-cosmic-action="critical_generate"><?php esc_html_e( 'Generate from homepage', 'cosmic-cache' ); ?></button>
				</p>
				<p class="description"><?php esc_html_e( 'Heuristic, no browser needed: analyzes the homepage stylesheets and saves a draft for review below. Nothing is applied automatically. For more URLs use wp cosmic critical-css.', 'cosmic-cache' ); ?></p>
				<div class="cosmic-tool-result" role="status" aria-live="polite"></div>
				<div class="cosmic-critical-review">
					<?php
					$critical_draft = \CosmicCache\Optimization\CriticalCSS::draft();
					if ( ! empty( $critical_draft['css'] ) ) :
						$critical_draft_when = ! empty( $critical_draft['time'] )
							? ( function_exists( 'date_i18n' )
								? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $critical_draft['time'] )
								: gmdate( 'Y-m-d H:i', (int) $critical_draft['time'] ) )
							: __( 'unknown date', 'cosmic-cache' );
						?>
						<p class="description">
							<?php
							echo esc_html( sprintf(
								/* translators: 1: rules kept, 2: rules dropped, 3: draft bytes, 4: date. */
								__( 'Draft waiting for review: %1$d rules kept, %2$d dropped, %3$d bytes, generated %4$s.', 'cosmic-cache' ),
								(int) ( $critical_draft['kept'] ?? 0 ),
								(int) ( $critical_draft['dropped'] ?? 0 ),
								strlen( (string) $critical_draft['css'] ),
								$critical_draft_when
							) );
							?>
						</p>
						<p>
							<button type="button" class="button button-primary" data-cosmic-action="critical_apply"<?php echo ! empty( $critical_draft['over_cap'] ) ? ' data-over-cap="1"' : ''; ?>><?php esc_html_e( 'Apply draft', 'cosmic-cache' ); ?></button>
							<button type="button" class="button" data-cosmic-action="critical_discard"><?php esc_html_e( 'Discard draft', 'cosmic-cache' ); ?></button>
						</p>
					<?php endif; ?>
					<?php if ( \CosmicCache\Optimization\CriticalCSS::canRevert() ) : ?>
						<p>
							<button type="button" class="button" data-cosmic-action="critical_revert"><?php esc_html_e( 'Revert to previous snippet', 'cosmic-cache' ); ?></button>
						</p>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Generation needs the PHP DOM extension (ext-dom), which is not available on this server. Paste the snippet manually above.', 'cosmic-cache' ); ?></p>
			<?php endif; ?>
		</td>
	</tr>
	<?php
	cosmic_field_checkbox( 'lazyload', __( 'Lazy-load images', 'cosmic-cache' ), __( 'Swap images to a placeholder until they scroll into view — smaller initial HTML, images load on demand. Experimental.', 'cosmic-cache' ), $settings );
	cosmic_field_checkbox( 'lazyload_iframes', __( 'Lazy-load iframes', 'cosmic-cache' ), __( 'Swap iframes to a placeholder until they scroll into view. Experimental.', 'cosmic-cache' ), $settings );
	cosmic_field_checkbox( 'remove_emoji', __( 'Remove emoji script', 'cosmic-cache' ), __( 'Strip the WordPress emoji script and style — a small loading win on desktop sites.', 'cosmic-cache' ), $settings );
	cosmic_field_checkbox( 'remove_query_strings', __( 'Remove query strings', 'cosmic-cache' ), __( 'Strip ?ver= from static asset URLs — may hurt fully-pre-rendered asset matching. Experimental.', 'cosmic-cache' ), $settings );
	cosmic_field_textarea_list( 'exclude_css', __( 'Exclude CSS', 'cosmic-cache' ), __( 'Patterns skipped by all CSS processing. One per line.', 'cosmic-cache' ), $settings );
	cosmic_field_textarea_list( 'exclude_js', __( 'Exclude JS', 'cosmic-cache' ), __( 'Patterns skipped by all JS processing. One per line.', 'cosmic-cache' ), $settings );
	cosmic_field_textarea_list( 'defer_exclude', __( 'Defer excludes', 'cosmic-cache' ), __( 'Script handles or patterns never deferred. One per line.', 'cosmic-cache' ), $settings );
	cosmic_field_textarea_list( 'dns_prefetch', __( 'DNS prefetch hosts', 'cosmic-cache' ), __( 'Hosts to preconnect/dns-prefetch. One per line.', 'cosmic-cache' ), $settings );
	?>
</table>
