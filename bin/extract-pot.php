#!/usr/bin/env php
<?php
/**
 * Build script: extract translatable strings (GNU gettext .pot) and package
 * the plugin as cosmic-cache.zip for distribution.
 *
 * The .pot extraction is intentionally dependency-free (no wp-cli), matching
 * the "pure PHP, zero runtime dependencies" rule.
 *
 * @package cosmic-cache
 */

/**
 * Scan PHP sources for the cosmic-cache text domain calls.
 *
 * Handles `__( '..', 'cosmic-cache' )`, `_e( '..', 'cosmic-cache' )`,
 * `esc_html__( ... )`, `esc_html_e( ... )` and their `sprintf()` wrappers
 * where the placeholder runs are still literal strings in the file.
 *
 * @param string[] $files
 * @return array<int,string> Found msgid strings (unique).
 */
function cosmic_extract_strings( array $files ): array {
	$msgs = array();
	// Matches only the i18n call forms; the text must be a single-quoted or
	// double-quoted literal passed directly to the function.
	$rx   = '/(?:__|_e|esc_html__|esc_html_e)\s*\(\s*([\'"])(.*?[^\\\\])\1\s*,\s*[\'"]cosmic-cache[\'"]\s*\)/';
	foreach ( $files as $file ) {
		$code = (string) file_get_contents( $file );
		if ( preg_match_all( $rx, $code, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[2] as $k => $match ) {
				$text = $match[0];
				$pos  = $m[0][ $k ][1];
				// Skip matches inside a line comment or docblock.
				$line = $code;
				$before = substr( $line, 0, $pos );
				$noComment = substr( $before, (int) strrpos( $before, "\n" ) + 1 );
				if ( false !== strpos( $noComment, '//' ) || false !== strpos( $noComment, '*' ) ) {
					continue;
				}
				$msgs[ $text ] = $file;
			}
		}
	}
	return $msgs;
}

/**
 * Walk a directory for PHP files.
 *
 * @param string $dir
 * @return string[]
 */
function cosmic_php_files( string $dir ): array {
	$out   = array();
	$it    = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $it as $file ) {
		if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
			$out[] = $file->getPathname();
		}
	}
	return $out;
}

$root = dirname( __DIR__ );
$files = array_merge(
	cosmic_php_files( $root . '/src' ),
	cosmic_php_files( $root . '/views' ),
	array( $root . '/cosmic-cache.php', $root . '/uninstall.php' )
);
$files = array_values( array_unique( $files ) );

$msgs   = cosmic_extract_strings( $files );
$pot    = "msgid \"\"\nmsgstr \"\"\n"
	. "\"Project-Id-Version: Cosmic Cache 1.1.3\\n\"\n"
	. "\"POT-Creation-Date: " . gmdate( 'Y-m-d H:iO' ) . "\\n\"\n"
	. "\"MIME-Version: 1.0\\n\"\n"
	. "\"Content-Type: text/plain; charset=UTF-8\\n\"\n"
	. "\"Content-Transfer-Encoding: 8bit\\n\"\n"
	. "\"Language-Team: none\\n\"\n"
	. "\"X-Generator: cosmic-cache build.sh\\n\"\n"
	. "\"X-Domain: cosmic-cache\\n\"\n\n";

foreach ( $msgs as $text => $file ) {
	$loc    = str_replace( $root . '/', '', $file );
	$esc    = str_replace( '"', '\\"', $text );
	$pot   .= "#: $loc\nmsgid \"$esc\"\nmsgstr \"\"\n\n";
}

@mkdir( $root . '/languages', 0755, true );
$potFile = $root . '/languages/cosmic-cache.pot';
file_put_contents( $potFile, $pot );

echo 'POT: ' . count( $msgs ) . " strings -> languages/cosmic-cache.pot\n";
return 0;