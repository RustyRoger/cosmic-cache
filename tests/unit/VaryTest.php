<?php
/**
 * Vary unit tests.
 *
 * @package cosmic-cache
 */

namespace CosmicCache\Tests;

use CosmicCache\Cache\Vary;
use PHPUnit\Framework\TestCase;

class VaryTest extends TestCase {

	private function settings( array $overrides = array() ): array {
		return array_merge(
			array(
				'cache_mobile'          => false,
				'cache_logged_in'       => false,
				'cache_cookies'         => array(),
				'cache_whitelist_query' => array( 'p', 'page', 'paged', 'cpage', 'utm_source' ),
			),
			$overrides
		);
	}

	private function env( array $overrides = array() ): array {
		return array_merge(
			array(
				'scheme'  => 'https',
				'host'    => 'example.com',
				'path'    => '/hello-world/',
				'method'  => 'GET',
				'ua'      => 'Mozilla/5.0',
				'query'   => array(),
				'cookies' => array(),
			),
			$overrides
		);
	}

	public function test_hash_key_keeps_only_whitelisted_in_canonical_order(): void {
		$settings = $this->settings();
		$env      = $this->env( array( 'query' => array( 'b' => '2', 'paged' => '3', 's' => 'search', 'utm_source' => 'x' ) ) );

		$this->assertSame( 'https://example.com/hello-world/?paged=3&utm_source=x', Vary::hashKey( $settings, $env ) );
	}

	public function test_hash_key_lowercases_path(): void {
		$settings = $this->settings();
		$env      = $this->env( array( 'path' => '/Hello-World/' ) );

		$this->assertSame( 'https://example.com/hello-world/', Vary::hashKey( $settings, $env ) );
	}

	public function test_resolve_file_no_variants_single_site(): void {
		$settings = $this->settings();
		$env      = $this->env( array( 'path' => '/hello-world/' ) );

		$expected = sys_get_temp_dir() . '/cosmic-tests/wp-content/cache/cosmic/https/example.com/' . sha1( 'https://example.com/hello-world/' ) . '.html';
		$this->assertSame( $expected, Vary::resolveFile( $settings, $env, 0 ) );
	}

	public function test_resolve_file_includes_blog_segment(): void {
		$settings = $this->settings();
		$env      = $this->env();

		$file = Vary::resolveFile( $settings, $env, 2 );
		$this->assertStringContainsString( '/https/example.com/2/', $file );
	}

	public function test_vary_suffix_mobile(): void {
		$settings = $this->settings( array( 'cache_mobile' => true ) );
		$env      = $this->env( array( 'ua' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0)' ) );

		$suffix = Vary::varySuffix( $settings, $env );
		$this->assertStringStartsWith( '.vary-', $suffix );
	}

	public function test_vary_suffix_empty_for_desktop(): void {
		$settings = $this->settings( array( 'cache_mobile' => true ) );
		$env      = $this->env( array( 'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)' ) );

		$this->assertSame( '', Vary::varySuffix( $settings, $env ) );
	}

	public function test_vary_suffix_cookie_values(): void {
		$settings = $this->settings( array( 'cache_cookies' => array( 'mycookie', 'other' ) ) );
		$env      = $this->env( array( 'cookies' => array( 'mycookie' => 'v1' ) ) );

		$suffix = Vary::varySuffix( $settings, $env );
		$this->assertSame( '.vary-' . sha1( 'mycookie=v1' ), $suffix );
	}

	public function test_vary_suffix_logged_in_cookie_when_enabled(): void {
		$settings = $this->settings( array( 'cache_logged_in' => true ) );
		$env      = $this->env( array( 'cookies' => array( 'wordpress_logged_in_abc' => 'user|1|123|hash' ) ) );

		$suffix = Vary::varySuffix( $settings, $env );
		$this->assertStringStartsWith( '.vary-', $suffix );
	}

	public function test_sanitize_host_strips_port_and_userinfo(): void {
		$this->assertSame( 'example.com', Vary::sanitizeHost( 'Example.COM:8080' ) );
		$this->assertSame( 'example.com', Vary::sanitizeHost( 'user:pass@example.com' ) );
		$this->assertSame( '', Vary::sanitizeHost( 'bad host!' ) );
	}

	public function test_sanitize_host_rejects_dot_traversal_labels(): void {
		// `..`/`.` labels would escape the cache-root host dir.
		$this->assertSame( '', Vary::sanitizeHost( '..' ) );
		$this->assertSame( '', Vary::sanitizeHost( '.' ) );
		$this->assertSame( '', Vary::sanitizeHost( 'a..b' ) );
		$this->assertSame( '', Vary::sanitizeHost( 'example..com' ) );
	}

	public function test_is_mobile(): void {
		$this->assertTrue( Vary::isMobile( 'Mozilla/5.0 (iPad)' ) );
		$this->assertTrue( Vary::isMobile( 'Opera Mini/7' ) );
		$this->assertFalse( Vary::isMobile( 'Mozilla/5.0 (X11; Linux x86_64)' ) );
	}

	public function test_blog_id_resolved_from_compiled_config(): void {
		$settings = $this->settings( array( 'blogs' => array( 'sub.example.com' => 3 ) ) );
		$this->assertSame( 3, Vary::blogId( $settings, 'sub.example.com' ) );
		$this->assertSame( 0, Vary::blogId( $settings, 'other.example.com' ) );
	}

	public function test_has_variants(): void {
		$this->assertTrue( Vary::hasVariants( $this->settings( array( 'cache_cookies' => array( 'x' ) ) ) ) );
		$this->assertFalse( Vary::hasVariants( $this->settings() ) );
	}

	public function test_request_env_honors_x_forwarded_proto_over_plain_http(): void {
		$_SERVER = array( 'REQUEST_METHOD' => 'GET', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_HOST' => 'example.com' );
		$this->assertSame( 'https', Vary::requestEnv()['scheme'] );
	}

	public function test_request_env_x_forwarded_proto_http_wins_over_secure_port(): void {
		$_SERVER = array(
			'REQUEST_METHOD'       => 'GET',
			'HTTP_X_FORWARDED_PROTO' => 'http',
			'SERVER_PORT'          => 443,
			'HTTP_HOST'            => 'example.com',
		);
		$this->assertSame( 'http', Vary::requestEnv()['scheme'] );
	}

	public function test_request_env_takes_first_comma_separated_forwarded_value(): void {
		$_SERVER = array(
			'REQUEST_METHOD'       => 'GET',
			'HTTP_X_FORWARDED_PROTO' => 'https, http',
			'HTTP_HOST'            => 'example.com',
		);
		$this->assertSame( 'https', Vary::requestEnv()['scheme'] );
	}

	public function test_request_env_falls_back_to_https_flag(): void {
		$_SERVER = array( 'REQUEST_METHOD' => 'GET', 'HTTPS' => 'on', 'HTTP_HOST' => 'example.com' );
		$this->assertSame( 'https', Vary::requestEnv()['scheme'] );
	}

	public function test_request_env_defaults_to_http(): void {
		$_SERVER = array( 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com' );
		$this->assertSame( 'http', Vary::requestEnv()['scheme'] );
	}
}
