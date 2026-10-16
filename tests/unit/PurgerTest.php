<?php
/**
 * Purger URL resolution + purge + event-handler tests.
 *
 * @package cosmic-cache
 */

namespace { // phpcs:ignore
	if ( ! function_exists( 'get_permalink' ) ) {
		function get_permalink( $id ) {
			if ( 7 === (int) $id ) {
				return 'https://example.com/articoli/';
			}
			if ( 6 === (int) $id ) {
				return 'https://example.com/front/';
			}
			return 'https://example.com/hello-world/';
		}
	}

	if ( ! function_exists( 'get_the_terms' ) ) {
		function get_the_terms( $id, $taxonomy ) {
			if ( 'post_tag' === $taxonomy ) {
				// Tags are covered via get_the_tags() below.
				return false;
			}
			if ( 'genre' === $taxonomy ) {
				$term          = new \stdClass();
				$term->term_id = 21;
				$term->slug    = 'sci-fi';
				return array( $term );
			}
			$term         = new \stdClass();
			$term->term_id = 9;
			$term->slug    = 'uncategorized';
			return array( $term );
		}
	}

	if ( ! function_exists( 'get_term_link' ) ) {
		function get_term_link( $term, $taxonomy = '' ) {
			$slug = is_object( $term ) && isset( $term->slug ) ? $term->slug : 'uncategorized';
			if ( 'news' === $slug ) {
				return 'https://example.com/tag/news/';
			}
			if ( 'sci-fi' === $slug ) {
				return 'https://example.com/genre/sci-fi/';
			}
			return 'https://example.com/category/uncategorized/';
		}
	}

	if ( ! function_exists( 'get_the_tags' ) ) {
		function get_the_tags( $id ) {
			$tag           = new \stdClass();
			$tag->term_id   = 11;
			$tag->slug      = 'news';
			$tag->name      = 'News';
			$tag->taxonomy  = 'post_tag';
			return array( $tag );
		}
	}

	if ( ! function_exists( 'get_post_field' ) ) {		function get_post_field( $field, $post_id = 0 ) {
			if ( 'post_author' === $field ) {
				return 5;
			}
			if ( 'post_date' === $field ) {
				return '2026-08-15 10:00:00';
			}
			return '';
		}
	}

	if ( ! function_exists( 'get_author_posts_url' ) ) {
		function get_author_posts_url( $author_id ) {
			return 'https://example.com/author/rusty/';
		}
	}

	if ( ! function_exists( 'get_year_link' ) ) {
		function get_year_link( $year ) {
			return 'https://example.com/' . $year . '/';
		}
	}

	if ( ! function_exists( 'get_month_link' ) ) {
		function get_month_link( $year, $month ) {
			return 'https://example.com/' . $year . '/' . str_pad( (string) $month, 2, '0', STR_PAD_LEFT ) . '/';
		}
	}

	if ( ! function_exists( 'get_day_link' ) ) {
		function get_day_link( $year, $month, $day ) {
			return 'https://example.com/' . $year . '/' . str_pad( (string) $month, 2, '0', STR_PAD_LEFT ) . '/' . str_pad( (string) $day, 2, '0', STR_PAD_LEFT ) . '/';
		}
	}

	if ( ! function_exists( 'add_query_arg' ) ) {
		function add_query_arg( $key, $value, $url ) {
			return $url . ( strpos( (string) $url, '?' ) ? '&' : '?' ) . rawurlencode( $key ) . '=' . rawurlencode( $value );
		}
	}

	if ( ! function_exists( 'get_post_status' ) ) {
		function get_post_status( $id ) {
			return $GLOBALS['cosmic_test_wp']['post_status'] ?? 'publish';
		}
	}

	if ( ! function_exists( 'wp_is_post_revision' ) ) {
		function wp_is_post_revision( $id ) {
			return (bool) ( $GLOBALS['cosmic_test_wp']['is_revision'] ?? false );
		}
	}

	if ( ! function_exists( 'wp_is_post_autosave' ) ) {
		function wp_is_post_autosave( $id ) {
			return (bool) ( $GLOBALS['cosmic_test_wp']['is_autosave'] ?? false );
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data ) {
			return (string) json_encode( $data );
		}
	}

	if ( ! function_exists( 'get_comment' ) ) {
		function get_comment( $id ) {
			$comment = new \stdClass();
			$comment->comment_post_ID = 42;
			return $comment;
		}
	}

