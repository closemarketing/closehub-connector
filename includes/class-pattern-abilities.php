<?php

defined( 'ABSPATH' ) || exit;

/**
 * MCP abilities to read block patterns (theme, core, plugin and synced) so a client can build landing pages from them.
 *
 * `list-theme-patterns` returns metadata only; `get-theme-pattern` returns the content of one pattern. The client
 * then creates the page with `closehub/create-post`.
 */
class CloseHub_Pattern_Abilities {
	private const MCP_TOOL_ABILITIES = [
		'closehub/list-theme-patterns',
		'closehub/get-theme-pattern',
	];

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_init', [ self::class, 'register_abilities' ] );
		add_filter( 'mcp_adapter_default_server_config', [ self::class, 'add_tools_to_default_server' ] );
	}

	/** Add the pattern abilities as direct tools on the default MCP server. */
	public static function add_tools_to_default_server( array $config ): array {
		$config['tools'] = array_values( array_unique( array_merge( $config['tools'] ?? [], self::MCP_TOOL_ABILITIES ) ) );
		return $config;
	}

	public static function register_abilities(): void {
		self::ability(
			'closehub/list-theme-patterns',
			'List theme patterns',
			'List the block patterns available on the site (active theme, core, plugins and synced patterns) as metadata only: slug, title, description, categories, blockTypes and source (theme, core, plugin or synced). It leaves out the pattern content; fetch the ones you need with get-theme-pattern, adapt the copy, and create the page with create-post as a draft.',
			[ self::class, 'list_patterns' ],
			[
				'category' => [ 'type' => 'string', 'description' => 'Only patterns in this category slug (for example "hero").' ],
				'search'   => [ 'type' => 'string', 'description' => 'Only patterns whose slug, title or description contain this text.' ],
			]
		);
		self::ability(
			'closehub/get-theme-pattern',
			'Get theme pattern',
			'Get the block content of one pattern, by slug (registered patterns) or by id (synced pattern, a wp_block post). Use the slug or id returned by list-theme-patterns.',
			[ self::class, 'get_pattern' ],
			[
				'slug' => [ 'type' => 'string', 'description' => 'Pattern slug, such as "my-theme/hero".' ],
				'id'   => [ 'type' => 'integer', 'description' => 'wp_block post ID of a synced pattern.' ],
			]
		);
	}

	private static function ability( string $id, string $label, string $description, array $execute, array $properties ): void {
		wp_register_ability( $id, [
			'label'               => $label,
			'description'         => CloseHub_Network::description( $id, $description ),
			'category'            => 'closehub-content',
			'input_schema'        => [ 'type' => 'object', 'properties' => CloseHub_Network::input_properties( $id, $properties ), 'required' => CloseHub_Network::required( $id, [] ) ],
			'execute_callback'    => CloseHub_Network::execute( $id, $execute, [ self::class, 'can_read_patterns' ] ),
			'permission_callback' => CloseHub_Network::permission( $id, [ self::class, 'can_read_patterns' ] ),
			'meta'                => [ 'show_in_rest' => true, 'mcp' => [ 'public' => true ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ],
		] );
	}

	public static function can_read_patterns(): bool {
		return current_user_can( 'edit_posts' );
	}

	public static function list_patterns( $input ): array|WP_Error {
		$input    = is_array( $input ) ? $input : [];
		$category = sanitize_key( $input['category'] ?? '' );
		$search   = trim( sanitize_text_field( $input['search'] ?? '' ) );

		$patterns = [];
		foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			$patterns[] = self::registered_data( $pattern );
		}
		foreach ( self::synced_posts() as $post ) {
			$patterns[] = self::synced_data( $post );
		}

		$patterns = array_values( array_filter( $patterns, static function ( array $pattern ) use ( $category, $search ): bool {
			if ( '' !== $category && ! in_array( $category, $pattern['categories'], true ) ) {
				return false;
			}
			if ( '' !== $search ) {
				foreach ( [ $pattern['slug'], $pattern['title'], $pattern['description'] ] as $haystack ) {
					if ( false !== stripos( (string) $haystack, $search ) ) {
						return true;
					}
				}
				return false;
			}
			return true;
		} ) );

		return [ 'patterns' => $patterns, 'total' => count( $patterns ) ];
	}

	public static function get_pattern( $input ): array|WP_Error {
		$input = is_array( $input ) ? $input : [];
		$id    = absint( $input['id'] ?? 0 );
		$slug  = trim( sanitize_text_field( $input['slug'] ?? '' ) );

		if ( $id > 0 ) {
			$post = get_post( $id );
			if ( ! $post || 'wp_block' !== $post->post_type || 'publish' !== $post->post_status ) {
				return new WP_Error( 'closehub_pattern_not_found', 'Pattern not found.', [ 'status' => 404 ] );
			}
			return self::synced_data( $post ) + [ 'content' => (string) $post->post_content ];
		}

		if ( '' === $slug ) {
			return new WP_Error( 'closehub_pattern_missing_identifier', 'Pass slug or id.', [ 'status' => 400 ] );
		}
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( $slug );
		if ( ! is_array( $pattern ) ) {
			return new WP_Error( 'closehub_pattern_not_found', 'Pattern not found.', [ 'status' => 404 ] );
		}
		return self::registered_data( $pattern ) + [ 'content' => (string) ( $pattern['content'] ?? '' ) ];
	}

	/** @return WP_Post[] */
	private static function synced_posts(): array {
		return get_posts( [ 'post_type' => 'wp_block', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC', 'suppress_filters' => false ] );
	}

	/** Metadata of a registered pattern, never its content. */
	private static function registered_data( array $pattern ): array {
		$slug = (string) ( $pattern['name'] ?? '' );
		return [
			'slug'        => $slug,
			'title'       => (string) ( $pattern['title'] ?? '' ),
			'description' => (string) ( $pattern['description'] ?? '' ),
			'categories'  => array_values( array_map( 'strval', (array) ( $pattern['categories'] ?? [] ) ) ),
			'blockTypes'  => array_values( array_map( 'strval', (array) ( $pattern['blockTypes'] ?? [] ) ) ),
			'source'      => self::source( $slug ),
		];
	}

	private static function synced_data( WP_Post $post ): array {
		$categories = wp_get_post_terms( $post->ID, 'wp_pattern_category', [ 'fields' => 'slugs' ] );
		return [
			'id'          => (int) $post->ID,
			'slug'        => (string) $post->post_name,
			'title'       => (string) $post->post_title,
			'description' => (string) $post->post_excerpt,
			'categories'  => is_array( $categories ) ? array_values( $categories ) : [],
			'blockTypes'  => [],
			'source'      => 'synced',
		];
	}

	/** Registered patterns are namespaced "<namespace>/<name>": the namespace tells where they came from. */
	private static function source( string $slug ): string {
		$namespace = strstr( $slug, '/', true );
		if ( 'core' === $namespace ) {
			return 'core';
		}
		if ( in_array( $namespace, [ get_stylesheet(), get_template() ], true ) ) {
			return 'theme';
		}
		return 'plugin';
	}
}
