<?php
/**
 * Field helpers shared by settings views.
 *
 * @package cosmic-cache
 */

if ( ! function_exists( 'cosmic_field_checkbox' ) ) :

	/**
	 * @param string $key
	 * @param string $label
	 * @param string $description
	 * @param array<string,mixed> $settings
	 * @return void
	 */
	function cosmic_field_checkbox( string $key, string $label, string $description, array $settings ): void {
		$checked = ! empty( $settings[ $key ] ) ? 'checked="checked"' : '';
		echo '<tr><th scope="row">' . esc_html__( $label, 'cosmic-cache' ) . '</th><td>';
		echo '<input type="hidden" name="cosmic_cache_settings[' . esc_attr( $key ) . ']" value="0" />';
		echo '<label><input type="checkbox" name="cosmic_cache_settings[' . esc_attr( $key ) . ']" value="1" ' . $checked . ' /> '
			. '<span class="description">' . esc_html__( $description, 'cosmic-cache' ) . '</span></label>';
		echo '</td></tr>';
	}

	/**
	 * @param string $key
	 * @param string $label
	 * @param string $description
	 * @param string $type
	 * @param array<string,mixed> $settings
	 * @param array<string,string> $attrs Extra input attributes (e.g. min, max, step).
	 * @return void
	 */
	function cosmic_field_text( string $key, string $label, string $description, array $settings, string $type = 'text', array $attrs = array() ): void {
		$value   = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
		$extra   = '';
		foreach ( $attrs as $name => $attr_value ) {
			$extra .= ' ' . esc_attr( $name ) . '="' . esc_attr( (string) $attr_value ) . '"';
		}
		echo '<tr><th scope="row">' . esc_html__( $label, 'cosmic-cache' ) . '</th><td>';
		echo '<input type="' . esc_attr( $type ) . '" name="cosmic_cache_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" class="regular-text"' . $extra . ' />';
		if ( $description ) {
			echo '<p class="description">' . esc_html__( $description, 'cosmic-cache' ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * List field stored as an array (comma-separated input).
	 *
	 * @param string $key
	 * @param string $label
	 * @param string $description
	 * @param array<string,mixed> $settings
	 * @return void
	 */
	function cosmic_field_list( string $key, string $label, string $description, array $settings ): void {
		$value = isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ? implode( ', ', $settings[ $key ] ) : '';
		echo '<tr><th scope="row">' . esc_html__( $label, 'cosmic-cache' ) . '</th><td>';
		echo '<input type="text" name="cosmic_cache_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" class="regular-text" />';
		if ( $description ) {
			echo '<p class="description">' . esc_html__( $description, 'cosmic-cache' ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Textarea field stored as an array (one item per line).
	 *
	 * @param string $key
	 * @param string $label
	 * @param string $description
	 * @param array<string,mixed> $settings
	 * @return void
	 */
	function cosmic_field_textarea_list( string $key, string $label, string $description, array $settings ): void {
		$value = isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ? implode( "\n", $settings[ $key ] ) : '';
		echo '<tr><th scope="row">' . esc_html__( $label, 'cosmic-cache' ) . '</th><td>';
		echo '<textarea name="cosmic_cache_settings[' . esc_attr( $key ) . ']" rows="5" class="large-text">' . esc_textarea( $value ) . '</textarea>';
		if ( $description ) {
			echo '<p class="description">' . esc_html__( $description, 'cosmic-cache' ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Textarea field stored as a plain string (e.g. pasted critical CSS).
	 *
	 * @param string $key
	 * @param string $label
	 * @param string $description
	 * @param array<string,mixed> $settings
	 * @return void
	 */
	function cosmic_field_textarea( string $key, string $label, string $description, array $settings ): void {
		$value = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
		echo '<tr><th scope="row">' . esc_html__( $label, 'cosmic-cache' ) . '</th><td>';
		echo '<textarea name="cosmic_cache_settings[' . esc_attr( $key ) . ']" rows="8" class="large-text code">' . esc_textarea( $value ) . '</textarea>';
		if ( $description ) {
			echo '<p class="description">' . esc_html__( $description, 'cosmic-cache' ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Select field.
	 *
	 * @param string $key
	 * @param string $label
	 * @param string $description
	 * @param array<string,string> $options
	 * @param array<string,mixed> $settings
	 * @return void
	 */
	function cosmic_field_select( string $key, string $label, string $description, array $options, array $settings ): void {
		$current = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
		echo '<tr><th scope="row">' . esc_html__( $label, 'cosmic-cache' ) . '</th><td>';
		echo '<select name="cosmic_cache_settings[' . esc_attr( $key ) . ']">';
		foreach ( $options as $value => $text ) {
			$selected = ( $current === $value ) ? 'selected="selected"' : '';
			echo '<option value="' . esc_attr( $value ) . '" ' . $selected . '>' . esc_html__( $text, 'cosmic-cache' ) . '</option>';
		}
		echo '</select>';
		if ( $description ) {
			echo '<p class="description">' . esc_html__( $description, 'cosmic-cache' ) . '</p>';
		}
		echo '</td></tr>';
	}

endif;