	if ( ! function_exists( 'get_post_type' ) ) {
		function get_post_type( $id = null ) {
			return $GLOBALS['cosmic_test_wp']['post_type'] ?? 'post';
		}
	}

	if ( ! function_exists( 'get_object_taxonomies' ) ) {
		function get_object_taxonomies( $post_type, $output = 'names' ) {
			if ( isset( $GLOBALS['cosmic_test_wp']['taxonomies'] ) ) {
				return $GLOBALS['cosmic_test_wp']['taxonomies'];
			}
			return array( 'category' );
		}
	}

	if ( ! function_exists( 'get_post_type_archive_link' ) ) {
		function get_post_type_archive_link( $post_type ) {
			if ( array_key_exists( 'archive_link', $GLOBALS['cosmic_test_wp'] ?? array() ) ) {
				return $GLOBALS['cosmic_test_wp']['archive_link'];
			}
			if ( 'bottle' === $post_type ) {
				return 'https://example.com/bottles/';
			}
			return false;
		}
	}

	if ( ! function_exists( 'wp_get_post_parent_id' ) ) {
		function wp_get_post_parent_id( $id ) {
			return (int) ( $GLOBALS['cosmic_test_wp']['attachment_parent'] ?? 0 );
		}
	}
}

namespace CosmicCache\Tests { // phpcs:ignore

	use CosmicCache\Cache\Purger;
	use CosmicCache\Cache\Vary;
	use PHPUnit\Framework\TestCase;

class PurgerTest extends TestCase {

	/** @var string */
	private $root;

	protected function setUp(): void {
		// Single-site installs store files with NO blog segment (blogId()=0),
		// mirroring the pre-boot hot path.
		$this->root = Vary::cacheRoot() . '/https/example.com';
		mkdir( $this->root, 0755, true );

		update_option( 'cosmic_cache_settings', array(
			'purge_on_post'    => true,
			'purge_on_comment' => true,
			'purge_on_terms'   => true,
		) );
		$GLOBALS['cosmic_test_wp'] = array();
	}

	protected function tearDown(): void {
		update_option( 'cosmic_cache_settings', array() );

		// Reset reading-settings + per-test WP doubles so state never leaks
		// into the next test.
		unset(
			$GLOBALS['cosmic_test_options']['show_on_front'],
			$GLOBALS['cosmic_test_options']['page_for_posts'],
			$GLOBALS['cosmic_test_options']['page_on_front'],
			$GLOBALS['cosmic_test_options']['sticky_posts']
		);
		$GLOBALS['cosmic_test_wp'] = array();

		// Reset the deferred-purge queue between tests.
		$pending = new \ReflectionProperty( Purger::class, 'pending' );
		$pending->setAccessible( true );
		$pending->setValue( null, array() );

		$base = Vary::cacheRoot();
		if ( is_dir( $base ) ) {
			$it = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $it as $f ) {
				$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
			}
		}
	}

	private function queued_urls(): array {
		$pending = new \ReflectionProperty( Purger::class, 'pending' );
		$pending->setAccessible( true );
		$urls = array();
		foreach ( (array) $pending->getValue() as $entry ) {
			if ( 'url' === $entry[0] ) {
				$urls = array_merge( $urls, (array) $entry[1] );
			}
		}
		return $urls;
	}

	private function settings(): array {
		return array(
			'cache_whitelist_query' => array( 'p', 'paged', 'cpage' ),
		);
	}

	private function path( string $hash ): string {
		return $this->root . '/' . $hash . '.html';
	}

	public function test_purge_url_removes_base_variants_and_pagination(): void {
		$base     = sha1( 'https://example.com/hello-world/' );
		$cpage    = sha1( 'https://example.com/hello-world/?cpage=2' );
		$paged    = sha1( 'https://example.com/hello-world/?paged=3' );
		$unrelated = sha1( 'https://example.com/other/' );

		file_put_contents( $this->path( $base ), 'a' );
		file_put_contents( $this->path( $cpage ), 'b' );
		file_put_contents( $this->path( $paged ), 'c' );
		file_put_contents( $this->path( $unrelated ), 'd' );

		$purger  = new Purger();
		$count   = $purger->purge_urls( array( 'https://example.com/hello-world/' ), $this->settings() );

		$this->assertGreaterThanOrEqual( 3, $count );
		$this->assertFileDoesNotExist( $this->path( $base ) );
		$this->assertFileDoesNotExist( $this->path( $cpage ) );
		$this->assertFileDoesNotExist( $this->path( $paged ) );
		$this->assertFileExists( $this->path( $unrelated ) );
	}

