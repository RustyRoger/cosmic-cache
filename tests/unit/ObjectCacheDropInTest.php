<?php
/**
 * Regression tests for the object-cache drop-in's non-persistent groups.
 *
 * wp_cache_add_non_persistent_groups() was missing entirely, fatalling
 * WordPress on boot; groups registered as non-persistent must be served from
 * the in-process array cache and never touch the configured backend.
 *
 * @package cosmic-cache
 */

namespace CosmicCache\Tests {

	use PHPUnit\Framework\TestCase;
	/**
	 * Records every backend call; optionally throws to simulate a dead Redis.
	 */
	class RedisSpy {
		/** @var array<int,string> */
		public $calls = array();
		/** @var bool */
		public $throw_on_call = false;

		public function __call( $name, $arguments ) {
			$this->calls[] = $name;
			if ( $this->throw_on_call ) {
				throw new \RuntimeException( 'redis backend touched: ' . $name );
			}
			return false;
		}
	}

	/**
	 * Simulates SCAN + DEL against an in-memory key store.
	 */
	class RedisGroupSpy {
		/** @var array<int,string> */
		public $store = array();
		/** @var array<int,array> */
		public $del_calls = array();
		/** @var array<int,string> */
		public $scan_patterns = array();

		public function __construct( array $store ) {
			$this->store = $store;
		}

		public function scan( &$it, $pattern, $count ) {
			$this->scan_patterns[] = $pattern;
			$it = null;
			$regex = '#' . str_replace( array( '\*', '\?' ), array( '.*', '.' ), preg_quote( $pattern, '#' ) ) . '#';
			$found = array();
			foreach ( $this->store as $key ) {
				if ( preg_match( $regex, $key ) ) {
					$found[] = $key;
				}
			}
			return $found;
		}

		public function del( $keys ) {
			$keys = (array) $keys;
			$this->del_calls[] = $keys;
			$this->store = array_values( array_diff( $this->store, $keys ) );
			return count( $keys );
		}
	}

	/**
	 * Simulates SCAN + DEL against an in-memory key store, paging results so
	 * the drop-in's cursor loop (SCAN exhausted) is exercised.
	 */
	class RedisScanSpy {
		/** @var array<int,string> */
		public $store = array();
		/** @var array<int,array> */
		public $del_calls = array();
		/** @var array<int,string> */
		public $scan_patterns = array();
		/** @var int */
		private $page_size = 2;
		/** @var array<string,array> */
		private $matches = array();
		/** @var array<string,int> */
		private $pos = array();

		public function __construct( array $store, int $page_size = 2 ) {
			$this->store = $store;
			$this->page_size = $page_size;
		}

		public function scan( &$it, $pattern, $count ) {
			$this->scan_patterns[] = $pattern;
			if ( ! isset( $this->matches[ $pattern ] ) ) {
				$regex = '#' . str_replace( array( '\*', '\?' ), array( '.*', '.' ), preg_quote( $pattern, '#' ) ) . '#';
				$this->matches[ $pattern ] = array_values(
					array_filter( $this->store, static function ( $k ) use ( $regex ) {
						return (bool) preg_match( $regex, $k );
					} )
				);
			}
			$all   = $this->matches[ $pattern ];
			$start = isset( $this->pos[ $pattern ] ) ? $this->pos[ $pattern ] : 0;
			$chunk = array_slice( $all, $start, $this->page_size );
			$this->pos[ $pattern ] = $start + count( $chunk );
			$it = ( $this->pos[ $pattern ] < count( $all ) ) ? 1 : 0;
			return $chunk;
		}

		public function del( $keys ) {
			$keys = (array) $keys;
			$this->del_calls[] = $keys;
			$this->store = array_values( array_diff( $this->store, $keys ) );
			return count( $keys );
		}
	}

	/**
	 * Faithful Redis store (serialized payloads) for get/set/mget/del paths,
	 * including a working INCRBY so counter semantics can be verified.
	 */
	class RedisStoreSpy {
		/** @var array<string,string> id => serialized value */
		public $store = array();
		/** @var array<int,string> */
		public $calls = array();

		public function get( $id ) {
			$this->calls[] = 'get';
			return array_key_exists( $id, $this->store ) ? $this->store[ $id ] : false;
		}

		public function set( $id, $value ) {
			$this->calls[] = 'set';
			$this->store[ $id ] = $value;
			return true;
		}

		public function setex( $id, $ttl, $value ) {
			$this->calls[] = 'setex';
			$this->store[ $id ] = $value;
			return true;
		}

