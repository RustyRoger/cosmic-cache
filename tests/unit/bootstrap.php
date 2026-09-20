<?php
/**
 * PHPUnit bootstrap for unit tests.
 *
 * Loads the composer autoloader and defines minimal WordPress function stubs
 * so pure-logic classes can be exercised WITHOUT booting WordPress (SPEC §17).
 *
 * @package cosmic-cache
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/cosmic-tests/wp-content' );
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/cosmic-tests/' );
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '', $scheme = null ) {
		return 'https://example.com' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'is_ssl' ) ) {
	function is_ssl() {
		return false;
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return $url;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( $text ) {
		return $text;
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $text ) {
		return $text;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}

if ( ! function_exists( '_e' ) ) {
	function _e( $text, $domain = null ) {
		echo $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = null ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = null ) {
		echo $text;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback ) {
		$store                 = &GLOBALS_filters_store();
		$store[ $tag ][] = $callback;
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $tag ) {
		$store = GLOBALS_filters_store();
		return ! empty( $store[ $tag ] );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		$store = GLOBALS_filters_store();
		foreach ( (array) ( $store[ $tag ] ?? array() ) as $callback ) {
			$value = call_user_func_array( $callback, array_merge( array( $value ), $args ) );
		}
		return $value;
	}
}

if ( ! function_exists( 'GLOBALS_filters_store' ) ) {
	/**
	 * Filter storage in function-static state rather than $GLOBALS, so it is
	 * never serialized when PHPUnit spawns a separate-process test (closures
	 * cannot be serialized). Returns by reference so callers can mutate the
	 * static array in place.
	 *
	 * @return array<string,array>
	 */
	function &GLOBALS_filters_store() {
		static $filters = array();
		return $filters;
	}
}

if ( ! function_exists( 'GLOBALS_filters_clear' ) ) {
	/**
	 * Drop every registered filter. Called in test setUp so closures from one
	 * test never leak into the next (the static store survives within a
	 * process).
	 */
	function GLOBALS_filters_clear() {
		$store = &GLOBALS_filters_store();
		$store = array();
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action() {}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		$store = isset( $GLOBALS['cosmic_test_options'] ) ? $GLOBALS['cosmic_test_options'] : array();
		return isset( $store[ $option ] ) ? $store[ $option ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		$GLOBALS['cosmic_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) {
		unset( $GLOBALS['cosmic_test_options'][ $option ] );
		return true;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['cosmic_test_transients'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return isset( $GLOBALS['cosmic_test_transients'][ $key ] ) ? $GLOBALS['cosmic_test_transients'][ $key ] : false;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['cosmic_test_transients'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() {
		return 1;
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() {
		return ! empty( $GLOBALS['cosmic_test_multisite'] );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return (int) ( $GLOBALS['cosmic_test_user_id'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		return new WP_Error( 'http', 'not available in unit tests' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return 0;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public function __construct( $code = '', $message = '' ) {
			$this->code = $code;
		}
	}
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
