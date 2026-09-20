<?php
/**
 * CDN settings tab.
 *
 * @var array<string,mixed> $settings
 * @package cosmic-cache
 */

require __DIR__ . '/field-helpers.php';

?>
<div class="notice notice-info inline">
	<p>
		<strong><?php esc_html_e( 'Experimental:', 'cosmic-cache' ); ?></strong>
		<?php esc_html_e( 'The CDN rewrite is experimental and off by default. Only asset links (stylesheets, scripts, images, icons, preload) are rewritten; canonical, RSS, and wp-json links keep the origin URL.', 'cosmic-cache' ); ?>
	</p>
</div>
<table class="form-table" role="presentation">
	<?php
	cosmic_field_text( 'cdn_url', __( 'CDN URL', 'cosmic-cache' ), __( 'e.g. https://cdn.example.com — rewrites local asset URLs to this host. Supports scheme-relative // URLs. Leave empty to disable.', 'cosmic-cache' ), $settings );
	cosmic_field_textarea_list( 'cdn_exclude', __( 'CDN excludes', 'cosmic-cache' ), __( 'Paths or extensions never rewritten, e.g. /uploads/ or .svg. One per line.', 'cosmic-cache' ), $settings );
	?>
</table>
