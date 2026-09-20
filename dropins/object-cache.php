<?php
/**
 * Cosmic Cache — object-cache drop-in.
 *
 * Generated at plugin activation/save by CosmicCache\ObjectCache\DropInGenerator.
 * Backend and connection details are baked in below. On a Redis connection
 * failure the cache fails open to an in-process array cache (site never breaks).
 *
 * @package cosmic-cache
 */

/*
 * COSMIC_CACHE_GENERATED v1.0.0
 * Machine marker. Used to identify this file as Cosmic-owned: foreign
 * drop-ins are never overwritten, and deactivation/uninstall only ever
 * remove a file carrying this marker.
 */

defined( 'ABSPATH' ) || exit;

if ( PHP_VERSION_ID < 70000 ) {
	// PHP too old to parse this drop-in's modern syntax. Fall back to the
	// WordPress core array cache so the site keeps working after the plugin
	// is removed from a legacy host.
	require_once ABSPATH . WPINC . '/class-wp-object-cache.php';

	if ( ! function_exists( 'wp_cache_init' ) ) {
		function wp_cache_init() {
			global $wp_object_cache;
			$wp_object_cache = new WP_Object_Cache();
		}
		function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
			global $wp_object_cache;
			return $wp_object_cache->add( $key, $data, $group, $expire );
		}
		function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
			global $wp_object_cache;
			return $wp_object_cache->set( $key, $data, $group, $expire );
		}
		function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
			global $wp_object_cache;
			$value = $wp_object_cache->get( $key, $group, $force );
			if ( null !== $found ) {
				$found = ( false !== $value );
			}
			return $value;
		}
		function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
			$found = array();
			foreach ( (array) $keys as $key ) {
				$found[ $key ] = wp_cache_get( $key, $group, $force );
			}
			return $found;
		}
		function wp_cache_set_multiple( $data, $group = '', $expire = 0 ) {
			$results = array();
			foreach ( (array) $data as $key => $value ) {
				$results[ $key ] = wp_cache_set( $key, $value, $group, $expire );
			}
			return $results;
		}
		function wp_cache_add_multiple( $data, $group = '', $expire = 0 ) {
			$results = array();
			foreach ( (array) $data as $key => $value ) {
				$results[ $key ] = wp_cache_add( $key, $value, $group, $expire );
			}
			return $results;
		}
		function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
			global $wp_object_cache;
			return $wp_object_cache->replace( $key, $data, $group, $expire );
		}
		function wp_cache_delete( $key, $group = '' ) {
			global $wp_object_cache;
			return $wp_object_cache->delete( $key, $group );
		}
		function wp_cache_flush() {
			global $wp_object_cache;
			return $wp_object_cache->flush();
		}
		function wp_cache_flush_runtime() {
			global $wp_object_cache;
			return $wp_object_cache->flush();
		}
		function wp_cache_flush_group( $group ) {
			return false;
		}
		function wp_cache_incr( $key, $offset = 1, $group = '' ) {
			global $wp_object_cache;
			return $wp_object_cache->incr( $key, $offset, $group );
		}
		function wp_cache_decr( $key, $offset = 1, $group = '' ) {
			global $wp_object_cache;
			return $wp_object_cache->decr( $key, $offset, $group );
		}
		function wp_cache_close() {
			global $wp_object_cache;
			return $wp_object_cache->close();
		}
		function wp_cache_add_global_groups( $groups ) {
			global $wp_object_cache;
			$wp_object_cache->add_global_groups( $groups );
		}
		function wp_cache_add_non_persistent_groups( $groups ) {
			global $wp_object_cache;
			$wp_object_cache->add_non_persistent_groups( $groups );
		}
		function wp_cache_switch_to_blog( $blog_id ) {
			global $wp_object_cache;
			$wp_object_cache->switch_to_blog( $blog_id );
		}
		function wp_cache_supports( $feature ) {
			return false;
		}
		function wp_cache_reset() {
			global $wp_object_cache;
			$wp_object_cache = new WP_Object_Cache();
		}
	}
	$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
	return;
}

