<?php

defined( 'ABSPATH' ) || exit;

/**
 * Optional TranslatePress bridge. It has no effect when TranslatePress is not active.
 *
 * TranslatePress translates strings of one post into every language instead of keeping one post per language
 * like WPML does. So there is nothing to link, duplicate or create per language: this bridge only manages
 * the site languages and reports the URL each published language serves a post on.
 */
class CloseHub_TranslatePress {
	public static function active(): bool {
		return class_exists( 'TRP_Translate_Press' );
	}

	/** Why a WPML-style operation cannot run on TranslatePress. $action completes "it cannot ...". */
	public static function unsupported( string $action ): WP_Error {
		return new WP_Error( 'closehub_translatepress_unsupported', sprintf( 'TranslatePress translates one post into every language instead of keeping one post per language, so it cannot %s. Use WPML for per-language content.', $action ), [ 'status' => 400 ] );
	}

	// TranslatePress loads its settings once per request, for the site the request started on. These methods read the
	// trp_settings option of the current site instead, so they stay correct after CloseHub_Network switches site.

	/** @return array<string, mixed> */
	private static function settings(): array {
		$settings = get_option( 'trp_settings', [] );
		return is_array( $settings ) ? $settings : [];
	}

	public static function default_language(): string {
		$default = (string) ( self::settings()['default-language'] ?? '' );
		return '' !== $default ? $default : (string) get_locale();
	}

	/** @return string[] Languages that are being translated, the default one first. */
	private static function language_codes(): array {
		$codes   = array_map( 'strval', array_values( (array) ( self::settings()['translation-languages'] ?? [] ) ) );
		$default = self::default_language();
		return array_values( array_unique( array_merge( [ $default ], $codes ) ) );
	}

	/** @return string[] Languages visitors can see. The default language is always one of them. */
	private static function published_codes(): array {
		$settings  = self::settings();
		$published = array_map( 'strval', array_values( (array) ( $settings['publish-languages'] ?? [] ) ) );
		return array_values( array_unique( array_merge( [ self::default_language() ], $published ) ) );
	}

	private static function slug( string $code ): string {
		$slug = (string) ( ( (array) ( self::settings()['url-slugs'] ?? [] ) )[ $code ] ?? '' );
		return '' !== $slug ? $slug : $code;
	}

	private static function languages_component(): ?object {
		$trp       = TRP_Translate_Press::get_trp_instance();
		$component = is_object( $trp ) ? $trp->get_component( 'languages' ) : null;
		return is_object( $component ) ? $component : null;
	}

	/** @return array<string, string> Every language TranslatePress supports (code => name). */
	private static function catalog( string $name_type ): array {
		$component = self::languages_component();
		return $component && method_exists( $component, 'get_languages' ) ? array_map( 'strval', (array) $component->get_languages( $name_type ) ) : [];
	}

	/** Languages being translated (code, name, native name, default flag, whether visitors see it, URL slug) and, with $include_available, the supported ones not yet added. */
	public static function languages( bool $include_available = false ): array|WP_Error {
		if ( ! self::active() ) {
			return new WP_Error( 'closehub_translatepress_unavailable', 'TranslatePress must be active to manage languages.', [ 'status' => 400 ] );
		}
		$english   = self::catalog( 'english_name' );
		$native    = self::catalog( 'native_name' );
		$default   = self::default_language();
		$published = self::published_codes();
		$codes     = self::language_codes();
		$out       = [];
		foreach ( $codes as $code ) {
			$out[] = [
				'code'        => $code,
				'name'        => $english[ $code ] ?? $code,
				'native_name' => $native[ $code ] ?? ( $english[ $code ] ?? $code ),
				'locale'      => $code,
				'default'     => $code === $default,
				'published'   => in_array( $code, $published, true ),
				'url_slug'    => self::slug( $code ),
			];
		}
		$result = [ 'default_language' => $default, 'languages' => $out ];
		if ( $include_available ) {
			$available = [];
			foreach ( $english as $code => $name ) {
				if ( ! in_array( (string) $code, $codes, true ) ) {
					$available[] = [ 'code' => (string) $code, 'name' => $name, 'native_name' => $native[ $code ] ?? $name, 'locale' => (string) $code ];
				}
			}
			$result['available_languages'] = $available;
		}
		return $result;
	}

