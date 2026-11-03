<?php

defined( 'ABSPATH' ) || exit;

/**
 * MCP abilities to manage WPML languages and duplicate content between them.
 * Linking existing posts as translations lives in CloseHub_Content_Abilities
 * (link-translations / unlink-translation); creating a brand-new translation
 * is create-post with `language` (+ `translation_of` to link it).
 */
class CloseHub_Language_Abilities {
	private const MCP_TOOL_ABILITIES = [
		'closehub/list-languages',
		'closehub/add-language',
		'closehub/set-default-language',
		'closehub/duplicate-post',
	];

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_init', [ self::class, 'register_abilities' ] );
		add_filter( 'mcp_adapter_default_server_config', [ self::class, 'add_tools_to_default_server' ] );
	}

	public static function add_tools_to_default_server( array $config ): array {
		$config['tools'] = array_values( array_unique( array_merge( $config['tools'] ?? [], self::MCP_TOOL_ABILITIES ) ) );
		return $config;
	}

	public static function register_abilities(): void {
		self::ability( 'closehub/list-languages', 'List languages', 'List the active WPML languages and which one is the default. Set include_available to also list the supported languages that can still be added with add-language.', [ self::class, 'list_languages' ], [ self::class, 'can_list_languages' ], true, true, [
			'include_available' => [ 'type' => 'boolean', 'default' => false ],
		] );
		self::ability( 'closehub/add-language', 'Add language', 'Activate a language supported by WPML (see list-languages with include_available). Existing content is not changed.', [ self::class, 'add_language' ], [ self::class, 'can_manage_languages' ], false, true, [
			'language' => [ 'type' => 'string', 'description' => 'WPML language code, e.g. "fr".' ],
		], [ 'language' ] );
		self::ability( 'closehub/set-default-language', 'Set default language', 'Make an already active WPML language the site default. Changing it affects URLs and language fallbacks of the whole site, so confirm with the user first.', [ self::class, 'set_default_language' ], [ self::class, 'can_manage_languages' ], false, true, [
			'language' => [ 'type' => 'string', 'description' => 'Active WPML language code.' ],
		], [ 'language' ], true );
		self::ability( 'closehub/duplicate-post', 'Duplicate post to another language', 'Copy an existing original post (or page, product, etc.) into one or more other languages as new drafts. By default each copy is linked to the original as its translation; pass link=false to create independent copies that are not linked. To write a translation from scratch use create-post with language and translation_of.', [ self::class, 'duplicate_post' ], [ self::class, 'can_duplicate_post' ], false, false, [
			'post_id'     => [ 'type' => 'integer', 'description' => 'Existing original post to copy.' ],
			'languages'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Target WPML language codes.' ],
			'link'        => [ 'type' => 'boolean', 'default' => true, 'description' => 'Link each copy to the original as its translation. false keeps it unlinked.' ],
			'clear_cache' => [ 'type' => 'boolean', 'default' => true ],
		], [ 'post_id', 'languages' ] );
	}

	private static function ability( string $id, string $label, string $description, array $execute, array $permission, bool $readonly, bool $idempotent, array $properties, array $required = [], bool $destructive = false ): void {
		wp_register_ability( $id, [
			'label' => $label, 'description' => CloseHub_Network::description( $id, $description ), 'category' => 'closehub-site',
			'input_schema' => [ 'type' => 'object', 'properties' => CloseHub_Network::input_properties( $id, $properties ), 'required' => CloseHub_Network::required( $id, $required ) ],
			'execute_callback' => CloseHub_Network::execute( $id, $execute, $permission ), 'permission_callback' => CloseHub_Network::permission( $id, $permission ),
			'meta' => [ 'show_in_rest' => true, 'mcp' => [ 'public' => true ], 'annotations' => [ 'readonly' => $readonly, 'destructive' => $destructive, 'idempotent' => $idempotent ] ],
		] );
	}

	public static function can_list_languages(): bool { return current_user_can( 'edit_posts' ); }
	public static function can_manage_languages(): bool { return current_user_can( 'manage_options' ); }
	public static function can_duplicate_post( $input ): bool {
		$post = get_post( absint( $input['post_id'] ?? 0 ) );
		$type = $post ? get_post_type_object( $post->post_type ) : null;
		return $post && $type && current_user_can( 'edit_post', $post->ID ) && current_user_can( $type->cap->create_posts ?? 'edit_posts' );
	}

	public static function list_languages( $input = null ): array|WP_Error {
		return CloseHub_WPML::languages( ! empty( $input['include_available'] ) );
	}

	public static function add_language( $input ): array|WP_Error {
		return CloseHub_WPML::add_language( (string) ( $input['language'] ?? '' ) );
	}

	public static function set_default_language( $input ): array|WP_Error {
		return CloseHub_WPML::set_default_language( (string) ( $input['language'] ?? '' ) );
	}

	public static function duplicate_post( $input ): array|WP_Error {
		$input   = is_array( $input ) ? $input : [];
		$post_id = absint( $input['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post || ! CloseHub_REST_API::post_type_allowed( $post->post_type ) ) {
			return new WP_Error( 'closehub_post_not_found', 'Post not found.', [ 'status' => 404 ] );
		}
		$languages = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $input['languages'] ?? [] ) ) ) ) );
		if ( ! $languages ) {
			return new WP_Error( 'closehub_languages_required', 'languages must list at least one target language.', [ 'status' => 400 ] );
		}

		$link     = ! isset( $input['link'] ) || ! empty( $input['link'] );
		$created  = [];
		$errors   = [];
		$affected = [];
		foreach ( $languages as $language ) {
			$result = CloseHub_WPML::duplicate( $post_id, $language, $link );
			if ( is_wp_error( $result ) ) {
				$errors[] = [ 'language' => $language, 'error' => $result->get_error_message() ];
				continue;
			}
			$affected = array_merge( $affected, $result['affected_post_ids'] );
			unset( $result['affected_post_ids'] );
			$created[] = $result;
		}
		if ( ! $created ) {
			return new WP_Error( 'closehub_duplicate_failed', implode( ' ', array_map( static fn( $e ) => $e['language'] . ': ' . $e['error'], $errors ) ), [ 'status' => 400 ] );
		}

		$output = [ 'duplicates' => $created ] + ( $errors ? [ 'errors' => $errors ] : [] );
		if ( $link && ( $input['clear_cache'] ?? true ) ) {
			$output = CloseHub_Content_Abilities::finish_translation_change( $output + [ 'affected_post_ids' => array_values( array_unique( $affected ) ) ], $input );
		}
		return $output;
	}
}