	public function test_purge_url_removes_vary_siblings(): void {
		$base     = sha1( 'https://example.com/hello-world/' );
		$sibling  = $base . '.vary-' . sha1( 'mobile' ) . '.html';

		file_put_contents( $this->root . '/' . $sibling, 'x' );

		$purger = new Purger();
		$purger->purge_urls( array( 'https://example.com/hello-world/' ), $this->settings() );
		$this->assertFileDoesNotExist( $this->root . '/' . $sibling );
	}

	public function test_full_purge_keeps_config(): void {
		file_put_contents( $this->root . '/a.html', 'x' );
		file_put_contents( Vary::cacheRoot() . '/config.php', "<?php return array();\n" );

		$purger = new Purger();
		$purger->purge_all();

		$this->assertFileDoesNotExist( $this->root . '/a.html' );
		$this->assertFileExists( Vary::cacheRoot() . '/config.php' );
	}

	public function test_invalid_url_is_ignored(): void {
		$purger = new Purger();
		$this->assertSame( 0, $purger->purge_urls( array( 'not a url' ), $this->settings() ) );
	}

	public function test_purge_prefix_rejects_traversal(): void {
		$purger = new Purger();

		// `..` anywhere and leading slashes must be refused (0, no files touched).
		$this->assertSame( 0, $purger->purge_prefix( '..' ) );
		$this->assertSame( 0, $purger->purge_prefix( '../' ) );
		$this->assertSame( 0, $purger->purge_prefix( 'a/../b' ) );
		$this->assertSame( 0, $purger->purge_prefix( '/etc' ) );

		// A harmless prefix still works (paths are relative to the cache root).
		$base = Vary::cacheRoot();
		mkdir( $base . '/p', 0755, true );
		file_put_contents( $base . '/p/a.html', 'x' );
		$this->assertSame( 1, $purger->purge_prefix( 'p' ) );
	}

	public function test_purge_regex_rejects_invalid_pattern_without_fatal(): void {
		$purger = new Purger();
		// "Unknown modifier" → @preg_match guard must bail out returning 0.
		$this->assertSame( 0, $purger->purge_regex( '/foo]' ) );
	}

	public function test_unpublish_transition_queues_permalink_feed_home_and_terms(): void {
		$post  = (object) array( 'ID' => 42 );
		$purger = new Purger();

		$purger->on_transition_post_status( 'draft', 'publish', $post );

		$urls = $this->queued_urls();
		$this->assertContains( 'https://example.com/hello-world/', $urls );
		$this->assertContains( 'https://example.com/hello-world/feed/', $urls );
		$this->assertContains( 'https://example.com', $urls );
		$this->assertContains( 'https://example.com/category/uncategorized/', $urls );
		$this->assertContains( 'https://example.com/category/uncategorized/feed/', $urls );
	}

	public function test_save_post_includes_feed_and_skips_revisions(): void {
		$purger = new Purger();

		// Revisions/autosaves must not purge.
		$GLOBALS['cosmic_test_wp']['is_revision'] = true;
		$purger->on_save_post( 42, (object) array( 'ID' => 42 ) );
		$this->assertSame( array(), $this->queued_urls() );

		$pending = new \ReflectionProperty( Purger::class, 'pending' );
		$pending->setAccessible( true );
		$pending->setValue( null, array() );

		// A real published save purges permalink + feed.
		$GLOBALS['cosmic_test_wp']['is_revision'] = false;
		$purger->on_save_post( 42, (object) array( 'ID' => 42 ) );
		$this->assertContains( 'https://example.com/hello-world/feed/', $this->queued_urls() );
	}

	public function test_save_post_queues_tags_author_and_date_archives(): void {
		$purger = new Purger();
		$purger->on_save_post( 42, (object) array( 'ID' => 42 ) );

		$urls = $this->queued_urls();
		$this->assertContains( 'https://example.com/tag/news/', $urls );
		$this->assertContains( 'https://example.com/tag/news/feed/', $urls );
		$this->assertContains( 'https://example.com/author/rusty/', $urls );
		$this->assertContains( 'https://example.com/2026/', $urls );
		$this->assertContains( 'https://example.com/2026/08/', $urls );
		$this->assertContains( 'https://example.com/2026/08/15/', $urls );
	}

