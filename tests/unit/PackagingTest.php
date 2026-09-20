<?php
/**
 * Release-tree packaging tests.
 *
 * Regression: bin/build.sh once omitted dropins/, so every zip install
 * failed to generate the advanced-cache drop-in at activation (PHP warnings
 * as "unexpected output" + missing drop-in notice). The build has its own
 * self-check, but this pins the runtime file list at the source level: any
 * file the plugin reads at runtime must exist in the repo tree that the
 * release is zipped from.
 *
 * @package cosmic-cache
 */

namespace CosmicCache\Tests;

use PHPUnit\Framework\TestCase;

class PackagingTest extends TestCase {

	/**
	 * Every file read at runtime outside src/ must ship in the release.
	 *
	 * Mirrors the verification list at the end of bin/build.sh — keep the
	 * two in sync when adding new runtime file dependencies.
	 *
	 * @return array<string,array{string}>
	 */
	public function runtime_files(): array {
		return array(
			'plugin entry'            => array( 'cosmic-cache.php' ),
			'uninstall handler'       => array( 'uninstall.php' ),
			'wp.org readme'           => array( 'readme.txt' ),
			'container'               => array( 'src/Plugin.php' ),
			'settings model'          => array( 'src/Config.php' ),
			'advanced-cache template' => array( 'dropins/advanced-cache.php' ),
			'object-cache template'   => array( 'dropins/object-cache.php' ),
			'page-cache tab'          => array( 'views/page-cache.php' ),
			'optimization tab'        => array( 'views/optimization.php' ),
		);
	}

	/**
	 * @dataProvider runtime_files
	 */
	public function test_runtime_file_ships_in_release_tree( string $rel ): void {
		$this->assertFileExists(
			dirname( __DIR__, 2 ) . '/' . $rel,
			"{$rel} is read at runtime and must exist in the release tree (bin/build.sh would ship a broken zip)."
		);
	}

	public function test_build_script_packages_dropins(): void {
		$build = (string) file_get_contents( dirname( __DIR__, 2 ) . '/bin/build.sh' );
		$this->assertStringContainsString(
			'dropins',
			$build,
			'bin/build.sh must package dropins/ (templates compiled on activation).'
		);
	}
}
