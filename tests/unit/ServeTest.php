<?php
/**
 * Regression tests for the hot-path serve() semantics:
 * a served request MUST terminate (exit) so WordPress never continues to
 * boot/re-capture. Covers the 304 and HEAD paths that previously returned
 * instead of exiting (SPEC §7.4).
 *
 * exit() cannot be observed in-process, so each case runs in a child PHP
 * process; a shutdown function reports the final http_response_code().
 *
 * @package cosmic-cache
 */

namespace CosmicCache\Tests {

	use CosmicCache\Cache\Serve;
	use PHPUnit\Framework\TestCase;

	class ServeTest extends TestCase {

		/**
		 * Run a snippet in a child PHP process that requires the autoloader.
		 *
		 * @param string $code
		 * @return array{0:string,1:int} [output, exit code]
		 */
		private function run_child( string $code ): array {
			$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
			$script   = 'require ' . var_export( $autoload, true ) . '; ' . $code;
			$cmd      = escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $script );
			exec( $cmd . ' 2>&1', $out, $status );
			return array( implode( "\n", (array) $out ), (int) $status );
		}

		/**
		 * A minimal compiled-config array (as Config::compile() emits).
		 *
		 * @return array<string,mixed>
		 */
		private function settings(): array {
			return array(
				'enabled'               => true,
				'ttl'                   => 3600,
				'gzip'                  => false,
				'brotli'                => false,
				'cache_headers'         => true,
				'debug'                 => false,
				'cache_mobile'          => false,
				'cache_cookies'         => array(),
				'cache_logged_in'       => false,
				'cache_whitelist_query' => array(),
				'blogs'                 => array(),
			);
		}

		public function test_get_is_served_then_exits(): void {
			list( $out, $status ) = $this->run_child(
				'$f = tempnam(sys_get_temp_dir(), "ccserve");' .
				'file_put_contents($f, "HELLO_CACHE_BODY");' .
				'$_SERVER = array("REQUEST_METHOD" => "GET", "HTTP_ACCEPT_ENCODING" => "");' .
				'register_shutdown_function(function () use ($f) { echo "\n__RC__" . http_response_code() . "\n"; @unlink($f); });' .
				'\\' . Serve::class . '::serve(' . var_export( $this->settings(), true ) . ', $f);' .
				'echo "__UNREACHED__";'
			);

			$this->assertSame( 0, $status );
			$this->assertStringContainsString( 'HELLO_CACHE_BODY', $out );
			$this->assertStringContainsString( '__RC__200', $out );
			$this->assertStringNotContainsString( '__UNREACHED__', $out );
		}

		public function test_not_modified_responds_304_and_exits(): void {
			list( $out, $status ) = $this->run_child(
				'$f = tempnam(sys_get_temp_dir(), "ccserve");' .
				'file_put_contents($f, "HELLO_CACHE_BODY");' .
				'$etag = sha1(filemtime($f) . ":" . filesize($f));' .
				'$_SERVER = array("REQUEST_METHOD" => "GET", "HTTP_ACCEPT_ENCODING" => "", "HTTP_IF_NONE_MATCH" => "\"" . $etag . "\"");' .
				'register_shutdown_function(function () use ($f) { echo "\n__RC__" . http_response_code() . "\n"; @unlink($f); });' .
				'\\' . Serve::class . '::serve(' . var_export( $this->settings(), true ) . ', $f);' .
				'echo "__UNREACHED__";'
			);

			$this->assertSame( 0, $status );
			$this->assertStringContainsString( '__RC__304', $out );
			$this->assertStringNotContainsString( 'HELLO_CACHE_BODY', $out );
			$this->assertStringNotContainsString( '__UNREACHED__', $out );
		}

		public function test_head_emits_headers_without_body_and_exits(): void {
			list( $out, $status ) = $this->run_child(
				'$f = tempnam(sys_get_temp_dir(), "ccserve");' .
				'file_put_contents($f, "HELLO_CACHE_BODY");' .
				'$_SERVER = array("REQUEST_METHOD" => "HEAD", "HTTP_ACCEPT_ENCODING" => "");' .
				'register_shutdown_function(function () use ($f) { echo "\n__RC__" . http_response_code() . "\n"; @unlink($f); });' .
				'\\' . Serve::class . '::serve(' . var_export( $this->settings(), true ) . ', $f);' .
				'echo "__UNREACHED__";'
			);

			$this->assertSame( 0, $status );
			$this->assertStringContainsString( '__RC__200', $out );
			$this->assertStringNotContainsString( 'HELLO_CACHE_BODY', $out );
			$this->assertStringNotContainsString( '__UNREACHED__', $out );
		}