	/**
	 * Add a language to translate into. TranslatePress codes are WordPress locales ("fr_FR"), not WPML's "fr".
	 * The value is saved through TranslatePress' own settings sanitizer, which creates the language's translation
	 * tables and downloads its WordPress language pack, exactly as saving the TranslatePress settings page does.
	 */
	public static function add_language( string $code, bool $publish = true ): array|WP_Error {
		if ( ! self::active() ) {
			return new WP_Error( 'closehub_translatepress_unavailable', 'TranslatePress must be active to manage languages.', [ 'status' => 400 ] );
		}
		$code      = sanitize_text_field( $code );
		$supported = self::catalog( 'english_name' );
		if ( '' === $code || ! isset( $supported[ $code ] ) ) {
			return new WP_Error( 'closehub_invalid_language', 'The language code is not supported by TranslatePress. TranslatePress uses locale codes such as "fr_FR"; use list-languages with include_available to see the valid codes.', [ 'status' => 400 ] );
		}
		if ( function_exists( 'ms_is_switched' ) && ms_is_switched() ) {
			return new WP_Error( 'closehub_translatepress_other_site', 'TranslatePress has loaded the settings of another site of this network in this request, so changing languages here could overwrite this site\'s settings. Change this site\'s languages from its own TranslatePress settings.', [ 'status' => 409 ] );
		}
		$codes = self::language_codes();
		if ( in_array( $code, $codes, true ) ) {
			return [ 'language' => $code, 'added' => false ] + (array) self::languages();
		}

		$settings                          = self::settings();
		$settings['default-language']      = self::default_language();
		$settings['translation-languages'] = array_merge( $codes, [ $code ] );
		$settings['publish-languages']     = $publish ? array_merge( self::published_codes(), [ $code ] ) : self::published_codes();
		$slugs                             = (array) ( $settings['url-slugs'] ?? [] );
		if ( empty( $slugs[ $code ] ) ) {
			$component = self::languages_component();
			$isos      = $component && method_exists( $component, 'get_iso_codes' ) ? (array) $component->get_iso_codes( [ $code ], false ) : [];
			$iso       = (string) ( $isos[ $code ] ?? '' );
			$slug      = sanitize_title( strtolower( '' !== $iso ? $iso : $code ) );
			// Two languages can share an ISO code (fr_FR, fr_CA): TranslatePress would reset every slug to its full code.
			$slugs[ $code ] = in_array( $slug, $slugs, true ) ? sanitize_title( strtolower( $code ) ) : $slug;
		}
		$settings['url-slugs'] = $slugs;

		// The sanitizer is registered on admin_init, which API and MCP requests never reach.
		$component = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' );
		if ( is_object( $component ) && method_exists( $component, 'register_setting' ) && ! has_filter( 'sanitize_option_trp_settings' ) ) {
			$component->register_setting();
		}
		update_option( 'trp_settings', $settings );

		if ( ! in_array( $code, self::language_codes(), true ) ) {
			return new WP_Error( 'closehub_language_add_failed', 'TranslatePress did not keep the language. Its settings sanitizer rejected it or the license does not allow another language.', [ 'status' => 500 ] );
		}
		return [ 'language' => $code, 'added' => true, 'published' => in_array( $code, self::published_codes(), true ) ] + (array) self::languages();
	}

	/** TranslatePress keeps its translations per default-to-target language pair, so changing the default would orphan every translation. */
	public static function set_default_language( string $code ): array|WP_Error {
		if ( ! self::active() ) {
			return new WP_Error( 'closehub_translatepress_unavailable', 'TranslatePress must be active to manage languages.', [ 'status' => 400 ] );
		}
		$code = sanitize_text_field( $code );
		if ( '' === $code || ! in_array( $code, self::language_codes(), true ) ) {
			return new WP_Error( 'closehub_invalid_language', 'The language must be a language TranslatePress already translates. Add it first with add-language.', [ 'status' => 400 ] );
		}
		$default = self::default_language();
		if ( $default === $code ) {
			return [ 'language' => $code, 'previous_default' => $default, 'changed' => false ];
		}
		return new WP_Error( 'closehub_translatepress_default_unsupported', 'TranslatePress stores its translations per default and target language pair, so changing the default language would orphan every existing translation. Change it from the TranslatePress settings if you really need to.', [ 'status' => 400 ] );
	}

