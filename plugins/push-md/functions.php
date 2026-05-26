<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'push_md_install_skill' ) ) {
	function push_md_install_skill( string $source_identifier, string $title, string $excerpt, string $content, array $extras = array() ) {
		if ( '' === $source_identifier ) {
			return new WP_Error( 'missing_source_identifier', 'A non-empty $source_identifier is required.' );
		}

		if ( ! post_type_exists( 'wp_guideline' ) || ! taxonomy_exists( 'wp_guideline_type' ) ) {
			return new WP_Error( 'guidelines_unavailable', 'Guidelines CPT/taxonomy are not registered on this blog.' );
		}

		$existing = get_posts(
			array(
				'post_type'      => 'wp_guideline',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private', 'trash' ),
				'meta_key'       => 'push_md_guideline_source', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $source_identifier, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);

		if ( ! empty( $existing ) ) {
			return array(
				'id'      => (int) $existing[0]->ID,
				'created' => false,
			);
		}

		$insert_args = array(
			'post_type'    => 'wp_guideline',
			'post_status'  => 'publish',
			'post_author'  => get_current_user_id(),
			'post_title'   => $title,
			'post_excerpt' => $excerpt,
			'post_content' => wp_kses_post( $content ),
		);

		$protected = array( 'post_type', 'post_status', 'post_author', 'post_title', 'post_excerpt', 'post_content' );
		foreach ( $protected as $key ) {
			unset( $extras[ $key ] );
		}
		$insert_args = array_merge( $extras, $insert_args );

		$post_id = wp_insert_post( $insert_args, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		wp_set_object_terms( $post_id, 'skill', 'wp_guideline_type' );
		update_post_meta( $post_id, 'push_md_guideline_source', sanitize_text_field( $source_identifier ) );

		return array(
			'id'      => (int) $post_id,
			'created' => true,
		);
	}
}