if ( ! class_exists( 'CosmicCache_ObjectCache' ) ) :

	/**
	 * Object cache backend abstraction.
	 */
	class CosmicCache_ObjectCache {

		/**
		 * Backend: 'redis', 'apcu' or 'array' (after failover).
		 *
		 * @var string
		 */
		private $backend;

		/**
		 * Redis connection (phpredis).
		 *
		 * @var \Redis|null
		 */
		private $redis;

		/**
		 * Stable prefix.
		 *
		 * @var string
		 */
		private $prefix;

		/**
		 * In-process fallback storage.
		 *
		 * @var array
		 */
		private $cache = array();

		/**
		 * Groups that are not per-site.
		 *
		 * @var array
		 */
		private $global_groups = array();

		/**
		 * Groups that are never persisted (request-only).
		 *
		 * @var array
		 */
		private $non_persistent_groups = array();

		/**
		 * Current multisite blog id.
		 *
		 * @var int
		 */
		private $blog_id = 1;

		/**
		 * Whether a backend failure was already logged.
		 *
		 * @var bool
		 */
		private $failed = false;

		/**
		 * Constructor.
		 *
		 * @param array $config Baked config: backend, host, port, prefix.
		 */
		public function __construct( array $config ) {
			$this->prefix = ! empty( $config['prefix'] ) ? $config['prefix'] : $this->default_prefix();
			$this->backend = isset( $config['backend'] ) ? $config['backend'] : 'array';

			if ( 'redis' === $this->backend && class_exists( 'Redis' ) ) {
				$this->redis = new Redis();
				try {
					$timeout = isset( $config['timeout'] ) ? (float) $config['timeout'] : 0.2;
					if ( ! $this->redis->connect( $config['host'], (int) $config['port'], $timeout ) ) {
						throw new Exception( 'Redis connect failed' );
					}
					if ( isset( $config['database'] ) && $config['database'] > 0 ) {
						$this->redis->select( (int) $config['database'] );
					}
				} catch ( \Throwable $e ) {
					$this->backend = 'array';
					$this->redis   = null;
					$this->failover( $e->getMessage() );
				}
			} elseif ( 'redis' === $this->backend ) {
				// Backend requested but ext-redis is missing: never leave a
				// null-$redis 'redis' backend (that fatals on every op).
				$this->backend = 'array';
				$this->redis   = null;
				$this->failover( 'ext-redis not installed' );
			} elseif ( 'apcu' === $this->backend && ! function_exists( 'apcu_fetch' ) ) {
				$this->backend = 'array';
				$this->failover( 'APCu extension not available' );
			}
		}

		/**
		 * Reset state (called by wp_cache_init).
		 */
		public function init() {
			if ( null === $this->redis ) {
				$this->cache = array();
			}
		}

		/**
		 * Add only if not present.
		 *
		 * @param string $key
		 * @param mixed  $data
		 * @param string $group
		 * @param int    $expire
		 * @return bool
		 */
		public function add( $key, $data, $group = '', $expire = 0 ) {
			$id = $this->build_key( $key, $group );

			if ( $this->in_non_persistent( $group ) ) {
				if ( isset( $this->cache[ $id ] ) ) {
					return false;
				}
				$this->cache[ $id ] = array(
					'data'   => $data,
					'expire' => $expire > 0 ? time() + $expire : 0,
				);
				return true;
			}

			if ( 'redis' === $this->backend ) {
				$ttl = max( 0, (int) $expire );
				try {
					if ( $ttl > 0 ) {
						// Only set when absent (atomic).
						$ok = $this->redis->set( $id, $this->encode( $data ), array( 'nx', 'ex' => $ttl ) );
					} else {
						$ok = $this->redis->setnx( $id, $this->encode( $data ) );
					}
					return (bool) $ok;
				} catch ( \Throwable $e ) {
					$this->failover_redis( $e );
				}
			}

			if ( 'apcu' === $this->backend ) {
				return (bool) apcu_add( $id, $data, max( 0, (int) $expire ) );
			}

			if ( isset( $this->cache[ $id ] ) ) {
				return false;
			}
			$this->cache[ $id ] = array(
				'data'   => $data,
				'expire' => $expire > 0 ? time() + $expire : 0,
			);
			return true;
		}

		/**
		 * Set unconditionally.
		 *
		 * @param string $key
		 * @param mixed  $data
		 * @param string $group
		 * @param int    $expire
		 * @return bool
		 */
		public function set( $key, $data, $group = '', $expire = 0 ) {
			$id = $this->build_key( $key, $group );

			if ( $this->in_non_persistent( $group ) ) {
				$this->cache[ $id ] = array(
					'data'   => $data,
					'expire' => $expire > 0 ? time() + $expire : 0,
				);
				return true;
			}

			if ( 'redis' === $this->backend ) {
				$ttl = max( 0, (int) $expire );
				try {
					if ( $ttl > 0 ) {
						return $this->redis->setex( $id, $ttl, $this->encode( $data ) );
					}
					return $this->redis->set( $id, $this->encode( $data ) );
				} catch ( \Throwable $e ) {
					$this->failover_redis( $e );
				}
			}

			if ( 'apcu' === $this->backend ) {
				return (bool) apcu_store( $id, $data, max( 0, (int) $expire ) );
			}

			$this->cache[ $id ] = array(
				'data'   => $data,
				'expire' => $expire > 0 ? time() + $expire : 0,
			);
			return true;
		}

		/**
		 * Get a value.
		 *
		 * @param string $key
		 * @param string $group
		 * @param bool   $force
		 * @return mixed
		 */
		public function get( $key, $group = '', $force = false ) {
			$id = $this->build_key( $key, $group );

			if ( $this->in_non_persistent( $group ) ) {
				if ( ! isset( $this->cache[ $id ] ) ) {
					return false;
				}
				$entry = $this->cache[ $id ];
				if ( $entry['expire'] > 0 && time() > $entry['expire'] ) {
					unset( $this->cache[ $id ] );
					return false;
				}
				return $entry['data'];
			}

			if ( 'redis' === $this->backend ) {
				try {
					$value = $this->redis->get( $id );
					return false === $value ? false : $this->decode( $value );
				} catch ( \Throwable $e ) {
					$this->failover_redis( $e );
				}
			}

			if ( 'apcu' === $this->backend ) {
				$found = false;
				$value = apcu_fetch( $id, $found );
				return $found ? $value : false;
			}

			if ( ! isset( $this->cache[ $id ] ) ) {
				return false;
			}
			$entry = $this->cache[ $id ];
			if ( $entry['expire'] > 0 && time() > $entry['expire'] ) {
				unset( $this->cache[ $id ] );
				return false;
			}
			return $entry['data'];
		}

		/**
		 * Get multiple values.
		 *
		 * @param array $keys
		 * @param string $group
		 * @return array
		 */
		public function get_multiple( $keys, $group = '' ) {
			$found = array();
			$ids   = array();
			foreach ( (array) $keys as $key ) {
				$ids[ $key ] = $this->build_key( $key, $group );
			}

			if ( $this->in_non_persistent( $group ) ) {
				foreach ( $ids as $key => $id ) {
					$found[ $key ] = $this->local_read( $id );
				}
				return $found;
			}

			if ( 'redis' === $this->backend ) {
				try {
					$values = $this->redis->mget( array_values( $ids ) );
					$i      = 0;
					foreach ( array_keys( $ids ) as $key ) {
						$found[ $key ] = ( false === $values[ $i ] ) ? false : $this->decode( $values[ $i ] );
						++$i;
					}
					return $found;
				} catch ( \Throwable $e ) {
					$this->failover_redis( $e );
				}
			}

			if ( 'apcu' === $this->backend ) {
				foreach ( $ids as $key => $id ) {
					$hit = false;
					$v   = apcu_fetch( $id, $hit );
					$found[ $key ] = $hit ? $v : false;
				}
				return $found;
			}

			foreach ( $ids as $key => $id ) {
				$found[ $key ] = $this->local_read( $id );
			}
			return $found;
		}

		/**
		 * Read from the in-process cache applying expiry (like get()).
		 *
		 * @param string $id
		 * @return mixed
		 */
		private function local_read( $id ) {
			if ( ! isset( $this->cache[ $id ] ) ) {
				return false;
			}
			$entry = $this->cache[ $id ];
			if ( $entry['expire'] > 0 && time() > $entry['expire'] ) {
				unset( $this->cache[ $id ] );
				return false;
			}
			return $entry['data'];
		}

		/**
		 * Add multiple values (only when absent).
		 *
		 * @param array $data key => value
		 * @param string $group
		 * @param int    $expire
		 * @return array<int|string,bool> key => added?
		 */
		public function add_multiple( $data, $group = '', $expire = 0 ) {
			$results = array();
			foreach ( (array) $data as $key => $value ) {
				$results[ (string) $key ] = $this->add( $key, $value, $group, $expire );
			}
			return $results;
		}

		/**
		 * Set multiple values.
		 *
		 * @param array  $data key => value
		 * @param string $group
		 * @param int    $expire
		 * @return array<int|string,bool> key => stored?
		 */
		public function set_multiple( $data, $group = '', $expire = 0 ) {
			$results = array();
			foreach ( (array) $data as $key => $value ) {
				$results[ (string) $key ] = $this->set( $key, $value, $group, $expire );
			}
			return $results;
		}

		/**
		 * Replace a value, only when the key already exists.
		 *
		 * @param string $key
		 * @param mixed  $data
		 * @param string $group
		 * @param int    $expire
		 * @return bool
		 */
		public function replace( $key, $data, $group = '', $expire = 0 ) {
			if ( ! $this->exists( $key, $group ) ) {
				return false;
			}
			return $this->set( $key, $data, $group, $expire );
		}

		/**
		 * Does the key exist in storage (regardless of the stored value, which
		 * may legitimately be false)?
		 *
		 * @param string $key
		 * @param string $group
		 * @return bool
		 */
		private function exists( $key, $group = '' ) {
			$id = $this->build_key( $key, $group );

			if ( $this->in_non_persistent( $group ) ) {
				return isset( $this->cache[ $id ] );
			}

			if ( 'redis' === $this->backend ) {
				try {
					return (bool) $this->redis->exists( $id );
				} catch ( \Throwable $e ) {
					$this->failover_redis( $e );
				}
			}

			if ( 'apcu' === $this->backend ) {
				return (bool) apcu_exists( $id );
			}

			return isset( $this->cache[ $id ] );
		}

		/**
		 * Delete a value.
		 *
		 * @param string $key
		 * @param string $group
		 * @return bool
		 */
		public function delete( $key, $group = '' ) {
			$id = $this->build_key( $key, $group );

			if ( $this->in_non_persistent( $group ) ) {
				$existed = isset( $this->cache[ $id ] );
				unset( $this->cache[ $id ] );
				return $existed;
			}

			if ( 'redis' === $this->backend ) {
				try {
					return 1 === $this->redis->del( $id );
				} catch ( \Throwable $e ) {
					$this->failover_redis( $e );
				}
			}

			if ( 'apcu' === $this->backend ) {
				return (bool) apcu_delete( $id );
			}

			$existed = isset( $this->cache[ $id ] );
			unset( $this->cache[ $id ] );
			return $existed;
		}

		/**
		 * Flush the entire cache (persistent backend + in-process entries).
		 *
		 * @return bool
		 */
		public function flush() {
			if ( 'redis' === $this->backend ) {
				try {
					// Only flush keys under our prefix, iterating the SCAN
					// cursor until exhausted (>500 keys are returned in batches).
					$it = null;
					do {
						$batch = $this->redis->scan( $it, $this->prefix . '*', 500 );
						if ( false === $batch ) {
							break;
						}
						if ( is_array( $batch ) && $batch ) {
							$this->redis->del( $batch );
						}
					} while ( 0 !== (int) $it );
				} catch ( \Throwable $e ) {
					$this->failover_redis( $e );
				}
			}

if ( 'apcu' === $this->backend ) {
			// Scoped flush: only keys under our prefix. A raw global APCu
			// purge would nuke every key on the host (shared object cache for
			// other apps) and was removed for that reason (audit D7).
			$regex = '/^' . preg_quote( $this->prefix, '/' ) . '/';
			if ( class_exists( 'APCUIterator' ) ) {
				foreach ( new APCUIterator( $regex, APC_ITER_KEY ) as $entry ) {
					apcu_delete( $entry['key'] );
				}
			} else {
				$info = function_exists( 'apcu_cache_info' ) ? apcu_cache_info() : false;
				if ( is_array( $info ) && isset( $info['cache_list'] ) && is_array( $info['cache_list'] ) ) {
					foreach ( $info['cache_list'] as $entry ) {
						if ( isset( $entry['info'] ) && preg_match( $regex, (string) $entry['info'] ) ) {
							apcu_delete( (string) $entry['info'] );
						}
					}
				}
			}
		}

			// A full flush invalidates the request-scoped cache too.
			$this->cache = array();
			return true;
		}

		/**
		 * Flush only the in-memory (request-scoped) cache, never the persistent
		 * backend. Mirrors WP's wp_cache_flush_runtime() contract.
		 *
		 * @return bool
		 */
		public function flush_runtime() {
			$this->cache = array();
			return true;
		}

		/**
		 * Flush one cache group (SPEC: group-scoped invalidation).
		 *
		 * Redis keys are `prefix:<blog_id>:<group>:<key>` (per-site) or
		 * `prefix:<group>:<key>` (global), so two unambiguous scan patterns
		 * cover both layouts without over-matching other groups. The SCAN
		 * cursor is iterated until exhausted. In-process (non-persistent)
		 * copies of the group are cleared too.
		 *
		 * @param string $group
		 * @return bool
		 */
		public function flush_group( $group ) {
			$group = (string) $group;
			if ( '' === $group ) {
				return false;
			}

			if ( 'redis' === $this->backend ) {
				try {
					$keys = array();
					foreach ( array(
						$this->prefix . ':' . $group . ':*',
						$this->prefix . ':*:' . $group . ':*',
					) as $pattern ) {
						$it = null;
						do {
							$batch = $this->redis->scan( $it, $pattern, 500 );
							if ( false === $batch ) {
								break;
							}
							$keys = array_merge( $keys, (array) $batch );
						} while ( 0 !== (int) $it );
					}
					$keys = array_values( array_unique( $keys ) );
					if ( $keys ) {
						$this->redis->del( $keys );
					}
				} catch ( \Throwable $e ) {
					$this->failover_redis( $e );
				}
			}

if ( 'apcu' === $this->backend ) {
			$regex = '/^' . preg_quote( $this->prefix, '/' ) . ':(?:\d+:)?' . preg_quote( $group, '/' ) . ':/';
			if ( class_exists( 'APCUIterator' ) ) {
				foreach ( new APCUIterator( $regex, APC_ITER_KEY ) as $entry ) {
					apcu_delete( $entry['key'] );
				}
			} else {
				$info = function_exists( 'apcu_cache_info' ) ? apcu_cache_info() : false;
				if ( is_array( $info ) && isset( $info['cache_list'] ) && is_array( $info['cache_list'] ) ) {
					foreach ( $info['cache_list'] as $entry ) {
						if ( isset( $entry['info'] ) && preg_match( $regex, (string) $entry['info'] ) ) {
							apcu_delete( (string) $entry['info'] );
						}
					}
				}
			}
		}

			// Never leave a group's in-process copies behind.
			$this->purge_local_group( $group );

			return true;
		}

		/**
		 * Purge every in-process entry belonging to a group.
		 *
		 * @param string $group
		 * @return void
		 */
		private function purge_local_group( $group ) {
			$match = '/^' . preg_quote( $this->prefix, '#' ) . ':(?:\d+:)?' . preg_quote( $group, '#' ) . ':/';
			foreach ( array_keys( $this->cache ) as $id ) {
				if ( preg_match( $match, $id ) ) {
					unset( $this->cache[ $id ] );
				}
			}
		}

		/**
		 * Increment a counter. Returns false when the key does not exist (WP
		 * semantics — a counter is never auto-created). Uses read-modify-write
		 * through get()/set() rather than a raw INCRBY: stored values are
		 * serialized like every other entry, and calling INCRBY on a serialized
		 * string would WRONGTYPE-fatal and force the drop-in into permanent
		 * array failover.
		 *
		 * @param string $key
		 * @param int    $offset
		 * @param string $group
		 * @return int|false
		 */
		public function incr( $key, $offset = 1, $group = '' ) {
			$current = $this->get( $key, $group );
			if ( false === $current ) {
				return false;
			}
			$value = (int) $current + (int) $offset;
			$this->set( $key, $value, $group );
			return $value;
		}

		/**
		 * Decrement a counter (never below zero). Returns false on a miss.
		 *
		 * @param string $key
		 * @param int    $offset
		 * @param string $group
		 * @return int|false
		 */
		public function decr( $key, $offset = 1, $group = '' ) {
			$current = $this->get( $key, $group );
			if ( false === $current ) {
				return false;
			}
			$value = max( 0, (int) $current - (int) $offset );
			$this->set( $key, $value, $group );
			return $value;
		}

		/**
		 * Close the connection.
		 */
		public function close() {
			if ( $this->redis ) {
				try {
					$this->redis->close();
				} catch ( \Throwable $e ) {
					// Ignore.
				}
			}
		}

		/**
		 * Add global groups (not per-site).
		 *
		 * @param array $groups
		 */
		public function add_global_groups( $groups ) {
			$this->global_groups = array_merge( $this->global_groups, (array) $groups );
		}

		/**
		 * Add groups that must never be persisted (kept in the in-process cache).
		 *
		 * @param array $groups
		 */
		public function add_non_persistent_groups( $groups ) {
			$this->non_persistent_groups = array_merge( $this->non_persistent_groups, (array) $groups );
		}

		/**
		 * Whether a group must stay in the request-scoped array cache.
		 *
		 * @param string $group
		 * @return bool
		 */
		private function in_non_persistent( $group ) {
			return in_array( $group, $this->non_persistent_groups, true );
		}

		/**
		 * Switch multisite blog.
		 *
		 * @param int $blog_id
		 */
		public function switch_to_blog( $blog_id ) {
			$this->blog_id = (int) $blog_id;
		}

		/**
		 * Supports (WP ≥ 6.1).
		 *
		 * @param string $feature
		 * @return bool
		 */
		public function supports( $feature ) {
			switch ( $feature ) {
				case 'flush_runtime':
				case 'flush_group':
				case 'add_multiple':
				case 'set_multiple':
				case 'get_multiple':
				case 'replace':
					return true;
				default:
					return false;
			}
		}

		/**
		 * Build the namespaced cache key.
		 *
		 * @param string $key
		 * @param string $group
		 * @return string
		 */
		private function build_key( $key, $group ) {
			$group = '' === $group ? 'default' : $group;
			$is_global = in_array( $group, $this->global_groups, true );

			$salt = $is_global ? '' : $this->blog_id . ':';
			return $this->prefix . ':' . $salt . $group . ':' . $key;
		}

		/**
		 * Encode a value for storage.
		 *
		 * @param mixed $value
		 * @return string
		 */
		private function encode( $value ) {
			return serialize( $value );
		}

		/**
		 * Classes WordPress persists through the object cache that must
		 * round-trip through unserialize(). Anything NOT listed is never
		 * instantiated (PHP object injection guard); it degrades to
		 * __PHP_Incomplete_Class.
		 *
		 * Keep this aligned with what core stores in persistent groups
		 * (posts/users/terms/comments/options/blogs/sites/…). Plugin-defined
		 * classes are deliberately excluded until they opt in via the
		 * cosmic_cache_object_cache_allowed_classes filter.
		 *
		 * @var array<int,string>
		 */
		private static $allowed_classes = array(
			'stdClass',
			'WP_Post',
			'WP_User',
			'WP_Term',
			'WP_Comment',
			'WP_Error',
			'WP_Query',
			'WP_Site',
			'WP_Network',
			'WP_User_Query',
			'WP_Term_Query',
			'WP_Comment_Query',
			'WP_Meta_Query',
			'WP_Date_Query',
			'WP_Post_Type',
			'WP_Role',
			'WP_Theme',
			'WP_Block_Type',
		);

		/**
		 * Decode a stored value. Only curated core classes (see
		 * $allowed_classes) are instantiated; arbitrary gadget classes stay
		 * inert. Plugins may extend the list via the
		 * cosmic_cache_object_cache_allowed_classes filter.
		 *
		 * @param string $value
		 * @return mixed
		 */
		private function decode( $value ) {
			$allowed = self::$allowed_classes;
			if ( function_exists( 'apply_filters' ) ) {
				$allowed = apply_filters( 'cosmic_cache_object_cache_allowed_classes', $allowed );
			}
			$allowed = array_values( array_map( 'strval', (array) $allowed ) );

			$decoded = @unserialize( $value, array( 'allowed_classes' => $allowed ) );
			return false === $decoded && 'b:0;' !== $value ? false : $decoded;
		}

		/**
		 * Stable default prefix from DB_NAME.
		 *
		 * @return string
		 */
		private function default_prefix() {
			return defined( 'DB_NAME' ) ? 'cosmic_' . DB_NAME : 'cosmic';
		}

		/**
		 * Log a failover event.
		 *
		 * @param string $reason
		 */
		private function failover( $reason ) {
			if ( $this->failed ) {
				return;
			}
			$this->failed = true;
			if ( function_exists( 'error_log' ) ) {
				error_log( 'Cosmic Cache object cache failover to in-process array: ' . $reason );
			}
		}

		/**
		 * Recover from a live Redis failure: degrade to the in-process array
		 * backend so a mid-request Redis crash never fatals the site.
		 *
		 * @param \Throwable $e
		 */
		private function failover_redis( $e ) {
			if ( 'array' === $this->backend ) {
				return;
			}
			$this->backend = 'array';
			$this->redis   = null;
			$this->failover( $e instanceof \Throwable ? $e->getMessage() : 'Redis operation failed' );
		}
	}

