<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Path filter utility for Push MD to determine supported vs ignored repository paths.
 */
class Push_MD_Path_Filter {

	/**
	 * Exact allowed metadata and documentation files at the root of the repository.
	 *
	 * @var array
	 */
	private static $allowed_root_files = array(
		'categories.md',
		'tags.md',
		'authors.md',
		'AGENTS.md',
		'CLAUDE.md',
		'README.md',
		'readme.md',
	);

	/**
	 * Allowed top-level prefix directories for skills / agent guidance.
	 *
	 * @var array
	 */
	private static $allowed_guidance_prefixes = array(
		'.agents/',
		'.claude/',
	);

	/**
	 * Checks if a given path is supported by Push MD for WordPress processing.
	 *
	 * @param string $path File path relative to repository root.
	 * @return bool True if supported, false if ignored.
	 */
	public static function is_supported_path( $path ) {
		$clean_path = ltrim( (string) $path, '/' );

		if ( '' === $clean_path ) {
			return false;
		}

		// Never process .gitignore file as a WordPress content object.
		if ( '.gitignore' === $clean_path ) {
			return false;
		}

		// Root metadata and documentation files.
		if ( in_array( $clean_path, self::$allowed_root_files, true ) ) {
			return true;
		}

		// Guidance/skills directories (.agents/, .claude/).
		foreach ( self::$allowed_guidance_prefixes as $prefix ) {
			if ( 0 === strpos( $clean_path, $prefix ) ) {
				return true;
			}
		}

		// Media attachment files.
		if ( 0 === strpos( $clean_path, 'media/' ) || ( class_exists( 'Push_MD_Media' ) && Push_MD_Media::is_media_path( $clean_path ) ) ) {
			return true;
		}

		// Check against supported post types and structural entity directories.
		$segments = explode( '/', $clean_path );
		$root_dir = $segments[0];

		$default_entity_types = array(
			'post',
			'page',
			'wp_template',
			'wp_template_part',
			'wp_navigation',
			'wp_theme',
			'wp_global_styles',
			'wp_guideline',
		);

		$supported_post_types = class_exists( 'Push_MD_Plugin' ) ? Push_MD_Plugin::get_supported_post_types() : array();
		$allowed_root_dirs    = array_unique( array_merge( $default_entity_types, $supported_post_types ) );

		if ( ! in_array( $root_dir, $allowed_root_dirs, true ) ) {
			return false;
		}

		// Post type specific extension and structure validation.
		if ( 'wp_template' === $root_dir || 'wp_template_part' === $root_dir || 'wp_navigation' === $root_dir ) {
			return 'html' === pathinfo( $clean_path, PATHINFO_EXTENSION );
		}

		if ( 'wp_theme' === $root_dir || 'wp_global_styles' === $root_dir ) {
			return 'json' === pathinfo( $clean_path, PATHINFO_EXTENSION );
		}

		if ( 'wp_guideline' === $root_dir ) {
			return 'md' === pathinfo( $clean_path, PATHINFO_EXTENSION );
		}

		if ( 'post' === $root_dir ) {
			return 2 === count( $segments ) && 'md' === pathinfo( $clean_path, PATHINFO_EXTENSION );
		}

		if ( 'page' === $root_dir ) {
			return count( $segments ) >= 2 && 'md' === pathinfo( $clean_path, PATHINFO_EXTENSION );
		}

		// Fallback for custom or newly registered post types: must be a .md file.
		return 'md' === pathinfo( $clean_path, PATHINFO_EXTENSION );
	}

	/**
	 * Checks if a given path should be ignored by Push MD.
	 *
	 * @param string $path File path relative to repository root.
	 * @return bool True if ignored, false if supported.
	 */
	public static function is_ignored_path( $path ) {
		return ! self::is_supported_path( $path );
	}
}