		public function setnx( $id, $value ) {
			$this->calls[] = 'setnx';
			if ( array_key_exists( $id, $this->store ) ) {
				return false;
			}
			$this->store[ $id ] = $value;
			return true;
		}

		public function mget( $ids ) {
			$this->calls[] = 'mget';
			$out = array();
			foreach ( (array) $ids as $id ) {
				$out[] = isset( $this->store[ $id ] ) ? $this->store[ $id ] : false;
			}
			return $out;
		}

		public function del( $ids ) {
			$this->calls[] = 'del';
			$n = 0;
			foreach ( (array) $ids as $id ) {
				if ( isset( $this->store[ $id ] ) ) {
					unset( $this->store[ $id ] );
					++$n;
				}
			}
			return $n;
		}

		public function incrBy( $id, $offset ) {
			$this->calls[] = 'incrBy';
			if ( ! isset( $this->store[ $id ] ) ) {
				$this->store[ $id ] = (string) $offset;
				return $offset;
			}
			$this->store[ $id ] = (string) ( (int) $this->store[ $id ] + $offset );
			return (int) $this->store[ $id ];
		}

		public function close() {}
	}

	class ObjectCacheDropInTest extends TestCase {

		protected function setUp(): void {
			require_once dirname( __DIR__, 2 ) . '/dropins/object-cache.php';
			$GLOBALS['cosmic_object_cache'] = null;
		}

		/**
		 * Build a cache instance forced onto the redis backend with a spy.
		 *
		 * @return \CosmicCache_ObjectCache
		 */
		private function redis_cache(): \CosmicCache_ObjectCache {
			$cache = new \CosmicCache_ObjectCache( array( 'backend' => 'array', 'prefix' => 't' ) );

			$backend = new \ReflectionProperty( \CosmicCache_ObjectCache::class, 'backend' );
			$backend->setAccessible( true );
			$backend->setValue( $cache, 'redis' );

			$redis = new \ReflectionProperty( \CosmicCache_ObjectCache::class, 'redis' );
			$redis->setAccessible( true );
			$redis->setValue( $cache, new RedisSpy() );

			return $cache;
		}

		public function test_wp_cache_add_non_persistent_groups_function_exists(): void {
			$this->assertTrue( function_exists( 'wp_cache_add_non_persistent_groups' ) );
		}

		public function test_non_persistent_groups_stay_in_request_cache(): void {
			$cache = $this->redis_cache();
			$cache->add_non_persistent_groups( array( 'session' ) );

			$this->assertTrue( $cache->add( 'uid', '123', 'session' ) );
			$this->assertSame( '123', $cache->get( 'uid', 'session' ) );

			$this->assertFalse( $cache->add( 'uid', '999', 'session' ) );
			$this->assertTrue( $cache->set( 'uid', '456', 'session' ) );
			$this->assertSame( '456', $cache->get( 'uid', 'session' ) );

			$this->assertTrue( $cache->delete( 'uid', 'session' ) );
			$this->assertFalse( $cache->get( 'uid', 'session' ) );
		}

		public function test_incr_decr_on_non_persistent_groups_avoid_backend(): void {
			$cache = $this->redis_cache();
			$cache->add_non_persistent_groups( array( 'stats' ) );

			$cache->set( 'n', 5, 'stats' );
			$this->assertSame( 6, $cache->incr( 'n', 1, 'stats' ) );
			$this->assertSame( 5, $cache->decr( 'n', 1, 'stats' ) );
		}

		public function test_persistent_groups_still_use_backend(): void {
			$cache = $this->redis_cache();

			$cache->set( 'k', 'v', 'persistent' );

			$redis = new \ReflectionProperty( \CosmicCache_ObjectCache::class, 'redis' );
			$redis->setAccessible( true );
			$this->assertContains( 'set', $redis->getValue( $cache )->calls );
		}

		public function test_dead_redis_fails_open_to_array_not_fatal(): void {
			$cache = $this->redis_cache();
			$redis = new \ReflectionProperty( \CosmicCache_ObjectCache::class, 'redis' );
			$redis->setAccessible( true );
			$redis->getValue( $cache )->throw_on_call = true;

			// A throwing backend must degrade to the array cache, not fatal.
			$cache->set( 'k', 'v', 'persistent' );
			$this->assertSame( 'v', $cache->get( 'k', 'persistent' ) );

			$backend = new \ReflectionProperty( \CosmicCache_ObjectCache::class, 'backend' );
			$backend->setAccessible( true );
			$this->assertSame( 'array', $backend->getValue( $cache ) );
		}

