<?php
/**
 * Bypass unit tests (hot-path rules).
 *
 * @package cosmic-cache
 */

namespace CosmicCache\Tests;

use CosmicCache\Cache\Bypass;
use PHPUnit\Framework\TestCase;

class BypassTest extends TestCase {

	private function settings( array $overrides = array() ): array {
		return array_merge(
			array(
				'cache_logged_in'       => false,
				'bypass_query'          => array(),
				'cache_whitelist_query' => array( 'p', 'paged' ),
				'exclude_cookies'       => array( 'wordpress_logged_in_*', 'comment_author_*' ),
				'exclude_urls'          => array(),
				'bypass_user_agents'    => array(),
			),
			$overrides
		);
	}

	private function env( array $overrides = array() ): array {
		return array_merge(
			array(
				'method'  => 'GET',
				'query'   => array(),
				'cookies' => array(),
				'path'    => '/hello-world/',
				'ua'      => 'Mozilla/5.0',
			),
			$overrides
		);
	}

	public function test_non_get_method_bypasses(): void {
		$this->assertTrue( Bypass::shouldBypassHotPath( $this->settings(), $this->env( array( 'method' => 'POST' ) ) ) );
	}

	public function test_head_is_cacheable(): void {
		$this->assertFalse( Bypass::shouldBypassHotPath( $this->settings(), $this->env( array( 'method' => 'HEAD' ) ) ) );
	}

	public function test_nonce_bypasses(): void {
		$this->assertTrue( Bypass::shouldBypassHotPath( $this->settings(), $this->env( array( 'query' => array( '_wpnonce' => 'abc' ) ) ) ) );
	}

	public function test_bypass_query_param(): void {
		$settings = $this->settings( array( 'bypass_query' => array( 'preview' ) ) );
		$this->assertTrue( Bypass::shouldBypassHotPath( $settings, $this->env( array( 'query' => array( 'preview' => '1' ) ) ) ) );
	}

	public function test_non_whitelisted_param_bypasses(): void {
		$this->assertTrue( Bypass::shouldBypassHotPath( $this->settings(), $this->env( array( 'query' => array( 's' => 'search' ) ) ) ) );
	}

	public function test_whitelisted_param_ok(): void {
		$this->assertFalse( Bypass::shouldBypassHotPath( $this->settings(), $this->env( array( 'query' => array( 'p' => '42' ) ) ) ) );
	}

	public function test_feed_bypass_even_when_whitelisted(): void {
		$settings = $this->settings( array( 'cache_whitelist_query' => array( 'p', 'feed' ) ) );
		$this->assertTrue( Bypass::shouldBypassHotPath( $settings, $this->env( array( 'query' => array( 'feed' => 'rss2' ) ) ) ) );
	}

	public function test_nocache_bust_param_bypasses(): void {
		$this->assertTrue( Bypass::shouldBypassHotPath( $this->settings(), $this->env( array( 'query' => array( 'nocache' => '1' ) ) ) ) );
	}

	public function test_logged_in_cookie_bypasses_when_disabled(): void {
		$this->assertTrue(
			Bypass::shouldBypassHotPath(
				$this->settings(),
				$this->env( array( 'cookies' => array( 'wordpress_logged_in_x' => 'u|1' ) ) )
			)
		);
	}

	public function test_logged_in_cookie_moves_to_vary_when_enabled(): void {
		$settings = $this->settings( array( 'cache_logged_in' => true ) );
		$this->assertFalse(
			Bypass::shouldBypassHotPath(
				$settings,
				$this->env( array( 'cookies' => array( 'wordpress_logged_in_x' => 'u|1' ) ) )
			)
		);
	}

	public function test_comment_author_cookie_bypasses_even_when_logged_in_enabled(): void {
		$settings = $this->settings( array( 'cache_logged_in' => true ) );
		$this->assertTrue(
			Bypass::shouldBypassHotPath(
				$settings,
				$this->env( array( 'cookies' => array( 'comment_author_abc' => 'x' ) ) )
			)
		);
	}

	public function test_protected_paths(): void {
		foreach ( array( '/wp-admin/', '/wp-login.php', '/wp-json/', '/robots.txt', '/category/tech/feed/' ) as $path ) {
			$this->assertTrue( Bypass::shouldBypassHotPath( $this->settings(), $this->env( array( 'path' => $path ) ) ), "expected bypass for {$path}" );
		}
	}

	public function test_exclude_urls_wildcard(): void {
		$settings = $this->settings( array( 'exclude_urls' => array( '/checkout*', '/restricted*' ) ) );
		$this->assertTrue( Bypass::shouldBypassHotPath( $settings, $this->env( array( 'path' => '/checkout/' ) ) ) );
		$this->assertFalse( Bypass::shouldBypassHotPath( $settings, $this->env( array( 'path' => '/shop/' ) ) ) );
	}

	public function test_bypass_user_agents_regex(): void {
		$settings = $this->settings( array( 'bypass_user_agents' => array( '~bot|crawl~i' ) ) );
		$this->assertTrue( Bypass::shouldBypassHotPath( $settings, $this->env( array( 'ua' => 'Googlebot/2.1' ) ) ) );
	}

	public function test_matches_wildcard_and_regex(): void {
		$this->assertTrue( Bypass::matches( 'wordpress_logged_in_*', 'wordpress_logged_in_123' ) );
		$this->assertTrue( Bypass::matches( '~^/downloads~', '/downloads/file.zip' ) );
		$this->assertFalse( Bypass::matches( 'wordpress_logged_in_*', 'comment_author_123' ) );
	}

	public function test_clean_request_is_cacheable(): void {
		$this->assertFalse( Bypass::shouldBypassHotPath( $this->settings(), $this->env() ) );
	}
}
