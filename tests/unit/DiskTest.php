<?php
/**
 * Disk storage unit tests (atomic writes, precompression).
 *
 * @package cosmic-cache
 */

namespace CosmicCache\Tests;

use CosmicCache\Cache\Storage\Disk;
use PHPUnit\Framework\TestCase;

class DiskTest extends TestCase {

	/** @var string */
	private $tmp;

	protected function setUp(): void {
		$this->tmp = sys_get_temp_dir() . '/cosmic-disk-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->tmp, 0755, true );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->tmp ) ) {
			foreach ( glob( $this->tmp . '/*' ) ?: array() as $file ) {
				@unlink( $file );
			}
			@rmdir( $this->tmp );
		}
	}

	public function test_store_writes_html_and_gzip(): void {
		$settings = array( 'gzip' => true, 'brotli' => false );
		$file     = $this->tmp . '/cache.html';
		$html     = '<p>hello world</p>';

		$this->assertTrue( Disk::store( $file, $html, $settings ) );
		$this->assertFileExists( $file );
		$this->assertFileExists( $file . '.gz' );
		$this->assertSame( $html, file_get_contents( $file ) );
		$this->assertSame( $html, gzdecode( (string) file_get_contents( $file . '.gz' ) ) );
	}

	public function test_store_writes_brotli_when_available(): void {
		if ( ! function_exists( 'brotli_compress' ) ) {
			$this->markTestSkipped( 'brotli not available' );
		}

		$settings = array( 'gzip' => false, 'brotli' => true );
		$file     = $this->tmp . '/cache.html';
		$html     = '<p>brotli</p>';

		$this->assertTrue( Disk::store( $file, $html, $settings ) );
		$this->assertFileExists( $file . '.br' );
	}

	public function test_atomic_write_leaves_no_tmp_files(): void {
		$file = $this->tmp . '/x.html';
		Disk::atomicWrite( $file, 'data' );
		$this->assertSame( array( 'x.html' ), array_values( array_diff( scandir( $this->tmp ) ?: array(), array( '.', '..' ) ) ) );
	}

	public function test_delete_removes_variants(): void {
		$settings = array( 'gzip' => true, 'brotli' => false );
		$file     = $this->tmp . '/c.html';
		Disk::store( $file, '<p>x</p>', $settings );

		Disk::delete( $file );
		$this->assertFileDoesNotExist( $file );
		$this->assertFileDoesNotExist( $file . '.gz' );
	}

	public function test_gc_respects_ttl_and_bound(): void {
		$settings = array( 'gzip' => false, 'brotli' => false, 'ttl' => 10 );

		$root = $this->tmp . '/root/https/example.com';
		mkdir( $root, 0755, true );

		$fresh = $root . '/a.html';
		$old   = $root . '/b.html';
		file_put_contents( $fresh, 'x' );
		file_put_contents( $old, 'x' );
		touch( $old, time() - 3600 );

		$deleted = Disk::gc( $settings, 100, $this->tmp . '/root' );
		$this->assertSame( 1, $deleted );
		$this->assertFileExists( $fresh );
		$this->assertFileDoesNotExist( $old );
	}

	public function test_gc_evicts_oldest_first_when_over_cap(): void {
		$settings = array( 'gzip' => false, 'brotli' => false, 'ttl' => 0 );

		$root = $this->tmp . '/root/https/example.com';
		mkdir( $root, 0755, true );

		// Five entries, oldest first (mtime ascending).
		$now = time();
		for ( $i = 1; $i <= 5; $i++ ) {
			$file = $root . '/page-' . $i . '.html';
			file_put_contents( $file, 'x' );
			touch( $file, $now - ( 6 - $i ) * 60 ); // page-1 oldest.
		}

		// Cap of 3 → the two oldest (page-1, page-2) must go.
		$deleted = Disk::gc( $settings, 100, $this->tmp . '/root', 3 );
		$this->assertSame( 2, $deleted );
		$this->assertFileDoesNotExist( $root . '/page-1.html' );
		$this->assertFileDoesNotExist( $root . '/page-2.html' );
		$this->assertFileExists( $root . '/page-3.html' );
		$this->assertFileExists( $root . '/page-4.html' );
		$this->assertFileExists( $root . '/page-5.html' );
	}

	public function test_gc_sweeps_orphaned_tmp_files(): void {
		$settings = array( 'gzip' => false, 'brotli' => false, 'ttl' => 0 );

		$root = $this->tmp . '/root';
		mkdir( $root, 0755, true );

		$old   = $root . '/x.123456.tmp';
		$fresh = $root . '/y.654321.tmp';
		file_put_contents( $old, 'x' );
		file_put_contents( $fresh, 'x' );
		touch( $old, time() - 3600 ); // orphan from an interrupted write.

		$deleted = Disk::gc( $settings, 100, $root );
		$this->assertSame( 1, $deleted );
		$this->assertFileDoesNotExist( $old );
		$this->assertFileExists( $fresh );
	}
}