		public function test_api_function_proxies_to_singleton(): void {
			$cache = $this->redis_cache();
			$GLOBALS['cosmic_object_cache'] = $cache;

			wp_cache_add_non_persistent_groups( array( 'widgets' ) );

			$check = new \ReflectionMethod( \CosmicCache_ObjectCache::class, 'in_non_persistent' );
			$check->setAccessible( true );
			$this->assertTrue( $check->invoke( $cache, 'widgets' ) );
			$this->assertFalse( $check->invoke( $cache, 'options' ) );
		}

		public function test_flush_group_clears_only_that_group_on_array_backend(): void {
			$cache = new \CosmicCache_ObjectCache( array( 'backend' => 'array', 'prefix' => 't' ) );
			$cache->add_global_groups( array( 'global' ) );

			$cache->set( 'a', '1', 'foo' );
			$cache->set( 'b', '2', 'foo' );
			$cache->set( 'c', '3', 'bar' );
			$cache->set( 'g', '4', 'global' );

			$this->assertTrue( $cache->flush_group( 'foo' ) );

			$this->assertFalse( $cache->get( 'a', 'foo' ) );
			$this->assertFalse( $cache->get( 'b', 'foo' ) );
			$this->assertSame( '3', $cache->get( 'c', 'bar' ) );
			$this->assertSame( '4', $cache->get( 'g', 'global' ) );
		}

		public function test_flush_group_scans_both_key_layouts_and_dels(): void {
			$redis = new RedisGroupSpy( array(
				't:foo:k1',   // global group layout
				't:1:foo:k2', // per-site layout
				't:bar:k3',   // other group (must survive)
			) );
			$cache = $this->redis_cache();
			$set   = new \ReflectionProperty( \CosmicCache_ObjectCache::class, 'redis' );
			$set->setAccessible( true );
			$set->setValue( $cache, $redis );

			$this->assertTrue( $cache->flush_group( 'foo' ) );

			$this->assertSame( array( 't:foo:*', 't:*:foo:*' ), $redis->scan_patterns );
			$this->assertSame( array( array( 't:foo:k1', 't:1:foo:k2' ) ), $redis->del_calls );
			$this->assertSame( array( 't:bar:k3' ), $redis->store );
		}

		public function test_wp_cache_flush_group_api_delegates(): void {
			$cache = new \CosmicCache_ObjectCache( array( 'backend' => 'array', 'prefix' => 't' ) );
			$GLOBALS['cosmic_object_cache'] = $cache;
			$cache->set( 'a', '1', 'widgets' );

			$this->assertTrue( wp_cache_flush_group( 'widgets' ) );
			$this->assertFalse( $cache->get( 'a', 'widgets' ) );
		}

		public function test_decode_roundtrips_stdclass_and_core_classes(): void {
			$method = new \ReflectionMethod( \CosmicCache_ObjectCache::class, 'decode' );
			$method->setAccessible( true );
			$cache = new \CosmicCache_ObjectCache( array( 'backend' => 'array', 'prefix' => 't' ) );

			// stdClass (e.g. alloptions / OBJECT_K rows) round-trips.
			$std         = new \stdClass();
			$std->name   = 'foo';
			$std->option = '1';
			$decoded     = $method->invoke( $cache, serialize( $std ) );
			$this->assertInstanceOf( \stdClass::class, $decoded );
			$this->assertSame( 'foo', $decoded->name );

			// Core classes WP caches (WP_Post, WP_User, …) round-trip too.
			$wp_post = new \WP_Post();
			$wp_post->post_title = 'Hello';
			$decoded = $method->invoke( $cache, serialize( $wp_post ) );
			$this->assertInstanceOf( \WP_Post::class, $decoded );
			$this->assertSame( 'Hello', $decoded->post_title );
		}

		public function test_decode_never_instantiates_unknown_gadget_classes(): void {
			$method = new \ReflectionMethod( \CosmicCache_ObjectCache::class, 'decode' );
			$method->setAccessible( true );
			$cache = new \CosmicCache_ObjectCache( array( 'backend' => 'array', 'prefix' => 't' ) );

			// A non-allowlisted class must degrade to __PHP_Incomplete_Class,
			// never be instantiated (object-injection guard).
			$gadget = new \CosmicCache\Tests\GadgetPayload();
			$decoded = $method->invoke( $cache, serialize( $gadget ) );
			$this->assertNotInstanceOf( \CosmicCache\Tests\GadgetPayload::class, $decoded );
			$this->assertInstanceOf( \__PHP_Incomplete_Class::class, $decoded );
		}

