<?php
/**
 * Hardening tests: drop-in overwrite guard, fail-open config validation,
 * WP-CLI capability guard (SPEC §15/§16).
 *
 * @package cosmic-cache
 */

namespace { // phpcs:ignore
	if ( ! function_exists( 'current_user_can' ) ) {
		function current_user_can( $cap ) {
			return (bool) ( $GLOBALS['cosmic_test_user_can'] ?? true );
		}
	}

	if ( ! class_exists( 'WP_CLI' ) ) {
		class WP_CLI {
			/** @var bool */
			public static $error_called = false;
			/** @var string */
			public static $last_error = '';

			public static function error( $message = '' ) {
				self::$error_called = true;
				self::$last_error   = (string) $message;
			}
		}
	}
}

namespace CosmicCache\Tests {

	use CosmicCache\Cache\Serve;
	use CosmicCache\Cli\Command;
	use CosmicCache\Plugin;
	use PHPUnit\Framework\TestCase;

	class HardeningTest extends TestCase {

		/** @var string */
		private $dropin;

		protected function setUp(): void {
			$this->dropin = WP_CONTENT_DIR . '/advanced-cache.php';
			@unlink( $this->dropin );
			$GLOBALS['cosmic_test_transients'] = array();
			$GLOBALS['cosmic_test_user_id']    = 1;
			$GLOBALS['cosmic_test_user_can']   = true;
			\WP_CLI::$error_called             = false;
			\WP_CLI::$last_error               = '';
		}

		protected function tearDown(): void {
			@unlink( $this->dropin );
		}

		public function test_foreign_advanced_dropin_is_not_overwritten(): void {
			$foreign = "<?php\n/* someone else's cache */\n";
			file_put_contents( $this->dropin, $foreign );

			$ok = Plugin::instance()->write_advanced_cache();

			$this->assertFalse( $ok );
			$this->assertSame( $foreign, file_get_contents( $this->dropin ) );
			$this->assertSame( 'advanced_cache_foreign', get_transient( 'cosmic_cache_notice' ) );
		}

		public function test_cosmic_dropin_is_regenerated(): void {
			$stale = "<?php\n/* Cosmic Cache — advanced-cache drop-in. */\nstale();\n";
			file_put_contents( $this->dropin, $stale );

			$ok  = Plugin::instance()->write_advanced_cache();
			$out = (string) file_get_contents( $this->dropin );

			$this->assertTrue( $ok );
			$this->assertStringNotContainsString( 'stale()', $out );
			// Template markers must have been replaced by inlined sources.
			$this->assertStringNotContainsString( 'COSMIC_SRC_VARY', $out );
			$this->assertStringContainsString( 'Serve::dispatch()', $out );
		}

		private function looks_like_config( string $file ): bool {
			$method = new \ReflectionMethod( Serve::class, 'looksLikeConfig' );
			$method->setAccessible( true );
			return (bool) $method->invoke( null, $file );
		}

		public function test_config_fail_open_accepts_generated_header(): void {
			$file = sys_get_temp_dir() . '/cosmic-config-' . bin2hex( random_bytes( 4 ) ) . '.php';
			file_put_contents( $file, "<?php\n// Cosmic Cache runtime config. Generated 2026-01-01T00:00:00+00:00. Do not edit.\nreturn array();\n" );
			$this->assertTrue( $this->looks_like_config( $file ) );
			@unlink( $file );
		}

		public function test_config_fail_open_rejects_foreign_and_broken_files(): void {
			$base = sys_get_temp_dir() . '/cosmic-config-' . bin2hex( random_bytes( 4 ) );

			$no_marker = $base . '-nomarker.php';
			file_put_contents( $no_marker, "<?php\nreturn array();\n" );
			$this->assertFalse( $this->looks_like_config( $no_marker ) );

			$no_tag = $base . '-notag.php';
			file_put_contents( $no_tag, "return array();\n" );
			$this->assertFalse( $this->looks_like_config( $no_tag ) );

			$empty = $base . '-empty.php';
			file_put_contents( $empty, '' );
			$this->assertFalse( $this->looks_like_config( $empty ) );

			@unlink( $no_marker );
			@unlink( $no_tag );
			@unlink( $empty );
		}

		public function test_cli_guard_rejects_without_capability(): void {
			$GLOBALS['cosmic_test_user_can'] = false;
			$method = new \ReflectionMethod( Command::class, 'assert_permission' );
			$method->setAccessible( true );

			$method->invoke( new Command() );

			$this->assertTrue( \WP_CLI::$error_called );
		}

		public function test_cli_guard_allows_with_capability(): void {
			$GLOBALS['cosmic_test_user_can'] = true;
			$method = new \ReflectionMethod( Command::class, 'assert_permission' );
			$method->setAccessible( true );

			$method->invoke( new Command() );

			$this->assertFalse( \WP_CLI::$error_called );
		}

		public function test_cli_guard_allows_without_user_context(): void {
			// Server-side runs (wp-cli as root / cron) have no user: allowed.
			$GLOBALS['cosmic_test_user_id']  = 0;
			$GLOBALS['cosmic_test_user_can'] = false;
			$method = new \ReflectionMethod( Command::class, 'assert_permission' );
			$method->setAccessible( true );

			$method->invoke( new Command() );

			$this->assertFalse( \WP_CLI::$error_called );
		}

		public function test_delete_tree_does_not_follow_directory_symlinks(): void {
			$base = sys_get_temp_dir() . '/cosmic-dtree-' . bin2hex( random_bytes( 4 ) );
			$tree = $base . '/cache';
			$outside = $base . '/outside';
			mkdir( $tree, 0755, true );
			mkdir( $outside, 0755, true );

			// A hostile symlink inside the cache tree pointing OUTSIDE it.
			file_put_contents( $outside . '/keep.txt', 'precious' );
			symlink( $outside, $tree . '/escape' );
			// A legit nested dir.
			mkdir( $tree . '/sub', 0755, true );
			file_put_contents( $tree . '/sub/a.html', 'x' );

			$plugin    = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
			$method    = new \ReflectionMethod( Plugin::class, 'delete_tree' );
			$method->setAccessible( true );
			$method->invoke( $plugin, $tree );

			// The symlink itself is gone (unlinked, not followed)...
			$this->assertFileDoesNotExist( $tree . '/escape' );
			// ...and the outside target is untouched.
			$this->assertFileExists( $outside . '/keep.txt' );
			$this->assertSame( 'precious', file_get_contents( $outside . '/keep.txt' ) );
			// Tree fully removed.
			$this->assertDirectoryDoesNotExist( $tree );

			@unlink( $outside . '/keep.txt' );
			@rmdir( $outside );
			@rmdir( $base );
		}

		public function test_object_cache_dropin_flush_is_prefix_scoped(): void {
			$template = (string) file_get_contents( dirname( __DIR__, 2 ) . '/dropins/object-cache.php' );

			// D7: must never nuke the whole APCu store via apcu_clear_cache().
			$this->assertStringNotContainsString( 'apcu_clear_cache', $template );
			// The scoped iterator path is present.
			$this->assertStringContainsString( 'APCUIterator', $template );
		}
	}
}
