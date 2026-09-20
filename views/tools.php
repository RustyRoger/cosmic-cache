<?php
/**
 * Tools tab: purge, purge URLs, preload, debug log viewer.
 *
 * @var array<string,mixed> $settings
 * @package cosmic-cache
 */

$debug_active = ! empty( $settings['debug'] ) || ( defined( 'COSMIC_CACHE_DEBUG' ) && COSMIC_CACHE_DEBUG );
$debug_file   = \CosmicCache\Config::cacheDir() . '/' . \CosmicCache\Config::DEBUG_FILE;

?>
<div class="cosmic-tool-result" role="status" aria-live="polite"></div>
<h2><?php esc_html_e( 'Tools', 'cosmic-cache' ); ?></h2>

<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><?php esc_html_e( 'Purge cache', 'cosmic-cache' ); ?></th>
		<td>
			<button type="button" class="button" data-cosmic-action="purge_all"><?php esc_html_e( 'Purge all', 'cosmic-cache' ); ?></button>
			<p class="description"><?php esc_html_e( 'Delete every cached page everywhere on the site.', 'cosmic-cache' ); ?></p>
		</td>
	</tr>
	<tr>
		<th scope="row"><?php esc_html_e( 'Purge URL(s)', 'cosmic-cache' ); ?></th>
		<td>
			<textarea name="cosmic_purge_urls" rows="4" class="large-text" placeholder="https://example.com/page/"></textarea>
			<p>
				<button type="button" class="button" data-cosmic-action="purge_urls"><?php esc_html_e( 'Purge URLs', 'cosmic-cache' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'Remove a specific page from the cache. One URL per line.', 'cosmic-cache' ); ?></p>
		</td>
	</tr>
	<tr>
		<th scope="row"><?php esc_html_e( 'Preload', 'cosmic-cache' ); ?></th>
		<td>
			<button type="button" class="button" data-cosmic-action="preload"><?php esc_html_e( 'Preload now', 'cosmic-cache' ); ?></button>
			<p class="description"><?php esc_html_e( 'Crawl the sitemap (or homepage + links) and rebuild every page so visitors never hit a cold miss.', 'cosmic-cache' ); ?></p>
		</td>
	</tr>
</table>

<?php if ( $debug_active ) : ?>
	<h2><?php esc_html_e( 'Debug log', 'cosmic-cache' ); ?></h2>
	<?php if ( is_file( $debug_file ) ) : ?>
		<?php $lines = array_slice( file( $debug_file ) ?: array(), -100 ); ?>
		<textarea readonly rows="14" class="large-text code"><?php echo esc_textarea( implode( '', $lines ) ); ?></textarea>
		<p class="description"><?php echo esc_html( $debug_file ); ?></p>
	<?php else : ?>
		<p class="description"><?php esc_html_e( 'No debug log yet.', 'cosmic-cache' ); ?></p>
	<?php endif; ?>
<?php endif; ?>