endif;

/**
 * Singleton accessor. Fail-open: if the generated bootstrap failed for any
 * reason (e.g. the config block was damaged) a working in-process array cache
 * is supplied so the site never crashes on a missing instance.
 *
 * @return CosmicCache_ObjectCache
 */
function cosmic_cache_object_cache() {
	global $cosmic_object_cache;
	if ( ! $cosmic_object_cache instanceof CosmicCache_ObjectCache ) {
		$cosmic_object_cache = new CosmicCache_ObjectCache( array(
			'backend' => 'array',
			'prefix'  => defined( 'DB_NAME' ) ? 'cosmic_' . DB_NAME : 'cosmic',
		) );
	}
	return $cosmic_object_cache;
}

/* --- WordPress object cache API --- */

function wp_cache_init() {
	cosmic_cache_object_cache()->init();
}

function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
	return cosmic_cache_object_cache()->add( $key, $data, $group, $expire );
}

function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	return cosmic_cache_object_cache()->set( $key, $data, $group, $expire );
}

function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	$value = cosmic_cache_object_cache()->get( $key, $group, $force );
	if ( is_null( $found ) ) {
		return $value;
	}
	$found = ( false !== $value );
	return $value;
}

function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
	return cosmic_cache_object_cache()->get_multiple( $keys, $group );
}

