<?php
/**
 * Preloader: background crawl to warm the cache (SPEC §7.7).
 *
 * Queue-based: discovered URLs are persisted in a transient queue and drained
 * in bounded batches per invocation (resuming on the next cron tick). A
 * transient mutex prevents overlapping runs; leases carry a stale-lock
 * timeout (E4). robots.txt is parsed by User-agent groups with prefix-match
 * rules and a short (≤10 min) cache that never stores empty results (E2).
 * Excluded URLs and bypassed user agents are honoured so preloaded URLs match
 * real visitor behaviour (E3). `stored` counts actual cache writes — a URL is
 * only counted when its file landed on disk — not mere HTTP 200s (E5). The
 * per-invocation batch respects max_execution_time and resumes via the queue
 * (E6).
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache\Cache;

final class Preloader {

	/** URLs drained per invocation (E1 bounded batch). */
	private const BATCH_MAX = 50;

	/** Transient mutex: blocks overlapping runs (E4). */
	private const LOCK_KEY = 'cosmic_cache_preload_lock';
	// Stale-lock timeout: a crashed run releases the lease after 5 minutes.
	private const LOCK_TTL = 300;

	/** Persistent URL queue (E1/E6 resume). */
	private const QUEUE_KEY = 'cosmic_cache_preload_queue';
	private const QUEUE_TTL = 43200;

	/** Processed set: skipped so a URL is not re-queued the same cycle. */
	private const DONE_KEY = 'cosmic_cache_preload_done';
	private const DONE_TTL = 604800;
	private const DONE_MAX = 1000;

	/** robots.txt transient (E2): short TTL, never store empty. */
	private const ROBOTS_KEY = 'cosmic_cache_robots_txt';
	private const ROBOTS_TTL = 600;

	/** Preloader user agent sent with each request. */
	private const USER_AGENT = 'Cosmic Cache Preloader/1.0';

	/**
	 * Settings snapshot for this run.
	 *
	 * @var array<string,mixed>
	 */
	private $settings;

	/**
	 * Max URLs per discovery pass (fallback crawl).
	 *
	 * @var int
	 */
	private $max_urls;

	/**
	 * @param array<string,mixed> $settings
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
		$this->max_urls = 500;
	}

	/**
	 * Run one preload batch.
	 *
	 * @return array{0:int,1:int} [requested, stored]
	 */
	public function run(): array {
		if ( empty( $this->settings['enabled'] ) ) {
			return array( 0, 0 );
		}

		if ( ! $this->acquire_lock() ) {
			return array( 0, 0 );
		}

		try {
			$queue = $this->load_queue();
			if ( empty( $queue ) ) {
				$queue = $this->discover_urls();
				$queue = apply_filters( 'cosmic_cache_preload_urls', $queue, $this->settings );
				$queue = array_values( array_unique( array_map( 'strval', $queue ) ) );
				$queue = $this->filter_queued( $queue );
			}

			$requested = 0;
			$stored    = 0;
			$done      = array();
			$deadline  = time() + $this->time_budget_seconds();

			foreach ( $queue as $url ) {
				if ( count( $done ) >= self::BATCH_MAX || time() >= $deadline ) {
					break;
				}
				if ( ! $this->url_eligible( $url ) ) {
					continue;
				}

				$done[] = $url;

				// Already-fresh entries are not requested.
				if ( $this->is_fresh( $url ) ) {
					continue;
				}

				if ( $this->warm( $url ) ) {
					++$stored;
				}
				++$requested;
			}

			$pending = array_values( array_diff( $queue, $done ) );
			$this->save_queue( $pending );
			$this->save_done( $done );

			// Resume the remaining queue on a later invocation.
			if ( $pending ) {
				$this->schedule_next();
			}

			do_action( 'cosmic_cache_preloaded', $done );

			return array( $requested, $stored );
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Try to acquire the overlap lock (E4). The transient TTL doubles as the
	 * stale-lock timeout: a crashed run releases itself automatically.
	 *
	 * @return bool
	 */
	private function acquire_lock(): bool {
		if ( false !== get_transient( self::LOCK_KEY ) ) {
			return false;
		}
		set_transient( self::LOCK_KEY, time(), self::LOCK_TTL );
		return true;
	}

	/**
	 * Release the overlap lock.
	 *
	 * @return void
	 */
	private function release_lock(): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::LOCK_KEY );
		}
	}

	/**
	 * Per-invocation time budget (E6): half of max_execution_time, floored at 1
	 * second, with a generous default when unlimited (CLI/cron).
	 *
	 * @return int Seconds.
	 */
	private function time_budget_seconds(): int {
		$limit = (int) ini_get( 'max_execution_time' );
		if ( $limit <= 0 ) {
			$limit = 30;
		}
		return max( 1, (int) floor( $limit / 2 ) );
	}

	/**
	 * Persist the remaining URL queue between invocations.
	 *
	 * @param array<int,string> $queue
	 * @return void
	 */
	private function save_queue( array $queue ): void {
		if ( empty( $queue ) ) {
			delete_transient( self::QUEUE_KEY );
			return;
		}
		set_transient( self::QUEUE_KEY, array_values( array_unique( $queue ) ), self::QUEUE_TTL );
	}

	/**
	 * Load the persisted queue.
	 *
	 * @return array<int,string>
	 */
	private function load_queue(): array {
		$queue = get_transient( self::QUEUE_KEY );
		if ( ! is_array( $queue ) ) {
			return array();
		}
		return array_values(
			array_filter(
				array_map( 'strval', $queue ),
				static fn( $url ): bool => '' !== $url
			)
		);
	}

	/**
	 * Merge newly processed URLs into the (bounded) done set.
	 *
	 * @param array<int,string> $done
	 * @return void
	 */
	private function save_done( array $done ): void {
		$previous = $this->done_set();
		$merged   = array_values( array_unique( array_merge( $previous, $done ) ) );
		set_transient( self::DONE_KEY, array_slice( $merged, -self::DONE_MAX ), self::DONE_TTL );
	}

	/**
	 * The processed set.
	 *
	 * @return array<int,string>
	 */
	private function done_set(): array {
		$done = get_transient( self::DONE_KEY );
		if ( ! is_array( $done ) ) {
			return array();
		}
		return array_values(
			array_filter(
				array_map( 'strval', $done ),
				static fn( $url ): bool => '' !== $url
			)
		);
	}

	/**
	 * Drop URLs already processed this cycle from a discovered list.
	 *
	 * @param array<int,string> $urls
	 * @return array<int,string>
	 */
	private function filter_queued( array $urls ): array {
		$done = array_flip( $this->done_set() );
		return array_values( array_filter( $urls, static function ( $url ) use ( $done ) {
			return ! isset( $done[ $url ] );
		} ) );
	}

	/**
	 * Schedule the next drain tick when work remains (E1/E6).
	 *
	 * @return void
	 */
	private function schedule_next(): void {
		if ( ! function_exists( 'wp_schedule_single_event' ) || ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}
		$interval = max( 60, (int) ( $this->settings['preload_interval'] ?? 3600 ) );
		if ( ! wp_next_scheduled( 'cosmic_cache_preload' ) ) {
			wp_schedule_single_event( time() + min( 600, $interval ), 'cosmic_cache_preload' );
		}
	}

	/**
	 * Is a discovered URL shaped like a real visitor request (E3)? Applies
	 * exclude_urls and bypass_user_agents so preloaded pages are cacheable the
	 * same way a browser's would be.
	 *
	 * @param string $url
	 * @return bool
	 */
	private function url_eligible( string $url ): bool {
		$env = $this->url_to_env( $url );
		if ( ! $env ) {
			return false;
		}

		$path = (string) ( $env['path'] ?? '/' );
		foreach ( (array) ( $this->settings['exclude_urls'] ?? array() ) as $pattern ) {
			if ( Bypass::matches( (string) $pattern, $path ) ) {
				return false;
			}
		}

		// If the preloader's own UA matches a bypass pattern, that page would
		// never be cached for it → skip to mirror real visitors.
		foreach ( (array) ( $this->settings['bypass_user_agents'] ?? array() ) as $pattern ) {
			if ( Bypass::matches( (string) $pattern, self::USER_AGENT ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Warm a single URL: fetch it, let the capture path store it, then verify
	 * the cache file actually exists before counting it (E5).
	 *
	 * @param string $url
	 * @return bool
	 */
	private function warm( string $url ): bool {
		if ( ! $this->robots_allowed( $url ) ) {
			return false;
		}
		if ( $this->is_fresh( $url ) ) {
			return false;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 3,
				'user-agent'  => self::USER_AGENT,
				'headers'     => array( 'X-Cosmic-Preload' => '1' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		// Stored == the page actually landed on disk, not HTTP 200.
		return $this->is_fresh( $url );
	}

	/**
	 * Discover URLs from the sitemap or a bounded fallback crawl.
	 *
	 * @return array<int,string>
	 */
	private function discover_urls(): array {
		$sitemap = trim( (string) ( $this->settings['preload_sitemap'] ?? '' ) );

		if ( '' !== $sitemap ) {
			$urls = $this->urls_from_sitemap( $sitemap );
			if ( $urls ) {
				return $urls;
			}
		}

		return $this->fallback_crawl();
	}

	/**
	 * Parse URLs from a sitemap document (supports sitemap index nesting).
	 *
	 * @param string $url
	 * @return array<int,string>
	 */
	private function urls_from_sitemap( string $url ): array {
		$found = array();
		$depth = 0;

		$queue = array( $url );
		while ( $queue && $depth < 3 && count( $found ) < $this->max_urls ) {
			$current = array_shift( $queue );

			// SSRF guard: only crawl the site's own origin (sitemap, nested
			// sitemap-index <loc>, and every discovered page).
			if ( ! $this->same_host( $current, home_url( '/' ) ) ) {
				continue;
			}

			$xml = $this->fetch( $current );

			if ( ! $xml ) {
				continue;
			}

			if ( preg_match_all( '#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+?)\s*(?:\]\]>)?\s*</loc>#i', $xml, $m ) ) {
				foreach ( $m[1] as $loc ) {
					$loc = html_entity_decode( trim( $loc ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					if ( strpos( $loc, '<?xml' ) !== false ) {
						continue;
					}
					if ( 0 === strpos( $loc, 'http' ) && $this->same_host( $loc, home_url( '/' ) ) ) {
						if ( preg_match( '/\.xml$/i', $loc ) && $depth < 2 ) {
							$queue[] = $loc;
						} else {
							$found[] = $loc;
						}
					}
					if ( count( $found ) >= $this->max_urls ) {
						break 2;
					}
				}
			}
			++$depth;
		}

		return $found;
	}

	/**
	 * Bounded fallback crawl: homepage + linked URLs, max depth 2.
	 *
	 * @return array<int,string>
	 */
	private function fallback_crawl(): array {
		$home  = home_url( '/' );
		$found = array( $home );
		$html  = $this->fetch( $home );

		if ( $html && preg_match_all( '#href=["\']([^"\']+)["\']#i', $html, $m ) ) {
			foreach ( $m[1] as $href ) {
				if ( count( $found ) >= $this->max_urls ) {
					break;
				}
				$absolute = $this->absolutize( $href, $home );
				if ( $absolute && $this->same_host( $absolute, $home ) ) {
					$found[] = $absolute;
				}
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Fetch a URL with a bounded timeout, skipping already-fresh files.
	 *
	 * @param string $url
	 * @return string|false Body.
	 */
	private function fetch( string $url ) {
		if ( ! $this->robots_allowed( $url ) ) {
			return false;
		}

		// Skip if already fresh in cache.
		if ( $this->is_fresh( $url ) ) {
			return false;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 5,
				'redirection' => 3,
				'user-agent' => self::USER_AGENT,
				'headers'    => array(
					'X-Cosmic-Preload' => '1',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Fetch several URLs concurrently.
	 *
	 * @param array<int,string> $urls
	 * @return array<int,bool> 1 = fetch attempted (and stored), 0 = skipped.
	 */
	private function fetch_many( array $urls ): array {
		$fresh = array();
		$todo  = array();
		foreach ( $urls as $i => $url ) {
			if ( $this->robots_allowed( $url ) && ! $this->is_fresh( $url ) ) {
				$todo[ $i ]  = $url;
				$fresh[ $i ] = true;
			} else {
				$fresh[ $i ] = false;
			}
		}

		if ( $todo ) {
			foreach ( $todo as $i => $url ) {
				$response = wp_remote_get(
					$url,
					array(
						'timeout'     => 5,
						'redirection' => 3,
						'user-agent'  => self::USER_AGENT,
						'headers'     => array( 'X-Cosmic-Preload' => '1' ),
					)
				);
				$fresh[ $i ] = ! is_wp_error( $response );
			}
		}

		$out = array();
		foreach ( $urls as $i => $url ) {
			$out[ $i ] = (bool) ( $fresh[ $i ] ?? false );
		}
		return $out;
	}

	/**
	 * Is the URL already cached and unexpired?
	 *
	 * @param string $url
	 * @return bool
	 */
	private function is_fresh( string $url ): bool {
		$env = $this->url_to_env( $url );
		if ( ! $env ) {
			return false;
		}

		// blogId() mirrors the hot-path resolution so preload checks hit the
		// same files that Serve() would (0 on single-site → no blog segment).
		$file = Vary::resolveFile( $this->settings, $env, Vary::blogId( $this->settings, (string) $env['host'] ) );
		if ( '' === $file || ! is_file( $file ) ) {
			return false;
		}

		$ttl = (int) $this->settings['ttl'];
		return 0 === $ttl || ( time() - (int) filemtime( $file ) ) <= $ttl;
	}

	/**
	 * Honour robots.txt: parse User-agent groups, pick the best-matching group
	 * for our UA, and apply prefix-match Disallow/Allow rules (E2). The rules
	 * are cached for a short time and an empty robots.txt is never cached.
	 *
	 * @param string $url
	 * @return bool
	 */
	private function robots_allowed( string $url ): bool {
		$groups = $this->robots_groups();
		if ( empty( $groups ) ) {
			return true;
		}

		$group = $this->robots_group_for_agent( $groups );
		if ( null === $group ) {
			return true;
		}

		$path = (string) parse_url( $url, PHP_URL_PATH );
		if ( '' === $path ) {
			$path = '/';
		}

		$best_disallow = '';
		foreach ( (array) $group['disallow'] as $rule ) {
			$rule = trim( (string) $rule );
			if ( '' === $rule ) {
				continue;
			}
			if ( 0 === strpos( $path, $rule ) && strlen( $rule ) > strlen( $best_disallow ) ) {
				$best_disallow = $rule;
			}
		}

		$best_allow = '';
		foreach ( (array) $group['allow'] as $rule ) {
			$rule = trim( (string) $rule );
			if ( '' === $rule ) {
				continue;
			}
			if ( 0 === strpos( $path, $rule ) && strlen( $rule ) > strlen( $best_allow ) ) {
				$best_allow = $rule;
			}
		}

		if ( '' === $best_disallow ) {
			return true;
		}

		if ( '' === $best_allow ) {
			return false;
		}

		// Longest match wins; on equal length an explicit Allow wins.
		return strlen( $best_allow ) >= strlen( $best_disallow );
	}

	/**
	 * Fetch + parse robots.txt into User-agent groups.
	 *
	 * @return array<string,array<string,array>>
	 */
	private function robots_groups(): array {
		$body = get_transient( self::ROBOTS_KEY );
		if ( false === $body ) {
			$response = wp_remote_get( home_url( '/robots.txt' ), array( 'timeout' => 3 ) );
			$body     = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
			$body     = trim( $body );
			if ( '' !== $body ) {
				// E2: short TTL, never store an empty robots.txt.
				set_transient( self::ROBOTS_KEY, $body, self::ROBOTS_TTL );
			}
		} else {
			$body = trim( (string) $body );
		}

		return $this->parse_robots( $body );
	}

	/**
	 * Select the robots group that applies to our UA: the longest group name
	 * that is a case-insensitive prefix of our user agent wins, else `*`.
	 *
	 * @param array<string,array<string,array>> $groups
	 * @return array<string,array>|null
	 */
	private function robots_group_for_agent( array $groups ): ?array {
		$wildcard = isset( $groups['*'] ) ? $groups['*'] : null;

		$specific = null;
		$best     = '';
		foreach ( $groups as $agent => $group ) {
			$agent = trim( (string) $agent );
			if ( '*' === $agent || '' === $agent ) {
				continue;
			}
			if ( 0 === stripos( self::USER_AGENT, $agent ) && strlen( $agent ) > strlen( $best ) ) {
				$specific = $group;
				$best     = $agent;
			}
		}

		return $specific ?? $wildcard;
	}

	/**
	 * Parse a robots.txt document into groups of allow/disallow rules.
	 *
	 * @param string $body
	 * @return array<string,array<string,array>>
	 */
	private function parse_robots( string $body ): array {
		$groups  = array();
		$current = null;

		foreach ( preg_split( '/\R/', $body ) ?: array() as $raw ) {
			$line = trim( $raw );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}

			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}

			$field = strtolower( trim( substr( $line, 0, $colon ) ) );
			$value = trim( substr( $line, $colon + 1 ) );

			if ( 'user-agent' === $field ) {
				$current = $value;
				if ( '' !== $current && ! isset( $groups[ $current ] ) ) {
					$groups[ $current ] = array( 'allow' => array(), 'disallow' => array() );
				}
				continue;
			}

			if ( null === $current || '' === $current ) {
				continue;
			}

			if ( 'disallow' === $field ) {
				$groups[ $current ]['disallow'][] = $value;
			} elseif ( 'allow' === $field ) {
				$groups[ $current ]['allow'][] = $value;
			}
		}

		return $groups;
	}

	/**
	 * Parse a URL into a normalized env array (mirrors Purger's private helper).
	 *
	 * @param string $url
	 * @return array<string,mixed>|null
	 */
	private function url_to_env( string $url ): ?array {
		$parts = parse_url( $url );
		if ( ! $parts || empty( $parts['host'] ) ) {
			return null;
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'http';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return null;
		}

		$host = Vary::sanitizeHost( (string) $parts['host'] );
		if ( '' === $host ) {
			return null;
		}

		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$query = array();
		if ( isset( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query );
		}

return array(
			'scheme'  => $scheme,
			'host'    => $host,
			'path'    => '' === $path ? '/' : '/' . ltrim( $path, '/' ),
			'method'  => 'GET',
			'ua'      => '',
			'query'   => $query,
			'cookies' => array(),
		);
	}

	/**
	 * Convert a relative href into an absolute URL.
	 *
	 * @param string $href
	 * @param string $base
	 * @return string|null
	 */
	private function absolutize( string $href, string $base ): ?string {
		if ( 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'mailto:' ) || 0 === strpos( $href, 'tel:' ) ) {
			return null;
		}
		if ( preg_match( '#^https?://#i', $href ) ) {
			return $href;
		}
		if ( 0 === strpos( $href, '//' ) ) {
			$scheme = parse_url( $base, PHP_URL_SCHEME );
			return $scheme . ':' . $href;
		}

		$path      = (string) parse_url( $base, PHP_URL_PATH );
		$base_path = dirname( $path );
		if ( '/' === $base_path ) {
			$base_path = '';
		}

		return rtrim( $base, '/' ) . '/' . ltrim( $href, '/' );
	}

	/**
	 * Same-host check (avoid crawling external links).
	 *
	 * @param string $url
	 * @param string $base
	 * @return bool
	 */
	private function same_host( string $url, string $base ): bool {
		$a = (string) parse_url( $url, PHP_URL_HOST );
		$b = (string) parse_url( $base, PHP_URL_HOST );
		return '' !== $a && $a === $b;
	}
}
