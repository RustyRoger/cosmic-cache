<?php
/**
 * Cache invalidation: full / URL / prefix / regex purges + hook triggers.
 *
 * Purges requested during a request are deferred to shutdown and de-duplicated
 * (SPEC §7.6).
 *
 * @package cosmic-cache
 */

declare( strict_types=1 );

namespace CosmicCache\Cache;

use CosmicCache\Optimization\CriticalCSS;

final class Purger {

	/**
	 * Pending purges, de-duplicated. Structure: array of [type, arg].
	 *
	 * @var array<int,array{0:string,1:mixed}>
	 */
	private static $pending = array();

	/**
	 * Register invalidation hooks (admin/cron/cli contexts).
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 2 );
		add_action( 'wp_trash_post', array( $this, 'on_trash_post' ) );
		add_action( 'untrash_post', array( $this, 'on_untrash_post' ) );
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ) );

		add_action( 'edit_term', array( $this, 'on_term_change' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'on_term_delete' ), 10, 4 );

		add_action( 'comment_post', array( $this, 'on_comment_change' ), 10, 2 );
		add_action( 'wp_set_comment_status', array( $this, 'on_comment_status' ), 10, 2 );
		add_action( 'trackback_post', array( $this, 'on_comment_change' ) );

		add_action( 'wp_update_nav_menu', array( $this, 'purge_all' ) );
		add_action( 'switch_theme', array( $this, 'purge_all' ) );

		// The pasted critical CSS snippet is tied to the theme/plugin CSS
		// sources: flag it as possibly stale when those change. Pages are
		// purged by the hooks above; the snippet itself needs a human refresh.
		add_action( 'switch_theme', array( CriticalCSS::class, 'markStale' ) );
		add_action( 'customize_save_after', array( CriticalCSS::class, 'markStale' ) );
		add_action( 'upgrader_process_complete', array( CriticalCSS::class, 'onUpgrader' ), 10, 2 );

		add_action( 'update_option_siteurl', array( $this, 'purge_all' ) );
		add_action( 'update_option_home', array( $this, 'purge_all' ) );
		add_action( 'update_option_permalink_structure', array( $this, 'purge_all' ) );

		add_action( 'shutdown', array( $this, 'flush_pending' ), 99 );
	}

	/**
	 * Admin toolbar "Clear cache" button.
	 *
	 * @return void
	 */
	public function register_admin_bar(): void {
		if ( empty( \CosmicCache\Config::runtime()['show_admin_bar_button'] ) || ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return;
		}

		add_action(
			'admin_bar_menu',
			static function ( $wp_admin_bar ) {
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}
				$nonce = wp_create_nonce( 'cosmic_cache_admin' );
				$wp_admin_bar->add_node(
					array(
						'id'     => 'cosmic-cache-clear',
						'title'  => __( 'Clear cache', 'cosmic-cache' ),
						'href'   => add_query_arg( array( 'cosmic_purge_all' => $nonce ), admin_url() ),
						'meta'   => array( 'class' => 'cosmic-cache-clear' ),
					)
				);
			},
			80
		);