	public function test_save_post_queues_paginated_home(): void {
		$purger = new Purger();
		$purger->on_save_post( 42, (object) array( 'ID' => 42 ) );

		$urls = $this->queued_urls();
		$this->assertContains( 'https://example.com?paged=2', $urls );
		$this->assertContains( 'https://example.com?paged=10', $urls );
	}

	public function test_comment_change_queues_cpage_variant(): void {
		$purger = new Purger();

		$purger->on_comment_change( 7, 'approved' );

		$urls = $this->queued_urls();
		$this->assertContains( 'https://example.com/hello-world/?cpage=2', $urls );
	}

	public function test_publish_transition_queues_posts_page_and_pretty_variants(): void {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_for_posts', 7 );
		update_option( 'page_on_front', 6 );

		$purger = new Purger();
		$purger->on_transition_post_status( 'publish', 'future', (object) array( 'ID' => 42 ) );

		$urls = $this->queued_urls();
		$this->assertContains( 'https://example.com/hello-world/', $urls );
		$this->assertContains( 'https://example.com/articoli/', $urls );
		$this->assertContains( 'https://example.com/articoli/page/2/', $urls );
		$this->assertContains( 'https://example.com/articoli/page/10/', $urls );
	}

	public function test_draft_to_publish_transition_queues_permalink(): void {
		$purger = new Purger();
		$purger->on_transition_post_status( 'publish', 'draft', (object) array( 'ID' => 42 ) );

		$this->assertContains( 'https://example.com/hello-world/', $this->queued_urls() );
	}

	public function test_publish_future_post_hook_queues_permalink(): void {
		$purger = new Purger();
		$purger->on_future_publish( 42 );

		$this->assertContains( 'https://example.com/hello-world/', $this->queued_urls() );
	}

	public function test_purge_url_removes_pretty_pagination_siblings(): void {
		$base = sha1( 'https://example.com/articoli/' );
		$page = sha1( 'https://example.com/articoli/page/2/' );

		file_put_contents( $this->path( $base ), 'a' );
		file_put_contents( $this->path( $page ), 'b' );

		$purger = new Purger();
		$count  = $purger->purge_urls( array( 'https://example.com/articoli/' ), $this->settings() );

		$this->assertGreaterThanOrEqual( 2, $count );
		$this->assertFileDoesNotExist( $this->path( $base ) );
		$this->assertFileDoesNotExist( $this->path( $page ) );
	}

	public function test_save_post_includes_custom_taxonomy_and_cpt_archive(): void {
		$GLOBALS['cosmic_test_wp']['post_type']    = 'bottle';
		$GLOBALS['cosmic_test_wp']['taxonomies']   = array( 'category', 'genre' );
		$GLOBALS['cosmic_test_wp']['archive_link'] = 'https://example.com/bottles/';

		$purger = new Purger();
		$purger->on_save_post( 42, (object) array( 'ID' => 42 ) );

		$urls = $this->queued_urls();
		$this->assertContains( 'https://example.com/genre/sci-fi/', $urls );
		$this->assertContains( 'https://example.com/bottles/', $urls );
		$this->assertContains( 'https://example.com/bottles/page/2/', $urls );
	}

	public function test_reading_settings_change_purges_all(): void {
		file_put_contents( $this->root . '/a.html', 'x' );

		$purger = new Purger();
		$purger->on_reading_change( 'posts', 'page' );

		$this->assertFileDoesNotExist( $this->root . '/a.html' );
	}

	public function test_customize_save_purges_all(): void {
		file_put_contents( $this->root . '/a.html', 'x' );

		$purger = new Purger();
		$purger->on_customize_save();

		$this->assertFileDoesNotExist( $this->root . '/a.html' );
	}

	public function test_edit_attachment_queues_parent_post(): void {
		$GLOBALS['cosmic_test_wp']['attachment_parent'] = 42;

		$purger = new Purger();
		$purger->on_edit_attachment( 99 );

		$this->assertContains( 'https://example.com/hello-world/', $this->queued_urls() );
	}
}
}
