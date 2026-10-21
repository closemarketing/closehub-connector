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

	/** @var array<int, array<int, array{post_id:int, language:string, source_language:?string}>> Group members keyed by trid. */
	private static array $members_cache = [];

	private static function element_type( WP_Post $post ): string {
		return (string) apply_filters( 'wpml_element_type', $post->post_type );
	}

	private static function raw_details( WP_Post $post ): ?object {
		$details = apply_filters( 'wpml_element_language_details', null, [ 'element_id' => $post->ID, 'element_type' => self::element_type( $post ) ] );
		return is_object( $details ) && ! empty( $details->language_code ) ? $details : null;
	}

	/** Every element of one translation group, read straight from icl_translations so the active language filter never hides one. */
	private static function members( int $trid, string $element_type ): array {
		if ( ! isset( self::$members_cache[ $trid ] ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT element_id, language_code, source_language_code FROM {$wpdb->prefix}icl_translations WHERE trid = %d AND element_type = %s AND element_id IS NOT NULL ORDER BY language_code", $trid, $element_type ) );
			self::$members_cache[ $trid ] = array_map(
				static fn( $row ): array => [ 'post_id' => (int) $row->element_id, 'language' => (string) $row->language_code, 'source_language' => empty( $row->source_language_code ) ? null : (string) $row->source_language_code ],
				(array) $rows
			);
		}
		return self::$members_cache[ $trid ];
	}

	/** Load the translation groups of many posts with one query (list-posts would otherwise run one per post). */
	public static function prefetch( array $posts ): void {
		if ( ! has_filter( 'wpml_element_language_details' ) || ! $posts ) {
			return;
		}
		global $wpdb;
		$trids = [];
		$type  = '';
		foreach ( $posts as $post ) {
			$details = $post instanceof WP_Post ? self::raw_details( $post ) : null;
			if ( $details && ! empty( $details->trid ) && ! isset( self::$members_cache[ (int) $details->trid ] ) ) {
				$trids[ (int) $details->trid ] = (int) $details->trid;
				$type = self::element_type( $post );
			}
		}
		if ( ! $trids ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $trids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT trid, element_id, language_code, source_language_code FROM {$wpdb->prefix}icl_translations WHERE element_type = %s AND element_id IS NOT NULL AND trid IN ($placeholders) ORDER BY language_code", array_merge( [ $type ], array_values( $trids ) ) ) );
		foreach ( $trids as $trid ) {
			self::$members_cache[ $trid ] = [];
		}
		foreach ( (array) $rows as $row ) {
			self::$members_cache[ (int) $row->trid ][] = [ 'post_id' => (int) $row->element_id, 'language' => (string) $row->language_code, 'source_language' => empty( $row->source_language_code ) ? null : (string) $row->source_language_code ];
		}
	}

	/** All translations of a post, itself included, as [ language, post_id, translation_group, status ]. */
	public static function translations( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post || ! has_filter( 'wpml_element_language_details' ) ) {
			return [];
		}
		$details = self::raw_details( $post );
		if ( ! $details || empty( $details->trid ) ) {
			return [];
		}
		$trid = (int) $details->trid;
		return array_map(
			static fn( array $member ): array => [ 'language' => $member['language'], 'post_id' => $member['post_id'], 'translation_group' => $trid, 'status' => (string) get_post_status( $member['post_id'] ) ],
			self::members( $trid, self::element_type( $post ) )
		);
	}

	private static function refresh( array $post_ids ): void {
		self::$members_cache = [];
		foreach ( array_unique( $post_ids ) as $id ) {
			clean_post_cache( (int) $id );
		}
	}

	private static function is_translatable( WP_Post $post ): bool {
		return ! has_filter( 'wpml_is_translated_post_type' ) || (bool) apply_filters( 'wpml_is_translated_post_type', false, $post->post_type );
	}

	private static function result( int $post_id, ?int $previous_trid, array $affected ): array {
		$details = self::details( $post_id );
		return [
			'post_id'                    => $post_id,
			'language'                   => $details['language'] ?? '',
			'source_language'            => $details['source_language'] ?? null,
			'translation_group'          => $details['translation_group'] ?? null,
			'previous_translation_group' => $previous_trid,
			'translations'               => self::translations( $post_id ),
			'affected_post_ids'          => array_values( array_unique( array_map( 'intval', $affected ) ) ),
		];
	}

	/**
	 * Attach an existing post to the translation group of $translation_of. Never creates or edits content.
	 * With $force, an existing translation in that language is detached and a post that is itself the source of other translations may be moved.
	 */
	public static function link( int $post_id, int $translation_of, string $language, bool $force = false ): array|WP_Error {
		$language = $language ? self::validate( $language, $translation_of ) : new WP_Error( 'closehub_invalid_language', 'language is required to link translations.', [ 'status' => 400 ] );
		if ( is_wp_error( $language ) ) {
			return $language;
		}
		if ( ! $post_id || $post_id === $translation_of ) {
			return new WP_Error( 'closehub_invalid_translation_link', 'post_id and translation_of must be two different posts.', [ 'status' => 400 ] );
		}
		$post   = get_post( $post_id );
		$source = get_post( $translation_of );
		if ( ! $post || ! $source ) {
			return new WP_Error( 'closehub_post_not_found', 'Post not found.', [ 'status' => 404 ] );
		}
		if ( $post->post_type !== $source->post_type ) {
			return new WP_Error( 'closehub_post_type_mismatch', 'post_id and translation_of must have the same post type.', [ 'status' => 400 ] );
		}
		if ( ! self::is_translatable( $post ) ) {
			return new WP_Error( 'closehub_post_type_not_translatable', sprintf( 'The "%s" post type is not translatable in WPML.', $post->post_type ), [ 'status' => 400 ] );
		}
		$source_details = self::raw_details( $source );
		if ( ! $source_details || empty( $source_details->trid ) ) {
			return new WP_Error( 'closehub_invalid_translation_source', 'translation_of has no WPML language details.', [ 'status' => 400 ] );
		}
		if ( ! empty( $source_details->source_language_code ) ) {
			return new WP_Error( 'closehub_translation_source_not_original', 'translation_of is itself a translation; use the original post as the source.', [ 'status' => 400 ] );
		}
		if ( $source_details->language_code === $language ) {
			return new WP_Error( 'closehub_invalid_translation_source', 'translation_of must be in a different language than language.', [ 'status' => 400 ] );
		}
		$details = self::raw_details( $post );
		if ( $details && $details->language_code !== $language ) {
			return new WP_Error( 'closehub_language_mismatch', sprintf( 'Post %d is in "%s", not "%s". Its language is never changed by linking.', $post_id, $details->language_code, $language ), [ 'status' => 409 ] );
		}
		$element_type = self::element_type( $post );
		$new_trid     = (int) $source_details->trid;
		$old_trid     = $details && ! empty( $details->trid ) ? (int) $details->trid : null;
		$new_members  = self::members( $new_trid, $element_type );
		$affected     = array_merge( [ $post_id, $translation_of ], array_column( $new_members, 'post_id' ) );

		if ( $old_trid === $new_trid && $details && (string) ( $details->source_language_code ?? '' ) === (string) $source_details->language_code ) {
			return self::result( $post_id, $old_trid, $affected ) + [ 'changed' => false ];
		}

		$detached = null;
		foreach ( $new_members as $member ) {
			if ( $member['language'] === $language && $member['post_id'] !== $post_id ) {
				if ( ! $force ) {
					return new WP_Error( 'closehub_translation_conflict', sprintf( 'Post %d is already the "%s" translation of this group. Pass force=true to detach it, or unlink it first.', $member['post_id'], $language ), [ 'status' => 409, 'conflicting_post_id' => $member['post_id'] ] );
				}
				$detached = $member['post_id'];
			}
		}
		if ( $old_trid && $old_trid !== $new_trid ) {
			$old_members = self::members( $old_trid, $element_type );
			$affected    = array_merge( $affected, array_column( $old_members, 'post_id' ) );
			$dependents  = array_filter( $old_members, static fn( array $m ): bool => $m['post_id'] !== $post_id && $m['source_language'] === $language );
			if ( $dependents && ! $force ) {
				return new WP_Error( 'closehub_translation_has_dependents', sprintf( 'Post %d is the source of other translations (%s). Pass force=true to move it anyway, leaving them in the old group.', $post_id, implode( ', ', array_column( $dependents, 'post_id' ) ) ), [ 'status' => 409, 'dependent_post_ids' => array_values( array_column( $dependents, 'post_id' ) ) ] );
			}
		}

		if ( $detached ) {
			$detached_post = get_post( $detached );
			$detach        = $detached_post ? self::detach( $detached_post ) : null;
			if ( is_wp_error( $detach ) ) {
				return $detach;
			}
		}
		do_action( 'wpml_set_element_language_details', [ 'element_id' => $post_id, 'element_type' => $element_type, 'trid' => $new_trid, 'language_code' => $language, 'source_language_code' => $source_details->language_code ] );
		self::refresh( $affected );

		$result = self::result( $post_id, $old_trid, $affected );
		if ( (int) ( $result['translation_group'] ?? 0 ) !== $new_trid ) {
			return new WP_Error( 'closehub_translation_link_failed', 'WPML did not apply the translation link.', [ 'status' => 500 ] );
		}
		return $result + [ 'changed' => true ] + ( $detached ? [ 'detached_post_id' => $detached ] : [] );
	}

	/** Move one post into a translation group of its own, keeping its language. */
	private static function detach( WP_Post $post ): bool|WP_Error {
		$details = self::raw_details( $post );
		if ( ! $details ) {
			return new WP_Error( 'closehub_post_not_translatable', 'Post has no WPML language details.', [ 'status' => 400 ] );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$new_trid = (int) $wpdb->get_var( "SELECT MAX(trid) + 1 FROM {$wpdb->prefix}icl_translations" );
		if ( $new_trid < 1 ) {
			return new WP_Error( 'closehub_translation_unlink_failed', 'Could not allocate a new translation group.', [ 'status' => 500 ] );
		}
		do_action( 'wpml_set_element_language_details', [ 'element_id' => $post->ID, 'element_type' => self::element_type( $post ), 'trid' => $new_trid, 'language_code' => (string) $details->language_code, 'source_language_code' => null ] );
		self::$members_cache = [];
		return true;
	}

	/** Split a translation off its group. The original (source) post of a group cannot be unlinked while translations remain. */
	public static function unlink( int $post_id ): array|WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'closehub_post_not_found', 'Post not found.', [ 'status' => 404 ] );
		}
		if ( ! has_filter( 'wpml_element_language_details' ) ) {
			return new WP_Error( 'closehub_wpml_unavailable', 'WPML must be active to unlink translations.', [ 'status' => 400 ] );
		}
		$details = self::raw_details( $post );
		if ( ! $details || empty( $details->trid ) ) {
			return new WP_Error( 'closehub_post_not_translatable', 'Post has no WPML language details.', [ 'status' => 400 ] );
		}
		$old_trid = (int) $details->trid;
		$members  = self::members( $old_trid, self::element_type( $post ) );
		$others   = array_filter( $members, static fn( array $m ): bool => $m['post_id'] !== $post_id );
		if ( ! $others ) {
			return new WP_Error( 'closehub_translation_not_linked', 'Post is not linked to any other translation.', [ 'status' => 409 ] );
		}
		if ( empty( $details->source_language_code ) ) {
			return new WP_Error( 'closehub_translation_is_source', 'Post is the original of this group. Unlink its translations instead.', [ 'status' => 409, 'translation_post_ids' => array_values( array_column( $others, 'post_id' ) ) ] );
		}
		$affected = array_column( $members, 'post_id' );
		$detached = self::detach( $post );
		if ( is_wp_error( $detached ) ) {
			return $detached;
		}
		self::refresh( $affected );
		return self::result( $post_id, $old_trid, $affected );
	}
}
