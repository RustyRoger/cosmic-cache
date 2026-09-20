<?php
/**
 * Object Cache settings tab.
 *
 * @var array<string,mixed> $settings
 * @package cosmic-cache
 */

require __DIR__ . '/field-helpers.php';

$available = \CosmicCache\ObjectCache\Status::available_backends();
$active    = \CosmicCache\ObjectCache\Status::active_backend();

?>
<?php if ( ! $available ) : ?>
	<div class="notice notice-error"><p>
		<?php esc_html_e( 'No object-cache backend is available. Install ext-redis or ext-apcu to enable the Cosmic Cache object-cache drop-in.', 'cosmic-cache' ); ?>
	</p></div>
<?php endif; ?>
<table class="form-table" role="presentation">
	<?php
	cosmic_field_checkbox( 'object_cache_enabled', __( 'Enable object cache', 'cosmic-cache' ), __( 'Generate the object-cache.php drop-in. Recommend enabling only when a backend is available below.', 'cosmic-cache' ), $settings );
	cosmic_field_select(
		'object_cache_backend',
		__( 'Backend', 'cosmic-cache' ),
		__( 'redis works locally or over TCP; apcu is a per-process in-memory cache best for single-server PHP-FPM.', 'cosmic-cache' ),
		array(
			'redis' => __( 'Redis (ext-redis)', 'cosmic-cache' ),
			'apcu'  => __( 'APCu', 'cosmic-cache' ),
		),
		$settings
	);
	cosmic_field_text( 'object_cache_host', __( 'Redis host', 'cosmic-cache' ), __( 'The Redis server address. Use 127.0.0.1 for a local Redis. Only used when Backend is redis.', 'cosmic-cache' ), $settings );
	cosmic_field_text( 'object_cache_port', __( 'Redis port', 'cosmic-cache' ), __( 'Default 6379. Only used when Backend is redis.', 'cosmic-cache' ), $settings, 'number', array( 'min' => '1', 'max' => '65535', 'step' => '1' ) );
	cosmic_field_text( 'object_cache_prefix', __( 'Key prefix override', 'cosmic-cache' ), __( 'Optional. Defaults to a stable prefix derived from the database name to keep keys unique per site.', 'cosmic-cache' ), $settings );
	?>
</table>
<p>
	<strong><?php esc_html_e( 'Active drop-in:', 'cosmic-cache' ); ?></strong>
	<?php echo esc_html( $active['type'] ); ?>
	<?php if ( $active['version'] ) : ?>
		(<?php echo esc_html( $active['version'] ); ?>)
	<?php endif; ?>
	&middot;
	<strong><?php esc_html_e( 'Available:', 'cosmic-cache' ); ?></strong>
	<?php echo esc_html( $available ? implode( ', ', $available ) : __( 'none', 'cosmic-cache' ) ); ?>
</p>
