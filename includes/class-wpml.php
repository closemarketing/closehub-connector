<?php

defined( 'ABSPATH' ) || exit;

/** Optional WPML bridge. It has no effect when WPML is not active. */
class CloseHub_WPML {
	public static function validate( string $language, int $translation_of = 0 ): string|WP_Error {
		if ( '' === $language && ! $translation_of ) {
			return '';
		}
		if ( ! has_filter( 'wpml_active_languages' ) ) {
			return new WP_Error( 'closehub_wpml_unavailable', 'WPML must be active to set a content language.', [ 'status' => 400 ] );
		}
		$languages = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
		if ( '' === $language || ! is_array( $languages ) || ! isset( $languages[ $language ] ) ) {
			return new WP_Error( 'closehub_invalid_language', 'The requested WPML language is not active.', [ 'status' => 400 ] );
		}
		return $language;
	}

	public static function assign( int $post_id, string $language, int $translation_of = 0 ): bool|WP_Error {
		$valid = self::validate( $language, $translation_of );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( '' === $valid ) {
			return true;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'closehub_post_not_found', 'Post not found.', [ 'status' => 404 ] );
		}
		$element_type = apply_filters( 'wpml_element_type', $post->post_type );
		$args = [ 'element_id' => $post_id, 'element_type' => $element_type, 'trid' => false, 'language_code' => $valid, 'source_language_code' => null ];
		if ( $translation_of ) {
			$source = get_post( $translation_of );
			if ( ! $source || $source->post_type !== $post->post_type ) {
				return new WP_Error( 'closehub_invalid_translation_source', 'translation_of must identify a post with the same post type.', [ 'status' => 400 ] );
			}
			$source_details = apply_filters( 'wpml_element_language_details', null, [ 'element_id' => $translation_of, 'element_type' => $element_type ] );
			if ( ! is_object( $source_details ) || empty( $source_details->trid ) || empty( $source_details->language_code ) || $source_details->language_code === $valid ) {
				return new WP_Error( 'closehub_invalid_translation_source', 'translation_of must be a WPML source in another language.', [ 'status' => 400 ] );
			}
			$args['trid']                 = (int) $source_details->trid;
			$args['source_language_code'] = $source_details->language_code;
		}
		do_action( 'wpml_set_element_language_details', $args );
		return true;
	}

	public static function details( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post || ! has_filter( 'wpml_element_language_details' ) ) {
			return [];
		}
		$element_type = apply_filters( 'wpml_element_type', $post->post_type );
		$details = apply_filters( 'wpml_element_language_details', null, [ 'element_id' => $post_id, 'element_type' => $element_type ] );
		if ( ! is_object( $details ) || empty( $details->language_code ) ) {
			return [];
		}
		return [ 'language' => (string) $details->language_code, 'translation_group' => isset( $details->trid ) ? (int) $details->trid : null, 'source_language' => $details->source_language_code ?? null ];
	}
}
