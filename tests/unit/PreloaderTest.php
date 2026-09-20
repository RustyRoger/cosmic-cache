<?php
/**
 * Regression tests for the Preloader crawl path (SPEC §7.7).
 *
 * fetch_many() previously passed an array of URLs as the first argument of
 * wp_remote_get(), which is not supported and fatals inside WordPress
 * (wp_kses_bad_protocol). The fix loops and passes a single URL string; the
 * subprocess spy test below would have caught the array argument.
 *
 * @package cosmic-cache
 */

namespace CosmicCache\Tests {

	use CosmicCache\Cache\Preloader;
	use PHPUnit\Framework\TestCase;

	class PreloaderTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['cosmic_test_transients'] = array();
		}

		/**
		 * A minimal settings array.
		 *
		 * @return array<string,mixed>
		 */
		private function settings(): array {
			return array(
				'enabled'               => true,
				'ttl'                   => 3600,
				'gzip'                  => false,
				'brotli'                => false,
				'cache_mobile'          => false,
				'cache_cookies'         => array(),
				'cache_logged_in'       => false,
				'cache_whitelist_query' => array(),
				'blogs'                 => array(),
				'preload_interval'      => 3600,
			);
		}

		/**
		 * A child-process stub environment for queue/lock/robot tests.
		 * Each child gets its own WP_CONTENT_DIR so no state leaks between runs.
		 *
		 * @return string
		 */
		private function child_helpers(): string {
			$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
			return 'require ' . var_export( $autoload, true ) . '; ' .
				'define("WP_CONTENT_DIR", sys_get_temp_dir() . "/cc-preload-" . bin2hex(random_bytes(4)));' .
				'@mkdir(WP_CONTENT_DIR . "/cache/cosmic", 0777, true);' .
				'define("HOUR_IN_SECONDS", 3600);' .
				'$GLOBALS["TS"] = array();' .
				'$GLOBALS["http"] = array();' .
				'function home_url($p = "", $s = null) { return "https://example.com/" . ltrim((string) $p, "/"); }' .
				'function get_transient($k) { return isset($GLOBALS["TS"][$k]) ? $GLOBALS["TS"][$k] : false; }' .
				'function set_transient($k, $v, $t = 0) { $GLOBALS["TS"][$k] = $v; return true; }' .
				'function delete_transient($k) { unset($GLOBALS["TS"][$k]); return true; }' .
				'function is_wp_error($r) { return $r instanceof WP_Error; }' .
				'class WP_Error { public $code = ""; public function __construct($c = "") { $this->code = $c; } }' .
				'function wp_remote_retrieve_body($r) { return is_wp_error($r) ? "" : $r->body; }' .
				'function apply_filters($t, $v, ...$a) { return $v; }' .
				'function do_action(...$a) {}';
		}

		public function test_run_drains_queue_in_bounded_batches(): void {
			$code = $this->child_helpers() .
				'$GLOBALS["http"] = array();' .
				'function wp_remote_get($url, $args = array()) { $GLOBALS["http"][$url] = 1; return new WP_Error("http"); }' .
				'function wp_schedule_single_event($t, $h) { $GLOBALS["sched"] = $h; }' .
				'function wp_next_scheduled($h) { return false; }' .
				'$settings = ' . var_export( $this->settings(), true ) . ';' .
				'function qsize() { $q = get_transient("cosmic_cache_preload_queue"); return is_array($q) ? count($q) : 0; }' .
				// Seed a queue wider than one batch (BATCH_MAX = 50).
				'$queue = array(); for ($i = 1; $i <= 60; $i++) { $queue[] = "https://example.com/page/" . $i . "/"; }' .
				'set_transient("cosmic_cache_preload_queue", $queue, 43200);' .
				'$p = new ' . Preloader::class . '( $settings );' .
				'$r1 = $p->run();' .
				'$left1 = qsize();' .
				'$r2 = $p->run();' .
				'$left2 = qsize();' .
				'echo "__R1__" . json_encode($r1) . "__LEFT1__" . $left1 . "__R2__" . json_encode($r2) . "__LEFT2__" . $left2 . "__SCHED__" . (isset($GLOBALS["sched"]) ? $GLOBALS["sched"] : "none");';

			exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code ) . ' 2>&1', $out, $status );
			$output     = implode( "\n", (array) $out );
			$this->assertSame( 0, (int) $status );

			$this->assertStringContainsString( '__R1__[50,0]', $output );
			$this->assertStringContainsString( '__LEFT1__10', $output );
			$this->assertStringContainsString( '__R2__[10,0]', $output );
			$this->assertStringContainsString( '__LEFT2__0', $output );
			$this->assertStringContainsString( '__SCHED__cosmic_cache_preload', $output );
		}

		public function test_run_respects_overlap_lock(): void {
			$settings = $this->settings();
			$pre      = new Preloader( $settings );

			// A lease already held by a concurrent run → refuse to run.
			$GLOBALS['cosmic_test_transients']['cosmic_cache_preload_lock'] = time() - 30;
			$this->assertSame( array( 0, 0 ), $pre->run() );
			unset( $GLOBALS['cosmic_test_transients']['cosmic_cache_preload_lock'] );

			// A stale lease (older than the 300s timeout) is ignored.
			$GLOBALS['cosmic_test_transients']['cosmic_cache_preload_lock'] = time() - 3600;
			$this->assertSame( array( 0, 0 ), $pre->run() );
		}

		public function test_url_eligible_applies_exclude_urls_and_bypass_agents(): void {
			$settings = $this->settings();
			$settings['exclude_urls']        = array( '/private*' );
			$settings['bypass_user_agents']  = array( 'OtherBot*' );

			$method = new \ReflectionMethod( Preloader::class, 'url_eligible' );
			$method->setAccessible( true );
			$pre = new Preloader( $settings );

			// Matches exclude_urls.
			$this->assertFalse( $method->invoke( $pre, 'https://example.com/private/x/' ) );
			// Public path is eligible.
			$this->assertTrue( $method->invoke( $pre, 'https://example.com/about/' ) );
			// Our UA is not OtherBot, so the bypass agent rule does not apply.
			$this->assertTrue( $method->invoke( $pre, 'https://example.com/news/' ) );
		}

		public function test_url_eligible_bypasses_when_own_agent_matches(): void {
			$settings = $this->settings();
			$settings['bypass_user_agents'] = array( '*Preloader*' );

			$method = new \ReflectionMethod( Preloader::class, 'url_eligible' );
			$method->setAccessible( true );
			$pre = new Preloader( $settings );

			$this->assertFalse( $method->invoke( $pre, 'https://example.com/page/' ) );
		}

		public function test_robots_honours_agent_groups_and_prefix_match(): void {
			$code = $this->child_helpers() .
				'$GLOBALS["robots_body"] = ' .
				'"User-agent: *\nDisallow: /private\n\nUser-agent: Cosmic Cache Preloader\nDisallow: /admin\nAllow: /admin/public\n";' .
				'function wp_remote_get($url, $args = array()) {' .
				'  $GLOBALS["http"][(string)$url] = 1;' .
				'  if (strpos($url, "robots.txt") !== false) {' .
				'    return (object) array("body" => $GLOBALS["robots_body"]);' .
				'  }' .
				'  return new WP_Error("http");' .
				'}' .
				'$settings = ' . var_export( $this->settings(), true ) . ';' .
				'$p = new ' . Preloader::class . '( $settings );' .
				'$m = new ReflectionMethod(' . var_export( Preloader::class, true ) . ', "robots_allowed");' .
				'$m->setAccessible(true);' .
				'$admin = $m->invoke($p, "https://example.com/admin/private/");' .
				'$admin_public = $m->invoke($p, "https://example.com/admin/public/page/");' .
				// The '*' group does not apply: our UA matches a specific group.
				'$private = $m->invoke($p, "https://example.com/private/x/");' .
				'$page = $m->invoke($p, "https://example.com/page/");' .
				'echo "__ADMIN__" . ($admin ? "yes" : "no") . "__ADMINPUB__" . ($admin_public ? "yes" : "no") . "__PRIVATE__" . ($private ? "yes" : "no") . "__PAGE__" . ($page ? "yes" : "no") . "__ROBOTS_FETCHED__" . (isset($GLOBALS["http"]["https://example.com/robots.txt"]) ? "yes" : "no");';

			exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code ) . ' 2>&1', $out, $status );
			$output = implode( "\n", (array) $out );

			$this->assertSame( 0, (int) $status );
			$this->assertStringContainsString( '__ADMIN__no', $output );
			// A longer Allow under the same group wins on equal-prefix boundary.
			$this->assertStringContainsString( '__ADMINPUB__yes', $output );
			// The '*' group is overridden by the more specific agent group.
			$this->assertStringContainsString( '__PRIVATE__yes', $output );
			$this->assertStringContainsString( '__PAGE__yes', $output );
			$this->assertStringContainsString( '__ROBOTS_FETCHED__yes', $output );
		}

		public function test_robots_empty_result_is_not_cached(): void {
			$code = $this->child_helpers() .
				'$GLOBALS["robots_body"] = "";' .
				'function wp_remote_get($url, $args = array()) {' .
				'  if (strpos($url, "robots.txt") !== false) {' .
				'    return (object) array("body" => $GLOBALS["robots_body"]);' .
				'  }' .
				'  return new WP_Error("http");' .
				'}' .
				'$settings = ' . var_export( $this->settings(), true ) . ';' .
				'$p = new ' . Preloader::class . '( $settings );' .
				'$m = new ReflectionMethod(' . var_export( Preloader::class, true ) . ', "robots_allowed");' .
				'$m->setAccessible(true);' .
				'$allowed = $m->invoke($p, "https://example.com/page/");' .
				'$cached = array_key_exists("cosmic_robots_txt", $GLOBALS["TS"]);' .
				'echo "__ALLOWED__" . ($allowed ? "yes" : "no") . "__CACHED__" . ($cached ? "yes" : "no");';

			exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code ) . ' 2>&1', $out, $status );
			$output = implode( "\n", (array) $out );

			$this->assertSame( 0, (int) $status );
			$this->assertStringContainsString( '__ALLOWED__yes', $output );
			$this->assertStringContainsString( '__CACHED__no', $output );
		}

		public function test_stored_counts_actual_cache_writes(): void {
			$code = $this->child_helpers() .
				'function wp_remote_get($url, $args = array()) {' .
				'  $GLOBALS["http"][(string)$url] = 1;' .
				// robots.txt yields nothing cacheable; only /page/ simulates a
				// capture-path write of its cache file (/other/ does not land).
				'  if (strpos($url, "robots.txt") !== false) {' .
				'    return (object) array("body" => "");' .
				'  }' .
				'  $env = array("scheme" => "https", "host" => "example.com", "path" => parse_url($url, PHP_URL_PATH), "method" => "GET", "ua" => "", "query" => array(), "cookies" => array());' .
				'  $f = \CosmicCache\Cache\Vary::resolveFile($GLOBALS["cc_settings"], $env, 0);' .
				'  if ("/page/" === $env["path"]) { @mkdir(dirname($f), 0777, true); file_put_contents($f, "<html>preloaded</html>"); }' .
				'  return (object) array("body" => "<html>preloaded</html>");' .
				'}' .
				'set_transient("cosmic_cache_preload_queue", array("https://example.com/page/", "https://example.com/other/"), 43200);' .
				'set_transient("cosmic_cache_preload_done", array(), 604800);' .
				'$settings = ' . var_export( $this->settings(), true ) . ';' .
				'$GLOBALS["cc_settings"] = $settings;' .
				'$p = new ' . Preloader::class . '( $settings );' .
				'$r = $p->run();' .
				'echo "__METRICS__" . json_encode($r);';

			exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code ) . ' 2>&1', $out, $status );
			$output = implode( "\n", (array) $out );

			$this->assertSame( 0, (int) $status );
			// stored=1 only for the URL whose cache file actually landed on disk.
			$this->assertStringContainsString( '__METRICS__[2,1]', $output );
		}

		public function test_time_budget_is_positive_and_bounded(): void {
			$method = new \ReflectionMethod( Preloader::class, 'time_budget_seconds' );
			$method->setAccessible( true );

			$this->assertGreaterThanOrEqual( 1, $method->invoke( new Preloader( $this->settings() ) ) );
		}

		public function test_fetch_many_returns_one_status_per_url(): void {
			$method = new \ReflectionMethod( Preloader::class, 'fetch_many' );
			$method->setAccessible( true );

			$result = $method->invoke( new Preloader( $this->settings() ), array(
				'https://example.com/a',
				'https://example.com/b',
			) );

			$this->assertSame( array( false, false ), $result );
		}

		public function test_fetch_many_passes_string_urls_to_wp_remote_get(): void {
			$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
			$script   = 'require ' . var_export( $autoload, true ) . '; ' .
				'define("WP_CONTENT_DIR", sys_get_temp_dir() . "/cc-preload");' .
				'@mkdir(WP_CONTENT_DIR . "/cache/cosmic", 0777, true);' .
				'define("HOUR_IN_SECONDS", 3600);' .
				'function home_url($p = "", $s = null) { return "https://example.com" . ltrim((string) $p, "/"); }' .
				'function get_transient($k) { return false; }' .
				'function set_transient($k, $v, $t = 0) {}' .
				'function is_wp_error($r) { return $r instanceof WP_Error; }' .
				'class WP_Error { public $code = ""; public function __construct($c = "") { $this->code = $c; } }' .
				'$GLOBALS["cc_http_calls"] = array();' .
				'function wp_remote_get($url, $args = array()) { $GLOBALS["cc_http_calls"][] = array("url" => $url); return new WP_Error("http"); }' .
				'$p = new ' . Preloader::class . '(' . var_export( $this->settings(), true ) . ');' .
				'$m = new ReflectionMethod(' . var_export( Preloader::class, true ) . ', "fetch_many");' .
				'$m->setAccessible(true);' .
				'$out = $m->invoke($p, array("https://example.com/a", "https://example.com/b"));' .
				'$bad = 0;' .
				'foreach ($GLOBALS["cc_http_calls"] as $c) { if (!is_string($c["url"])) { $bad++; } }' .
				'echo "__BAD__" . $bad . "__RESULT__" . json_encode($out);';

			exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $script ) . ' 2>&1', $out, $status );
			$output = implode( "\n", (array) $out );

			$this->assertSame( 0, (int) $status );
			// No wp_remote_get call may have received a non-string (array) URL.
			$this->assertStringContainsString( '__BAD__0', $output );
			$this->assertStringContainsString( '__RESULT__[false,false]', $output );
		}

		public function test_sitemap_loc_from_foreign_host_is_never_fetched(): void {
			$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
			$script   = 'require ' . var_export( $autoload, true ) . '; ' .
				'define("WP_CONTENT_DIR", sys_get_temp_dir() . "/cc-preload-ssrf");' .
				'@mkdir(WP_CONTENT_DIR . "/cache/cosmic", 0777, true);' .
				'define("HOUR_IN_SECONDS", 3600);' .
				'function home_url($p = "", $s = null) { return "https://example.com" . ltrim((string) $p, "/"); }' .
				'function get_transient($k) { return false; }' .
				'function set_transient($k, $v, $t = 0) {}' .
				'function is_wp_error($r) { return $r instanceof WP_Error; }' .
				'class WP_Error { public $code = ""; public function __construct($c = "") { $this->code = $c; } }' .
				'$GLOBALS["cc_http_calls"] = array();' .
				'function wp_remote_get($url, $args = array()) {' .
				'  $GLOBALS["cc_http_calls"][] = (string) $url;' .
				'  if ($url === "https://example.com/sitemap.xml") {' .
				'    return (object) array("body" => "<?xml version=\"1.0\"?><urlset><url><loc>https://example.com/hello/</loc></url><url><loc>https://evil.example.com/steal/</loc></url></urlset>");' .
				'  }' .
				'  return new WP_Error("http");' .
				'}' .
				'function wp_remote_retrieve_body($r) { return is_wp_error($r) ? "" : (is_array($r) ? $r["body"] : $r->body); }' .
				'$settings = array(' .
				'  "enabled" => true, "ttl" => 3600, "gzip" => false, "brotli" => false,' .
				'  "cache_mobile" => false, "cache_cookies" => array(), "cache_logged_in" => false,' .
				'  "cache_whitelist_query" => array(), "blogs" => array(),' .
				'  "preload_sitemap" => "https://example.com/sitemap.xml",' .
				');' .
				'$p = new ' . Preloader::class . '( $settings );' .
				'$m = new ReflectionMethod(' . var_export( Preloader::class, true ) . ', "urls_from_sitemap");' .
				'$m->setAccessible(true);' .
				'$out = $m->invoke($p, "https://example.com/sitemap.xml");' .
				'$foreign = array_filter($GLOBALS["cc_http_calls"], function ($u) { return strpos($u, "evil.example.com") !== false; });' .
				'echo "__FOREIGN__" . count($foreign) . "__URLS__" . json_encode($out);';

			exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $script ) . ' 2>&1', $out, $status );
			$output = implode( "\n", (array) $out );

			$this->assertSame( 0, (int) $status );
			// The evil host must never be fetched, and only the same-host page kept.
			$this->assertStringContainsString( '__FOREIGN__0', $output );
			$json  = substr( $output, strpos( $output, '__URLS__' ) + strlen( '__URLS__' ) );
			$urls  = json_decode( $json, true );
			$this->assertSame( array( 'https://example.com/hello/' ), $urls );
		}
	}
}