		add_action(
			'admin_init',
			static function () {
				if ( ! isset( $_GET['cosmic_purge_all'] ) ) {
					return;
				}
				if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['cosmic_purge_all'] ) ), 'cosmic_cache_admin' ) ) {
					return;
				}
				\CosmicCache\Plugin::instance()->purger()->purge_all();
			}
		);
	}

	/**
	 * Queue a purge (deferred to shutdown, de-duplicated).
	 *
	 * @param string $type full|url|prefix|regex
	 * @param mixed  $arg
	 * @return void
	 */
	public static function queue( string $type, $arg ): void {
		$key = $type . ':' . ( is_string( $arg ) ? $arg : (string) wp_json_encode( $arg ) );
		if ( ! isset( self::$pending[ $key ] ) ) {
			self::$pending[ $key ] = array( $type, $arg );
		}
	}

	/**
	 * Run any queued purges now.
	 *
	 * @return void
	 */
	public function flush_pending(): void {
		if ( empty( self::$pending ) ) {
			return;
		}

		$pending = self::$pending;
		self::$pending = array();

		$settings = \CosmicCache\Config::runtime();

		foreach ( $pending as $purge ) {
			switch ( $purge[0] ) {
				case 'full':
					$this->purge_all();
					break;
				case 'url':
					$this->purge_urls( (array) $purge[1], $settings );
					break;
				case 'prefix':
					$this->purge_prefix( (string) $purge[1], $settings );
					break;
				case 'regex':
					$this->purge_regex( (string) $purge[1], $settings );
					break;
			}
		}
	}

	/**
	 * Full purge: delete all cached files under the cache root (keeps
	 * config.php and debug.log so the cache keeps serving empty until warm).
	 *
	 * @return int Files removed.
	 */
	public function purge_all(): int {
		$root = Vary::cacheRoot();
		if ( '' === $root || ! is_dir( $root ) ) {
			return 0;
		}

		$count = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$name = $file->getFilename();
			if ( 'config.php' === $name || 'debug.log' === $name ) {
				continue;
			}
			if ( 1 === preg_match( '/\.(html|gz|br)$/', $name ) ) {
				@unlink( $file->getPathname() );
				++$count;
			}
		}

		do_action( 'cosmic_cache_purged_all' );
		return $count;
	}

	/**
	 * Purge one or more URLs including all `.vary-*` variants and whitelisted
	 * query hashes (paginated archives, comment pages).
	 *
	 * @param array<int,string>    $urls
	 * @param array<string,mixed>  $settings
	 * @return int Files removed.
	 */
	public function purge_urls( array $urls, ?array $settings = null ): int {
		$settings = $settings ?? \CosmicCache\Config::runtime();
		$urls     = apply_filters( 'cosmic_cache_purge_urls', $urls, $settings );

		$count = 0;
		foreach ( $urls as $url ) {
			$count += $this->purge_url( (string) $url, $settings );
		}

		if ( $count > 0 ) {
			do_action( 'cosmic_cache_purged_urls', $urls );
		}

		return $count;
	}

	/**
	 * Purge a single URL.
	 *
	 * @param string              $url
	 * @param array<string,mixed> $settings
	 * @return int Files removed.
	 */
	public function purge_url( string $url, array $settings ): int {
		$env = $this->url_to_env( $url );
		if ( ! $env ) {
			return 0;
		}

		$blog_id = Vary::blogId( $settings, (string) $env['host'] );

		$files   = array();
		$root    = Vary::cacheRoot();
		$dir     = $root . '/' . $env['scheme'] . '/' . $env['host'] . ( $blog_id ? '/' . $blog_id : '' );

		// 1. Exact URL + base URL (no query).
		foreach ( array( $env, $this->strip_query( $env ) ) as $candidate ) {
			$file = Vary::resolveFile( $settings, $candidate, $blog_id );
			if ( '' !== $file ) {
				$files[ $file ] = true;
			}
		}

		// 2. Whitelisted-query hashes: bounded pagination / comment ranges so a
		// single event invalidates paged + cpage variants of the same URL.
		$files += $this->query_variants( $settings, $env, $blog_id );

		$count = 0;
		foreach ( array_keys( $files ) as $file ) {
			if ( ! is_file( $file ) && ! is_file( $file . '.gz' ) && ! is_file( $file . '.br' ) ) {
				continue;
			}
			// Also remove any .vary-* siblings (list on disk, not just computed).
			$this->delete_file( $file );
			++$count;
		}

		// 3. On-disk vary siblings for the exact hash.
		if ( is_dir( $dir ) ) {
			$base_hash = sha1( Vary::hashKey( $settings, $env ) );
			foreach ( glob( $dir . '/' . $base_hash . '.vary-*.html*' ) ?: array() as $sibling ) {
				@unlink( $sibling );
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Prefix purge: delete a subtree (term archives, paged archives).
	 *
	 * @param string              $prefix Filesystem path prefix.
	 * @param array<string,mixed> $settings
	 * @return int
	 */
	public function purge_prefix( string $prefix, ?array $settings = null ): int {
		$settings = $settings ?? \CosmicCache\Config::runtime();
		$root     = Vary::cacheRoot();
		if ( '' === $root || '' === $prefix || ! is_dir( $root ) ) {
			return 0;
		}

		// Containment: a prefix must stay inside the cache root. `..` or a
		// leading `/` could otherwise delete files anywhere on disk.
		if ( 1 === preg_match( '#(^|/)\.\.(/|$)#', $prefix ) || 0 === strpos( $prefix, '/' ) ) {
			return 0;
		}

		$path = $root . '/' . ltrim( $prefix, '/' );
		if ( ! is_dir( $path ) ) {
			return 0;
		}

		$count = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 1 === preg_match( '/\.(html|gz|br)$/', $file->getFilename() ) ) {
				@unlink( $file->getPathname() );
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Regex purge: bounded iteration over matching cache file paths.
	 *
	 * @param string              $pattern
	 * @param array<string,mixed> $settings
	 * @param int                 $max
	 * @return int
	 */
	public function purge_regex( string $pattern, ?array $settings = null, int $max = 5000 ): int {
		$settings = $settings ?? \CosmicCache\Config::runtime();
		$root     = Vary::cacheRoot();
		if ( '' === $root || '' === $pattern || ! is_dir( $root ) ) {
			return 0;
		}

		// Validate the pattern compiles before iterating every file; an invalid
		// pattern would fatal on the first match.
		if ( false === @preg_match( $pattern, '' ) ) {
			return 0;
		}

		$count   = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( $count >= $max ) {
				break;
			}
			if ( ! $file->isFile() || 1 !== preg_match( '/\.(html|gz|br)$/', $file->getFilename() ) ) {
				continue;
			}
			$relative = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) ) ), '/' );
			if ( preg_match( $pattern, $relative ) || preg_match( $pattern, $file->getPathname() ) ) {
				@unlink( $file->getPathname() );
				++$count;
			}
		}

		return $count;
	}

	/**
	 * post_status transition: unpublish must never leave a stale page.
	 *
	 * @param string   $new_status
	 * @param string   $old_status
	 * @param \WP_Post $post
	 * @return void
	 */
	public function on_transition_post_status( $new_status, $old_status, $post ): void {
		if ( ! empty( \CosmicCache\Config::runtime()['purge_on_post'] ) && 'publish' === $old_status && 'publish' !== $new_status ) {
			$this->queue_post_urls( $post->ID );
		}
	}

	/**
	 * save_post: purge permalink + home + term archives for published posts.
	 *
	 * @param int     $post_id
	 * @param \WP_Post $post
	 * @return void
	 */
	public function on_save_post( $post_id, $post ): void {
		if ( empty( \CosmicCache\Config::runtime()['purge_on_post'] ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return;
		}

		$this->queue_post_urls( $post_id );
	}

	/**
	 * wp_trash_post / untrash_post / before_delete_post.
	 *
	 * @param int $post_id
	 * @return void
	 */
	public function on_trash_post( $post_id ) {
		if ( ! empty( \CosmicCache\Config::runtime()['purge_on_post'] ) ) {
			$this->queue_post_urls( $post_id );
		}
	}

	public function on_untrash_post( $post_id ) {
		if ( ! empty( \CosmicCache\Config::runtime()['purge_on_post'] ) ) {
			$this->queue_post_urls( $post_id );
		}
	}

	public function on_delete_post( $post_id ) {
		if ( ! empty( \CosmicCache\Config::runtime()['purge_on_post'] ) ) {
			$this->queue_post_urls( $post_id );
		}
	}

	/**
	 * Term change → term archive + home.
	 *
	 * @param int    $term_id
	 * @param int    $tt_id
	 * @param string $taxonomy
	 * @return void
	 */
	public function on_term_change( $term_id, $tt_id, $taxonomy ) {
		if ( empty( \CosmicCache\Config::runtime()['purge_on_terms'] ) ) {
			return;
		}
		$this->queue_term_urls( $term_id, $taxonomy );
	}

	public function on_term_delete( $term_id, $tt_id, $taxonomy, $deleted_term ) {
		if ( empty( \CosmicCache\Config::runtime()['purge_on_terms'] ) ) {
			return;
		}
		$this->queue_term_urls( $term_id, $taxonomy );
	}

	/**
	 * Comment change → associated post (incl. comment-paged variants).
	 *
	 * @param int $comment_id
	 * @return void
	 */
	public function on_comment_change( $comment_id ) {
		if ( empty( \CosmicCache\Config::runtime()['purge_on_comment'] ) ) {
			return;
		}
		$comment = get_comment( $comment_id );
		if ( $comment && ! empty( $comment->comment_post_ID ) ) {
			$this->queue_post_urls( (int) $comment->comment_post_ID, true );
		}
	}

	/**
	 * @param int $comment_id
	 * @param string $status
	 * @return void
	 */
	public function on_comment_status( $comment_id, $status ) {
		if ( empty( \CosmicCache\Config::runtime()['purge_on_comment'] ) ) {
			return;
		}
		$comment = get_comment( $comment_id );
		if ( $comment && ! empty( $comment->comment_post_ID ) ) {
			$this->queue_post_urls( (int) $comment->comment_post_ID, true );
		}
	}

	/**
	 * Queue a post's cache URLs (permalink + home + term archives + feed).
	 *
	 * @param int  $post_id
	 * @param bool $with_comments Also invalidate comment pagination.
	 * @return void
	 */
	private function queue_post_urls( int $post_id, bool $with_comments = false ): void {
		$urls = $this->post_urls( $post_id, $with_comments );
		if ( $urls ) {
			self::queue( 'url', $urls );
		}
	}

	/**
	 * Build the cache URLs affected by a post event.
	 *
	 * @param int  $post_id
	 * @param bool $with_comments
	 * @return array<int,string>
	 */
	private function post_urls( int $post_id, bool $with_comments = false ): array {
		$urls = array();

		$permalink = get_permalink( $post_id );
		if ( $permalink ) {
			$urls[] = $permalink;
			$urls[] = $permalink . 'feed/';
		}

		// Home + bounded paginated home: ?paged=N are whitelisted separate
		// cache entries, so a publish must invalidate them together.
		$home = home_url( '/' );
		$urls[] = $home;
		for ( $i = 2; $i <= 10; $i++ ) {
			$urls[] = add_query_arg( 'paged', (string) $i, $home );
		}

		if ( function_exists( 'get_the_terms' ) ) {
			$terms = get_the_terms( $post_id, 'category' );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$link = get_term_link( $term );
					if ( $link && ! is_wp_error( $link ) ) {
						$urls[] = (string) $link;
						$urls[] = (string) $link . 'feed/';
					}
				}
			}
		}

		// Tag archives (taxonomy post_tag).
		if ( function_exists( 'get_the_tags' ) ) {
			$tags = get_the_tags( $post_id );
			if ( is_array( $tags ) ) {
				foreach ( $tags as $tag ) {
					$link = get_term_link( $tag );
					if ( $link && ! is_wp_error( $link ) ) {
						$urls[] = (string) $link;
						$urls[] = (string) $link . 'feed/';
					}
				}
			}
		}

		// Author archive (SPEC D4).
		if ( function_exists( 'get_post_field' ) && function_exists( 'get_author_posts_url' ) ) {
			$author = (int) get_post_field( 'post_author', $post_id );
			if ( $author > 0 ) {
				$link = get_author_posts_url( $author );
				if ( $link ) {
					$urls[] = (string) $link;
					$urls[] = (string) $link . 'feed/';
				}
			}
		}

		// Date archives for the publication date (day/month/year).
		if ( function_exists( 'get_post_field' ) && function_exists( 'get_day_link' )
			&& function_exists( 'get_month_link' ) && function_exists( 'get_year_link' ) ) {
			$date = get_post_field( 'post_date', $post_id );
			$ts   = is_string( $date ) ? strtotime( $date ) : false;
			if ( false !== $ts ) {
				$year  = (int) gmdate( 'Y', $ts );
				$month = (int) gmdate( 'm', $ts );
				$day   = (int) gmdate( 'j', $ts );
				foreach ( array( get_year_link( $year ), get_month_link( $year, $month ), get_day_link( $year, $month, $day ) ) as $link ) {
					if ( $link ) {
						$urls[] = (string) $link;
					}
				}
			}
		}

		if ( $with_comments ) {
			// Comment pages are reached via ?cpage= (whitelisted query hash).
			$urls[] = add_query_arg( 'cpage', '2', (string) get_permalink( $post_id ) );
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Queue a term's cache URLs.
	 *
	 * @param int    $term_id
	 * @param string $taxonomy
	 * @return void
	 */
	private function queue_term_urls( $term_id, $taxonomy ) {
		$link = get_term_link( (int) $term_id, (string) $taxonomy );
		if ( ! $link || is_wp_error( $link ) ) {
			return;
		}
		$urls = array( (string) $link, (string) $link . 'feed/', home_url( '/' ) );
		self::queue( 'url', $urls );
	}

	/**
	 * Delete a file plus its precompressed variants.
	 *
	 * @param string $file
	 * @return void
	 */
	private function delete_file( string $file ): void {
		Storage\Disk::delete( $file );
	}

	/**
	 * Parse an absolute URL into a normalized env array.
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
	 * Return a copy of env with the query removed.
	 *
	 * @param array<string,mixed> $env
	 * @return array<string,mixed>
	 */
	private function strip_query( array $env ): array {
		$env['query'] = array();
		return $env;
	}

	/**
	 * Whitelisted-query hash variants for a URL: bounded pagination/comment
	 * ranges (paged, cpage, page) so a single event invalidates every variant.
	 *
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $env
	 * @param int                 $blog_id
	 * @return array<string,bool> File path => true.
	 */
	private function query_variants( array $settings, array $env, int $blog_id ): array {
		$files = array();
		$whitelist = (array) ( $settings['cache_whitelist_query'] ?? array() );
		$pagination = array( 'paged', 'cpage', 'page' );

		foreach ( $pagination as $param ) {
			if ( ! in_array( $param, $whitelist, true ) ) {
				continue;
			}
			// Bounded range: 2..100 keeps this deterministic and cheap.
			for ( $i = 2; $i <= 100; $i++ ) {
				$candidate            = $env;
				$candidate['query']   = array( $param => (string) $i );
				$file                 = Vary::resolveFile( $settings, $candidate, $blog_id );
				if ( '' !== $file ) {
					$files[ $file ] = true;
				}
			}
		}

		return $files;
	}
}