		public function test_flush_runtime_never_touches_persistent_redis(): void {
			$cache = $this->redis_cache();
			$redis = new \ReflectionProperty( \CosmicCache_ObjectCache::class, 'redis' );
			$redis->setAccessible( true );
			$spy = $redis->getValue( $cache );

			$cache->set( 'a', '1', 'g' );
			$spy->calls = array();

			$this->assertTrue( $cache->flush_runtime() );
			$this->assertNotContains( 'scan', $spy->calls );
			$this->assertNotContains( 'del', $spy->calls );
		}

		public function test_flush_clears_in_memory_entries_too(): void {
			$cache = $this->redis_cache();
			$cache->add_non_persistent_groups( array( 'np' ) );
			$cache->set( 'a', '1', 'np' );

			$this->assertTrue( $cache->flush() );
			$this->assertFalse( $cache->get( 'a', 'np' ) );
		}

		public function test_flush_iterates_scan_cursor_until_exhausted(): void {
			$redis = new RedisScanSpy( array( 't:a1', 't:a2', 't:a3', 't:a4', 't:a5' ), 2 );
			$cache = $this->redis_cache();
			$set   = new \ReflectionProperty( \CosmicCache_ObjectCache::class, 'redis' );
			$set->setAccessible( true );
			$set->setValue( $cache, $redis );

			$this->assertTrue( $cache->flush() );

			// 5 keys at page size 2 → 3 batches, full cursor exhaustion.
			$this->assertGreaterThanOrEqual( 3, $redis->del_calls );
			$this->assertSame( array(), $redis->store );
		}

		public function test_flush_group_iterates_both_patterns_until_cursor_exhausted(): void {
			$redis = new RedisScanSpy( array(
				't:foo:k1', 't:foo:k2', 't:foo:k3', 't:foo:k4',
				't:1:foo:k5', 't:1:foo:k6', 't:1:foo:k7',
				't:bar:x',
			), 2 );
			$cache = $this->redis_cache();
			$set   = new \ReflectionProperty( \CosmicCache_ObjectCache::class, 'redis' );
			$set->setAccessible( true );
			$set->setValue( $cache, $redis );

			$this->assertTrue( $cache->flush_group( 'foo' ) );

			$this->assertSame( array( 't:foo:*', 't:*:foo:*' ), array_values( array_unique( $redis->scan_patterns ) ) );
			$this->assertCount( 1, $redis->del_calls );
			$this->assertSame(
				array( 't:foo:k1', 't:foo:k2', 't:foo:k3', 't:foo:k4', 't:1:foo:k5', 't:1:foo:k6', 't:1:foo:k7' ),
				$redis->del_calls[0]
			);
			$this->assertSame( array( 't:bar:x' ), $redis->store );
		}

		public function test_incr_decr_use_read_modify_write_and_return_false_on_miss(): void {
			$redis = new RedisStoreSpy();
			$cache = $this->redis_cache();
			$set   = new \ReflectionProperty( \CosmicCache_ObjectCache::class, 'redis' );
			$set->setAccessible( true );
			$set->setValue( $cache, $redis );

			// Miss → false, never auto-create.
			$this->assertFalse( $cache->incr( 'n', 1, 'g' ) );
			$this->assertFalse( $cache->decr( 'n', 1, 'g' ) );

			$cache->set( 'n', 5, 'g' );
			$this->assertSame( 6, $cache->incr( 'n', 1, 'g' ) );
			$this->assertSame( 5, $cache->decr( 'n', 1, 'g' ) );

			// Never floors below zero.
			$this->assertSame( 0, $cache->decr( 'n', 10, 'g' ) );
		}

		public function test_incr_on_serialized_counter_never_triggers_failover(): void {
			$redis = new RedisStoreSpy();
			$cache = $this->redis_cache();
			$set   = new \ReflectionProperty( \CosmicCache_ObjectCache::class, 'redis' );
			$set->setAccessible( true );
			$set->setValue( $cache, $redis );

			$cache->set( 'n', 3, 'g' ); // stored serialized, like everything else
			$this->assertSame( 4, $cache->incr( 'n', 1, 'g' ) );
			$this->assertNotContains( 'incrBy', $redis->calls );

			$backend = new \ReflectionProperty( \CosmicCache_ObjectCache::class, 'backend' );
			$backend->setAccessible( true );
			$this->assertSame( 'redis', $backend->getValue( $cache ) );
		}

		public function test_set_multiple_returns_per_key_booleans(): void {
			$cache = new \CosmicCache_ObjectCache( array( 'backend' => 'array', 'prefix' => 't' ) );
			$result = $cache->set_multiple( array( 'a' => 1, 'b' => 2, 'c' => 3 ), 'g' );
			$this->assertIsArray( $result );
			$this->assertSame( array( 'a' => true, 'b' => true, 'c' => true ), $result );
		}

