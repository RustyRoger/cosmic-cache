<?php
/**
 * Cosmic Cache — advanced-cache drop-in.
 *
 * SELF-CONTAINED and generated at plugin activation/upgrade from
 * dropins/advanced-cache.php. The hot path (cache hit) serves static HTML from
 * disk BEFORE WordPress boots: this file reads only the cache root,
 * config.php and the cached files. Do not edit; regenerate instead.
 *
 * @package cosmic-cache
 */

namespace CosmicCache\Cache {

/* COSMIC_SRC_VARY */

/* COSMIC_SRC_BYPASS */

/* COSMIC_SRC_SERVE */

	defined( 'ABSPATH' ) || exit;

	Serve::dispatch();
}