function wp_cache_set_multiple( $data, $group = '', $expire = 0 ) {
	return cosmic_cache_object_cache()->set_multiple( $data, $group, $expire );
}

function wp_cache_add_multiple( $data, $group = '', $expire = 0 ) {
	return cosmic_cache_object_cache()->add_multiple( $data, $group, $expire );
}

function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
	return cosmic_cache_object_cache()->replace( $key, $data, $group, $expire );
}

function wp_cache_delete( $key, $group = '' ) {
	return cosmic_cache_object_cache()->delete( $key, $group );
}

function wp_cache_flush() {
	return cosmic_cache_object_cache()->flush();
}

function wp_cache_flush_runtime() {
	return cosmic_cache_object_cache()->flush_runtime();
}

function wp_cache_flush_group( $group ) {
	return cosmic_cache_object_cache()->flush_group( $group );
}

function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	return cosmic_cache_object_cache()->incr( $key, $offset, $group );
}

function wp_cache_decr( $key, $offset = 1, $group = '' ) {
	return cosmic_cache_object_cache()->decr( $key, $offset, $group );
}

function wp_cache_close() {
	return cosmic_cache_object_cache()->close();
}

function wp_cache_add_global_groups( $groups ) {
	cosmic_cache_object_cache()->add_global_groups( $groups );
}

function wp_cache_add_non_persistent_groups( $groups ) {
	cosmic_cache_object_cache()->add_non_persistent_groups( $groups );
}

function wp_cache_switch_to_blog( $blog_id ) {
	cosmic_cache_object_cache()->switch_to_blog( $blog_id );
}

function wp_cache_supports( $feature ) {
	return cosmic_cache_object_cache()->supports( $feature );
}

/**
 * Deprecated alias kept for legacy plugins.
 */
function wp_cache_reset() {
	cosmic_cache_object_cache()->init();
}

/* COSMIC_OC_CONFIG */
