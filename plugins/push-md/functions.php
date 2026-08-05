<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'push_md_install_skill' ) ) {
	function push_md_install_skill( string $source_identifier, string $title, string $excerpt, string $content, array $extras = array() ) {
		if ( '' === $source_identifier ) {
			return new WP_Error( 'missing_source_identifier', 'A non-empty $source_identifier is required.' );
		}

		if ( ! post_type_exists( 'wp_knowledge' ) || ! taxonomy_exists( 'wp_knowledge_type' ) ) {
			return new WP_Error( 'knowledge_unavailable', 'The WordPress Knowledge post type and taxonomy are not registered on this site.' );
		}

		$knowledge_post_type = get_post_type_object( 'wp_knowledge' );
		$publish_capability  = $knowledge_post_type && isset( $knowledge_post_type->cap->publish_posts )
			? $knowledge_post_type->cap->publish_posts
			: 'publish_posts';

		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( $publish_capability ) ) {
			return new WP_Error( 'knowledge_forbidden', 'You do not have permission to install a site-wide Knowledge skill.' );
		}

		$existing = get_posts(
			array(
				'post_type'      => 'wp_knowledge',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private', 'trash' ),
				'meta_key'       => 'push_md_knowledge_source', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
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
			'post_type'    => 'wp_knowledge',
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

		$term = term_exists( 'skill', 'wp_knowledge_type' );
		if ( ! $term ) {
			$term = wp_insert_term( __( 'Skill', 'push-md' ), 'wp_knowledge_type', array( 'slug' => 'skill' ) );
		}
		if ( is_wp_error( $term ) ) {
			wp_delete_post( $post_id, true );
			return $term;
		}

		$term_id   = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
		$set_terms = wp_set_object_terms( $post_id, array( $term_id ), 'wp_knowledge_type' );
		if ( is_wp_error( $set_terms ) ) {
			wp_delete_post( $post_id, true );
			return $set_terms;
		}
		update_post_meta( $post_id, 'push_md_knowledge_source', sanitize_text_field( $source_identifier ) );

		return array(
			'id'      => (int) $post_id,
			'created' => true,
		);
	}
}