		public function test_dispatch_uses_cached_file_within_ttl(): void {
			list( $out, $status ) = $this->run_child(
				'$root = sys_get_temp_dir() . "/ccdispatch-" . bin2hex(random_bytes(4));' .
				'define("COSMIC_CACHE_DIR", $root);' .
				'$settings = ' . var_export( $this->settings(), true ) . ';' .
				'@mkdir($root, 0755, true);' .
				'file_put_contents($root . "/config.php", "<?php\n// Cosmic Cache runtime config. Generated 2026-01-01T00:00:00+00:00. Do not edit.\nreturn " . var_export($settings, true) . ";\n");' .
				'$env = array("scheme" => "https", "host" => "example.com", "path" => "/hello/", "method" => "GET", "ua" => "", "query" => array(), "cookies" => array());' .
				'$file = \CosmicCache\Cache\Vary::resolveFile($settings, $env, 0);' .
				'@mkdir(dirname($file), 0755, true);' .
				'file_put_contents($file, "CACHED_FRESH");' .
				'touch($file, time());' .
				'$_SERVER = array("REQUEST_METHOD" => "GET", "HTTP_ACCEPT_ENCODING" => "", "HTTP_HOST" => "example.com", "REQUEST_URI" => "/hello/", "HTTPS" => "on");' .
				'register_shutdown_function(function () use ($file, $root) { echo "\n__RC__" . http_response_code() . "\n"; @unlink($file); @unlink($root . "/config.php"); @rmdir($root . "/https/example.com"); @rmdir($root . "/https"); @rmdir($root); });' .
				'\\' . Serve::class . '::dispatch();' .
				'echo "__UNREACHED__";'
			);

			$this->assertSame( 0, $status );
			$this->assertStringContainsString( 'CACHED_FRESH', $out );
			$this->assertStringContainsString( '__RC__200', $out );
			$this->assertStringNotContainsString( '__UNREACHED__', $out );
		}

		public function test_dispatch_expired_entry_deletes_variants_and_reboots(): void {
			$old        = time() - 7200; // older than the 3600s TTL.
			list( $out, $status ) = $this->run_child(
				'$root = sys_get_temp_dir() . "/ccdispatch-" . bin2hex(random_bytes(4));' .
				'define("COSMIC_CACHE_DIR", $root);' .
				'$settings = ' . var_export( $this->settings(), true ) . ';' .
				'@mkdir($root, 0755, true);' .
				'file_put_contents($root . "/config.php", "<?php\n// Cosmic Cache runtime config. Generated 2026-01-01T00:00:00+00:00. Do not edit.\nreturn " . var_export($settings, true) . ";\n");' .
				'$env = array("scheme" => "https", "host" => "example.com", "path" => "/hello/", "method" => "GET", "ua" => "", "query" => array(), "cookies" => array());' .
				'$file = \CosmicCache\Cache\Vary::resolveFile($settings, $env, 0);' .
				'@mkdir(dirname($file), 0755, true);' .
				'file_put_contents($file, "STALE_BODY");' .
				'file_put_contents($file . ".gz", gzencode("STALE_BODY"));' .
				'touch($file, ' . $old . ');' .
				'touch($file . ".gz", ' . $old . ');' .
				'$_SERVER = array("REQUEST_METHOD" => "GET", "HTTP_ACCEPT_ENCODING" => "", "HTTP_HOST" => "example.com", "REQUEST_URI" => "/hello/", "HTTPS" => "on");' .
				'register_shutdown_function(function () use ($file, $root) { echo "\n__EXIST__" . (is_file($file) ? "yes" : "no") . "\n"; echo "\n__GZ__" . (is_file($file . ".gz") ? "yes" : "no") . "\n"; @rmdir(dirname($file)); @rmdir($root . "/https"); @rmdir($root); });' .
				'\\' . Serve::class . '::dispatch();' .
				'echo "\n__REBOOTED__\n";'
			);

			$this->assertSame( 0, $status );
			// Expired entry is deleted (html + variant) and treated as a miss.
			$this->assertStringContainsString( '__EXIST__no', $out );
			$this->assertStringContainsString( '__GZ__no', $out );
			$this->assertStringContainsString( '__REBOOTED__', $out );
			$this->assertStringNotContainsString( 'STALE_BODY', $out );
		}

		/**
		 * sendfileHeader() must only emit a header for the two server-assisted
		 * strategies, only for paths inside the cache root, and fail open (null)
		 * otherwise so the hot path falls back to readfile().
		 */
		public function test_sendfile_header_php_default_is_null(): void {
			$this->assertNull( Serve::sendfileHeader( $this->settings(), '/tmp/cosmic/root/x.html' ) );
			$this->assertNull( Serve::sendfileHeader( array( 'serve_method' => 'bogus' ), '/tmp/cosmic/root/x.html' ) );
		}

		public function test_sendfile_header_x_sendfile_uses_absolute_path(): void {
			$root = \CosmicCache\Cache\Vary::cacheRoot();
			$this->assertSame(
				'X-Sendfile: ' . $root . '/http/example.com/abc.html',
				Serve::sendfileHeader( array( 'serve_method' => 'x-sendfile' ), $root . '/http/example.com/abc.html' )
			);
		}

		public function test_sendfile_header_x_accel_maps_path_under_internal_uri(): void {
			$root = \CosmicCache\Cache\Vary::cacheRoot();
			$this->assertSame(
				'X-Accel-Redirect: /cosmic-cache/http/example.com/abc.html',
				Serve::sendfileHeader( array( 'serve_method' => 'x-accel-redirect' ), $root . '/http/example.com/abc.html' )
			);
		}

		public function test_sendfile_header_never_emits_outside_cache_root(): void {
			$root = \CosmicCache\Cache\Vary::cacheRoot();
			$this->assertNull( Serve::sendfileHeader( array( 'serve_method' => 'x-sendfile' ), '/etc/passwd' ) );
			$this->assertNull( Serve::sendfileHeader( array( 'serve_method' => 'x-sendfile' ), $root . '-else/http/example.com/abc.html' ) );

			$this->assertNull( Serve::sendfileHeader( array( 'serve_method' => 'x-accel-redirect' ), '/etc/shadow' ) );
		}
	}
}