	/**
	 * The URL a published post has in each published language, as { language, language_urls }.
	 * "language" is the language the content is written in, the default one. Only published posts get URLs.
	 */
	public static function details( int $post_id ): array {
		if ( ! self::active() ) {
			return [];
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [];
		}
		$details = [ 'language' => self::default_language() ];
		$urls    = 'publish' === $post->post_status ? self::language_urls( (string) get_permalink( $post ) ) : [];
		return $urls ? $details + [ 'language_urls' => $urls ] : $details;
	}

	/**
	 * TranslatePress serves a language under /{slug}/ after the home URL. The default language has no prefix
	 * unless "add subdirectory to default language" is on.
	 *
	 * @return array<string, string> language code => URL
	 */
	private static function language_urls( string $permalink ): array {
		$home = home_url( '/' );
		if ( '' === $permalink || 0 !== strpos( $permalink, $home ) ) {
			return [];
		}
		$rest      = substr( $permalink, strlen( $home ) );
		$default   = self::default_language();
		$prefix_it = 'yes' === ( self::settings()['add-subdirectory-to-default-language'] ?? 'no' );
		$urls      = [];
		foreach ( self::published_codes() as $code ) {
			$urls[ $code ] = $code === $default && ! $prefix_it ? $permalink : $home . self::slug( $code ) . '/' . $rest;
		}
		return $urls;
	}

	// ── String translations ────────────────────────────────────────────────────
	//
	// TranslatePress splits rendered HTML into strings (a paragraph with bold text becomes several strings) and keeps
	// them in trp_original_strings, one translation row per language in trp_dictionary_{default}_{language}. Strings
	// rendered inside a post's content are linked to it in trp_original_meta (post_parent_id), so that is how a post's
	// strings are found. They only exist once the post has been rendered in a translated language.

	public const STATUS_LABELS = [ 0 => 'not_translated', 1 => 'machine_translated', 2 => 'human_reviewed', 3 => 'similar_translated', 4 => 'translated_in_language_file' ];

	/** Who may read and write translations: the same capability TranslatePress' own editor requires. */
	public static function can_translate(): bool {
		return current_user_can( (string) apply_filters( 'trp_translating_capability', 'manage_options' ) );
	}

	/** @return object|WP_Error TranslatePress' query component, usable on the current site. */
	private static function query(): mixed {
		if ( ! self::active() ) {
			return new WP_Error( 'closehub_translatepress_unavailable', 'TranslatePress must be active to translate strings.', [ 'status' => 400 ] );
		}
		// The query component builds table names from the settings loaded for the site the request started on.
		if ( function_exists( 'ms_is_switched' ) && ms_is_switched() ) {
			return new WP_Error( 'closehub_translatepress_other_site', 'TranslatePress has loaded the settings of another site of this network in this request, so its translation tables cannot be used here.', [ 'status' => 409 ] );
		}
		$query = TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
		if ( ! is_object( $query ) || ! method_exists( $query, 'get_string_rows' ) || ! method_exists( $query, 'update_strings' ) ) {
			return new WP_Error( 'closehub_translatepress_unsupported_version', 'This TranslatePress version does not expose the translation API CloseHub needs.', [ 'status' => 500 ] );
		}
		return $query;
	}

	/**
	 * @param string[] $requested Language codes asked for; empty means every translation language.
	 * @return string[]|WP_Error  Translation languages (never the default one).
	 */
	private static function target_languages( array $requested ): array|WP_Error {
		$all = array_values( array_diff( self::language_codes(), [ self::default_language() ] ) );
		if ( ! $requested ) {
			return $all;
		}
		$requested = array_values( array_unique( array_map( 'strval', $requested ) ) );
		$invalid   = array_values( array_diff( $requested, $all ) );
		if ( $invalid ) {
			return new WP_Error( 'closehub_invalid_language', sprintf( 'Not a TranslatePress translation language: %s. Use list-languages for the codes (the default language has nothing to translate).', implode( ', ', $invalid ) ), [ 'status' => 400 ] );
		}
		return $requested;
	}