		public function test_add_multiple_only_adds_absent_keys(): void {
			$cache = new \CosmicCache_ObjectCache( array( 'backend' => 'array', 'prefix' => 't' ) );
			$cache->add( 'a', 1, 'g' );
			$result = $cache->add_multiple( array( 'a' => 99, 'b' => 2 ), 'g' );
			$this->assertSame( array( 'a' => false, 'b' => true ), $result );
			$this->assertSame( 1, $cache->get( 'a', 'g' ) );
		}

		public function test_replace_only_updates_existing_keys(): void {
			$cache = new \CosmicCache_ObjectCache( array( 'backend' => 'array', 'prefix' => 't' ) );
			$this->assertFalse( $cache->replace( 'missing', 'x', 'g' ) );

			$cache->set( 'k', 'old', 'g' );
			$this->assertTrue( $cache->replace( 'k', 'new', 'g' ) );
			$this->assertSame( 'new', $cache->get( 'k', 'g' ) );

			$cache->set( 'f', false, 'g' );
			$this->assertTrue( $cache->replace( 'f', 'after', 'g' ) );
			$this->assertSame( 'after', $cache->get( 'f', 'g' ) );
		}

		public function test_delete_returns_false_when_key_absent(): void {
			$cache = new \CosmicCache_ObjectCache( array( 'backend' => 'array', 'prefix' => 't' ) );
			$this->assertFalse( $cache->delete( 'missing', 'g' ) );

			$cache->set( 'k', 'v', 'g' );
			$this->assertTrue( $cache->delete( 'k', 'g' ) );
			$this->assertFalse( $cache->delete( 'k', 'g' ) );
		}

		public function test_get_multiple_enforces_expiry_in_memory(): void {
			$cache = new \CosmicCache_ObjectCache( array( 'backend' => 'array', 'prefix' => 't' ) );
			$cache->add_non_persistent_groups( array( 'np' ) );

			$cache->set( 'a', '1', 'np', 1 );
			$this->assertSame( array( 'a' => '1', 'b' => false ), $cache->get_multiple( array( 'a', 'b' ), 'np' ) );

			sleep( 2 );

			$this->assertSame( array( 'a' => false ), $cache->get_multiple( array( 'a' ), 'np' ) );
		}

		public function test_supports_declares_replace(): void {
			$cache = new \CosmicCache_ObjectCache( array( 'backend' => 'array', 'prefix' => 't' ) );
			$this->assertTrue( $cache->supports( 'replace' ) );
			$this->assertTrue( $cache->supports( 'add_multiple' ) );
			$this->assertTrue( $cache->supports( 'flush_runtime' ) );
			$this->assertFalse( $cache->supports( 'incr' ) );
		}

		public function test_wp_cache_proxies_for_new_api_surface(): void {
			$cache = new \CosmicCache_ObjectCache( array( 'backend' => 'array', 'prefix' => 't' ) );
			$GLOBALS['cosmic_object_cache'] = $cache;

			$this->assertTrue( function_exists( 'wp_cache_replace' ) );
			$this->assertTrue( function_exists( 'wp_cache_add_multiple' ) );

			$this->assertFalse( wp_cache_replace( 'k', 'x', 'g' ) );
			wp_cache_set( 'k', 'a', 'g' );
			$this->assertTrue( wp_cache_replace( 'k', 'b', 'g' ) );
			$this->assertSame( 'b', $cache->get( 'k', 'g' ) );

			$this->assertSame(
				array( 'm1' => true, 'm2' => true ),
				wp_cache_add_multiple( array( 'm1' => 1, 'm2' => 2 ), 'g' )
			);

			$this->assertTrue( wp_cache_flush_runtime() );
			$this->assertFalse( $cache->get( 'k', 'g' ) );
		}

		public function test_accessor_falls_back_to_array_cache_when_uninitialized(): void {
			$GLOBALS['cosmic_object_cache'] = null;
			$cache = cosmic_cache_object_cache();

			$this->assertInstanceOf( \CosmicCache_ObjectCache::class, $cache );
			$cache->set( 'k', 'v', 'g' );
			$this->assertSame( 'v', $cache->get( 'k', 'g' ) );
		}
	}

	/**
	 * A class deliberately NOT in the drop-in allowlist; must never be
	 * instantiated by decode().
	 */
	class GadgetPayload {
		public $prop = 'boom';
	}
}

namespace {

	if ( ! class_exists( 'WP_Post' ) ) {
		class WP_Post {
			public $post_title;
		}
	}
}
