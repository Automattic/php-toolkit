<?php
/**
 * Polyfills WordPress core functions for running in non-WordPress environments
 */

if (
	! isset( $html5_named_character_references ) &&
	file_exists( __DIR__ . '/../HTML/html5-named-character-references.php' )
) {
	require_once __DIR__ . '/../HTML/html5-named-character-references.php';
}

if ( ! class_exists( 'WP_Block_Parser' ) ) {
	require_once __DIR__ . '/../BlockParser/class-wp-block-parser-block.php';
	require_once __DIR__ . '/../BlockParser/class-wp-block-parser-frame.php';
	require_once __DIR__ . '/../BlockParser/class-wp-block-parser.php';
}


if ( ! function_exists( '_doing_it_wrong' ) ) {
	$GLOBALS['_doing_it_wrong_messages'] = array();
	function _doing_it_wrong( $method, $message, $version ) {
		$GLOBALS['_doing_it_wrong_messages'][] = $message;
	}
}

if ( ! function_exists( 'wp_kses_uri_attributes' ) ) {
	function wp_kses_uri_attributes() {
		return array();
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $input ) {
		return $input;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $input ) {
		return htmlspecialchars( $input );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $input ) {
		return htmlspecialchars( $input );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return htmlspecialchars( $url );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		global $wp_filter;
		if ( ! isset( $wp_filter ) ) {
			$wp_filter = array();
		}
		if ( ! isset( $wp_filter[ $hook_name ] ) ) {
			$wp_filter[ $hook_name ] = array();
		}
		if ( ! isset( $wp_filter[ $hook_name ][ $priority ] ) ) {
			$wp_filter[ $hook_name ][ $priority ] = array();
		}
		$wp_filter[ $hook_name ][ $priority ][] = array(
			'function'      => $callback,
			'accepted_args' => $accepted_args,
		);
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $hook_name, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook_name, $value ) {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook_name ] ) ) {
			return $value;
		}
		$args = func_get_args();
		array_shift( $args ); // Remove hook name

		ksort( $wp_filter[ $hook_name ] );
		foreach ( $wp_filter[ $hook_name ] as $priority => $functions ) {
			foreach ( $functions as $function ) {
				$args[0] = $value;
				$value   = call_user_func_array( $function['function'], array_slice( $args, 0, $function['accepted_args'] ) );
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook_name ) {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook_name ] ) ) {
			return;
		}
		$args = func_get_args();
		array_shift( $args ); // Remove hook name

		ksort( $wp_filter[ $hook_name ] );
		foreach ( $wp_filter[ $hook_name ] as $priority => $functions ) {
			foreach ( $functions as $function ) {
				call_user_func_array( $function['function'], array_slice( $args, 0, $function['accepted_args'] ) );
			}
		}
	}
}

if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( $input ) {
		$parser = new WP_Block_Parser();
		return $parser->parse( $input );
	}
}