	/**
	 * Request the post in every translation language so TranslatePress registers its strings, like a first visit does.
	 * The query argument skips page caches, which would otherwise serve HTML without running TranslatePress.
	 *
	 * @return array<string, int|string> language => HTTP status or error message
	 */
	private static function render_post( int $post_id, array $languages ): array {
		$urls   = self::details( $post_id )['language_urls'] ?? [];
		$result = [];
		foreach ( $languages as $code ) {
			if ( empty( $urls[ $code ] ) ) {
				$result[ $code ] = 'no public URL (only published posts can be rendered)';
				continue;
			}
			$response        = wp_remote_get( add_query_arg( 'closehub_trp_render', (string) time(), $urls[ $code ] ), [ 'timeout' => 60, 'sslverify' => false, 'redirection' => 3 ] );
			$result[ $code ] = is_wp_error( $response ) ? $response->get_error_message() : (int) wp_remote_retrieve_response_code( $response );
		}
		return $result;
	}

	/**
	 * Original strings of a post (via post_parent_id) and/or matching a search, with their translation per language.
	 *
	 * @param array{post_id?:int, search?:string, languages?:string[], only_missing?:bool, render?:bool, page?:int, per_page?:int} $args
	 */
	public static function strings( array $args ): array|WP_Error {
		$query = self::query();
		if ( is_wp_error( $query ) ) {
			return $query;
		}
		$post_id = absint( $args['post_id'] ?? 0 );
		$search  = trim( (string) ( $args['search'] ?? '' ) );
		if ( ! $post_id && '' === $search ) {
			return new WP_Error( 'closehub_strings_scope_required', 'Pass post_id, search, or both.', [ 'status' => 400 ] );
		}
		if ( $post_id && ! get_post( $post_id ) ) {
			return new WP_Error( 'closehub_post_not_found', 'Post not found.', [ 'status' => 404 ] );
		}
		$languages = self::target_languages( (array) ( $args['languages'] ?? [] ) );
		if ( is_wp_error( $languages ) ) {
			return $languages;
		}
		$rendered = $post_id && ! empty( $args['render'] ) ? self::render_post( $post_id, $languages ) : null;

		global $wpdb;
		$originals_table = $query->get_table_name_for_original_strings();
		$where           = [];
		$params          = [];
		if ( $post_id ) {
			$where[]  = "o.id IN ( SELECT m.original_id FROM {$query->get_table_name_for_original_meta()} m WHERE m.meta_key = 'post_parent_id' AND m.meta_value = %s )";
			$params[] = (string) $post_id;
		}
		if ( '' !== $search ) {
			$where[]  = 'o.original LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT o.id, o.original FROM {$originals_table} o WHERE " . implode( ' AND ', $where ) . ' ORDER BY o.id', $params ) );

		$by_language = [];
		$ids         = array_map( static fn( $row ): int => (int) $row->id, $rows );
		foreach ( $languages as $code ) {
			$by_language[ $code ] = $ids ? (array) $query->get_string_rows( $ids, [], $code, OBJECT_K, true ) : [];
		}

		$strings = [];
		foreach ( $rows as $row ) {
			$id           = (int) $row->id;
			$translations = [];
			$missing      = false;
			foreach ( $languages as $code ) {
				$dictionary = $by_language[ $code ][ $id ] ?? null;
				$status     = $dictionary ? (int) $dictionary->status : 0;
				$translated = $dictionary ? (string) $dictionary->translated : '';
				$missing    = $missing || '' === $translated || 0 === $status;
				$translations[ $code ] = [ 'translated' => $translated, 'status' => self::STATUS_LABELS[ $status ] ?? (string) $status ];
			}
			if ( ! empty( $args['only_missing'] ) && ! $missing ) {
				continue;
			}
			$strings[] = [ 'original_id' => $id, 'original' => (string) $row->original, 'translations' => $translations ];
		}

		$per_page = max( 1, min( 500, absint( $args['per_page'] ?? 200 ) ) );
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$result   = [
			'default_language' => self::default_language(),
			'languages'        => $languages,
			'total'            => count( $strings ),
			'page'             => $page,
			'total_pages'      => (int) ceil( count( $strings ) / $per_page ),
			'strings'          => array_slice( $strings, ( $page - 1 ) * $per_page, $per_page ),
		];
		if ( null !== $rendered ) {
			$result['rendered'] = $rendered;
		}
		if ( $post_id && ! $rows ) {
			$result['notice'] = 'TranslatePress has not registered any string of this post yet. Pass render=true to render it in every translation language first.';
		}
		return $result;
	}

	/**
	 * Save translations for one language as human reviewed, like TranslatePress' editor does. Each item names its
	 * original by original_id (from list-string-translations) or by its exact original text. A string that has no
	 * row in that language yet is created first.
	 *
	 * @param array<int, array{original_id?:int, original?:string, translated?:string}> $items
	 */
	public static function save_strings( string $language, array $items ): array|WP_Error {
		$query = self::query();
		if ( is_wp_error( $query ) ) {
			return $query;
		}
		$languages = self::target_languages( [ $language ] );
		if ( is_wp_error( $languages ) ) {
			return $languages;
		}
		if ( ! $items ) {
			return new WP_Error( 'closehub_translations_required', 'translations must contain at least one item.', [ 'status' => 400 ] );
		}
		if ( count( $items ) > 500 ) {
			return new WP_Error( 'closehub_too_many_translations', 'Save at most 500 translations per call.', [ 'status' => 400 ] );
		}

		global $wpdb;
		$originals_table = $query->get_table_name_for_original_strings();
		$ids_needed      = array_values( array_unique( array_filter( array_map( static fn( $item ): int => absint( $item['original_id'] ?? 0 ), $items ) ) ) );
		$text_by_id      = [];
		if ( $ids_needed ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids_needed ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, original FROM {$originals_table} WHERE id IN ($placeholders)", $ids_needed ) ) as $row ) {
				$text_by_id[ (int) $row->id ] = (string) $row->original;
			}
		}

		$wanted    = []; // original text => translated
		$not_found = [];
		$empty     = [];
		foreach ( $items as $index => $item ) {
			$original_id = absint( $item['original_id'] ?? 0 );
			$original    = $original_id ? ( $text_by_id[ $original_id ] ?? null ) : ( isset( $item['original'] ) ? (string) $item['original'] : null );
			$translated  = trp_sanitize_string( (string) ( $item['translated'] ?? '' ) );
			if ( null === $original || '' === $original ) {
				$not_found[] = $original_id ? [ 'index' => $index, 'original_id' => $original_id ] : [ 'index' => $index, 'original' => (string) ( $item['original'] ?? '' ) ];
				continue;
			}
			if ( '' === trim( $translated ) ) {
				$empty[] = $index;
				continue;
			}
			$wanted[ $original ] = $translated;
		}

		$created = 0;
		$saved   = 0;
		if ( $wanted ) {
			$rows    = (array) $query->get_string_rows( [], array_keys( $wanted ), $language );
			$known   = [];
			foreach ( $rows as $row ) {
				$known[ (string) $row->original ] = $row;
			}
			$missing = array_values( array_diff( array_keys( $wanted ), array_keys( $known ) ) );
			if ( $missing ) {
				$query->insert_strings( $missing, $language );
				$created = count( $missing );
				foreach ( (array) $query->get_string_rows( [], $missing, $language ) as $row ) {
					$known[ (string) $row->original ] = $row;
				}
			}
			$update = [];
			foreach ( $wanted as $original => $translated ) {
				$row = $known[ $original ] ?? null;
				if ( ! $row ) {
					$not_found[] = [ 'original' => $original ];
					continue;
				}
				$update[] = [ 'id' => (int) $row->id, 'original' => $original, 'translated' => $translated, 'status' => 2, 'block_type' => (int) $row->block_type ];
			}
			if ( $update ) {
				$query->update_strings( $update, $language, [ 'id', 'translated', 'status', 'block_type' ] );
				if ( method_exists( $query, 'remove_possible_duplicates' ) ) {
					$query->remove_possible_duplicates( $update, $language, 'regular' );
				}
				do_action( 'trp_save_editor_translations_regular_strings', [ $language => $update ], get_option( 'trp_settings', [] ) );
			}
			$saved = count( $update );
		}

		return [
			'language'  => $language,
			'saved'     => $saved,
			'created'   => $created,
			'not_found' => $not_found,
			'skipped_empty' => $empty,
		];
	}
}
