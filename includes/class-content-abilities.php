<?php

defined( 'ABSPATH' ) || exit;

class CloseHub_Content_Abilities {
	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ self::class, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ self::class, 'register_abilities' ] );
	}

	public static function register_category(): void {
		wp_register_ability_category( 'closehub-content', [
			'label'       => 'CloseHub Content',
			'description' => 'Manage WordPress posts connected to CloseHub.',
		] );

		wp_register_ability_category( 'closehub-commerce', [
			'label'       => 'CloseHub Commerce',
			'description' => 'Read WooCommerce sales data connected to CloseHub.',
		] );
	}

	public static function register_abilities(): void {
		self::ability( 'closehub/list-posts', 'List posts', 'List or search posts, or another post type such as "page" or "product", with status and pagination filters.', [ self::class, 'list_posts' ], [ self::class, 'can_edit_posts' ], true, true, [
			'status' => [ 'type' => 'string' ], 'search' => [ 'type' => 'string' ], 'page' => [ 'type' => 'integer', 'default' => 1 ], 'per_page' => [ 'type' => 'integer', 'default' => 20 ], 'post_type' => [ 'type' => 'string', 'default' => 'post' ],
		] );
		self::ability( 'closehub/get-post', 'Get post', 'Get one post, page, product, or other supported content item and its CloseHub-managed metadata.', [ self::class, 'get_post' ], [ self::class, 'can_edit_post' ], true, true, [ 'post_id' => [ 'type' => 'integer' ] ], [ 'post_id' ] );
		self::ability( 'closehub/create-post', 'Create post', 'Create a post as a draft unless another valid status is supplied. Pass post_type to create a page, product, or other registered content type instead of a post.', [ self::class, 'create_post' ], [ self::class, 'can_create_post' ], false, false, self::post_fields( true ), [ 'title', 'content' ] );
		self::ability( 'closehub/update-post', 'Update post', 'Update an existing post, page, product, or other supported content item and its CloseHub metadata.', [ self::class, 'update_post' ], [ self::class, 'can_edit_post' ], false, false, self::post_fields( false ), [ 'post_id' ] );
		self::ability( 'closehub/update-post-slug', 'Update post slug', 'Update the URL slug of one post, page, product, or other supported content item. WordPress normalizes the slug and adds a suffix when needed to keep it unique.', [ self::class, 'update_post_slug' ], [ self::class, 'can_edit_post' ], false, true, [
			'post_id' => [ 'type' => 'integer' ],
			'slug'    => [ 'type' => 'string' ],
		], [ 'post_id', 'slug' ], true );
		self::ability( 'closehub/replace-gutenberg-block', 'Replace Gutenberg block', 'Replace one Gutenberg block at an exact block path after confirming the post content version and existing block type.', [ self::class, 'replace_gutenberg_block' ], [ self::class, 'can_edit_post' ], false, true, [
			'post_id'             => [ 'type' => 'integer' ],
			'block_path'          => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
			'expected_block_name' => [ 'type' => 'string' ],
			'expected_content_hash' => [ 'type' => 'string' ],
			'block'               => [ 'type' => 'string' ],
			], [ 'post_id', 'block_path', 'expected_block_name', 'expected_content_hash', 'block' ], true );
		self::ability( 'closehub/trash-post', 'Trash post', 'Send an existing post, page, product, or other supported content item to the WordPress trash without permanently deleting it.', [ self::class, 'trash_post' ], [ self::class, 'can_delete_post' ], false, true, [ 'post_id' => [ 'type' => 'integer' ] ], [ 'post_id' ], true );
		self::ability( 'closehub/get-order-summary', 'Get WooCommerce order summary', 'Get order count, total sales, average order value, and orders for a date range.', [ self::class, 'get_order_summary' ], [ self::class, 'can_manage_woocommerce' ], true, true, [ 'after' => [ 'type' => 'string' ], 'before' => [ 'type' => 'string' ], 'status' => [ 'type' => 'string', 'default' => 'completed,processing' ] ], [ 'after', 'before' ], false, 'closehub-commerce' );
	}

	private static function ability( string $id, string $label, string $description, array $execute, array $permission, bool $readonly, bool $idempotent, array $properties, array $required = [], bool $destructive = false, string $category = 'closehub-content' ): void {
		wp_register_ability( $id, [
			'label' => $label, 'description' => $description, 'category' => $category,
			'input_schema' => [ 'type' => 'object', 'properties' => $properties, 'required' => $required ],
			'execute_callback' => $execute, 'permission_callback' => $permission,
			'meta' => [ 'show_in_rest' => true, 'mcp' => [ 'public' => true ], 'annotations' => [ 'readonly' => $readonly, 'destructive' => $destructive, 'idempotent' => $idempotent ] ],
		] );
	}

	private static function post_fields( bool $creating ): array {
		$fields = [ 'post_id' => [ 'type' => 'integer' ], 'title' => [ 'type' => 'string' ], 'content' => [ 'type' => 'string' ], 'excerpt' => [ 'type' => 'string' ], 'status' => [ 'type' => 'string', 'enum' => [ 'draft', 'publish', 'pending' ] ], 'categories' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ], 'featured_image_url' => [ 'type' => 'string' ], 'seo_title' => [ 'type' => 'string' ], 'seo_description' => [ 'type' => 'string' ], 'seo_focus_keyword' => [ 'type' => 'string' ] ];
		if ( $creating ) { $fields['status']['default'] = 'draft'; $fields['post_type'] = [ 'type' => 'string', 'default' => 'post' ]; }
		return $fields;
	}

	public static function can_edit_posts( $input ): bool { return self::type_cap( $input['post_type'] ?? 'post', 'edit_posts' ); }
	public static function can_create_post( $input ): bool { return self::type_cap( $input['post_type'] ?? 'post', 'create_posts' ) && ( 'publish' !== ( $input['status'] ?? 'draft' ) || self::type_cap( $input['post_type'] ?? 'post', 'publish_posts' ) ); }
	public static function can_edit_post( $input ): bool {
		$post_id = absint( $input['post_id'] ?? 0 );
		return current_user_can( 'edit_post', $post_id ) && ( 'publish' !== ( $input['status'] ?? '' ) || self::type_cap( get_post_type( $post_id ) ?: 'post', 'publish_posts' ) );
	}
	public static function can_delete_post( $input ): bool { return current_user_can( 'delete_post', absint( $input['post_id'] ?? 0 ) ); }
	public static function can_manage_woocommerce(): bool { return current_user_can( 'manage_woocommerce' ); }

	/** Check a post type's own meta capability (e.g. 'publish_products' for a product) instead of assuming 'post'. */
	private static function type_cap( string $post_type, string $cap ): bool {
		$post_type_object = get_post_type_object( $post_type );
		return null !== $post_type_object && current_user_can( $post_type_object->cap->{$cap} ?? $cap );
	}

	public static function list_posts( $input ): array|WP_Error {
		$input = is_array( $input ) ? $input : [];
		$post_type = sanitize_key( $input['post_type'] ?? 'post' );
		if ( ! CloseHub_REST_API::post_type_allowed( $post_type ) ) {
			return new WP_Error( 'closehub_post_type_not_allowed', sprintf( 'The "%s" post type is not available.', $post_type ), [ 'status' => 400 ] );
		}
		$page = max( 1, absint( $input['page'] ?? 1 ) );
		$query = new WP_Query( [ 'post_type' => $post_type, 'post_status' => $input['status'] ?? 'publish', 'perm' => 'readable', 's' => sanitize_text_field( $input['search'] ?? '' ), 'paged' => $page, 'posts_per_page' => min( 100, max( 1, absint( $input['per_page'] ?? 20 ) ) ) ] );
		return [ 'posts' => array_map( [ self::class, 'post_data' ], $query->posts ), 'page' => $page, 'total' => (int) $query->found_posts, 'total_pages' => (int) $query->max_num_pages ];
	}

	public static function get_post( $input ): array|WP_Error {
		$post = get_post( absint( $input['post_id'] ?? 0 ) );
		return $post && CloseHub_REST_API::post_type_allowed( $post->post_type ) && ! post_password_required( $post ) ? self::post_data( $post, true ) : new WP_Error( 'closehub_post_not_found', 'Post not found.', [ 'status' => 404 ] );
	}

	public static function create_post( $input ): array|WP_Error {
		$input = is_array( $input ) ? $input : [];
		$post_type = sanitize_key( $input['post_type'] ?? 'post' );
		if ( ! CloseHub_REST_API::post_type_allowed( $post_type ) ) {
			return new WP_Error( 'closehub_post_type_not_allowed', sprintf( 'The "%s" post type is not available.', $post_type ), [ 'status' => 400 ] );
		}
		$forbidden = self::forbidden_new_categories( $input['categories'] ?? [], $post_type );
		if ( $forbidden ) { return $forbidden; }
		$request = self::request( 'POST', '/closehub/v1/posts', $input );
		$request->set_param( 'post_type', $post_type );
		if ( ! $request->get_param( 'status' ) ) { $request->set_param( 'status', 'draft' ); }
		$permission_input = $input;
		$permission_input['post_type'] = $post_type;
		$permission_input['status']    = $request->get_param( 'status' );
		return ( new CloseHub_REST_API() )->create_post_for_mcp( $request, fn() => self::can_create_post( $permission_input ) );
	}

	public static function update_post( $input ): array|WP_Error {
		$input = is_array( $input ) ? $input : [];
		$post_id = absint( $input['post_id'] ?? 0 );
		$post = get_post( $post_id );
		if ( ! $post || ! CloseHub_REST_API::post_type_allowed( $post->post_type ) ) {
			return new WP_Error( 'closehub_post_not_found', 'Post not found.', [ 'status' => 404 ] );
		}
		$forbidden = self::forbidden_new_categories( $input['categories'] ?? [], $post->post_type );
		if ( $forbidden ) { return $forbidden; }
		$request = self::request( 'PUT', '/closehub/v1/posts/' . $post_id, $input );
		$request->set_param( 'id', $post_id );
		return ( new CloseHub_REST_API() )->update_post_for_mcp( $request, fn() => self::can_edit_post( $input ) );
	}

	/** Update only a post's permalink slug through WordPress's normal unique-slug handling. */
	public static function update_post_slug( $input ): array|WP_Error {
		$input   = is_array( $input ) ? $input : [];
		$post_id = absint( $input['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post || ! CloseHub_REST_API::post_type_allowed( $post->post_type ) ) {
			return new WP_Error( 'closehub_post_not_found', 'Post not found.', [ 'status' => 404 ] );
		}

		$slug = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		if ( '' === $slug ) {
			return new WP_Error( 'closehub_invalid_post_slug', 'slug must normalize to a non-empty URL slug.', [ 'status' => 400 ] );
		}

		$updated = wp_update_post( [ 'ID' => $post_id, 'post_name' => $slug ], true );
		if ( $updated instanceof WP_Error ) {
			return $updated;
		}
		$post = get_post( $post_id );
		return [ 'post_id' => $post_id, 'slug' => $post->post_name, 'url' => get_permalink( $post_id ) ];
	}

	/**
	 * Replace a single parsed block, rather than asking a client to rewrite an
	 * entire post_content string. block_path is a zero-based path through each
	 * block's innerBlocks array, e.g. [ 2, 0 ] selects the first child of the
	 * third top-level block.
	 */
	public static function replace_gutenberg_block( $input ): array|WP_Error {
		$input   = is_array( $input ) ? $input : [];
		$post_id = absint( $input['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post || ! CloseHub_REST_API::post_type_allowed( $post->post_type ) ) {
			return new WP_Error( 'closehub_post_not_found', 'Post not found.', [ 'status' => 404 ] );
		}

		$expected_hash = (string) ( $input['expected_content_hash'] ?? '' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_hash ) ) {
			return new WP_Error( 'closehub_content_changed', 'Post content has changed. Get the post again before replacing a block.', [ 'status' => 409 ] );
		}

		$path = $input['block_path'] ?? [];
		if ( ! is_array( $path ) || [] === $path ) {
			return new WP_Error( 'closehub_invalid_block_path', 'block_path must identify one block.', [ 'status' => 400 ] );
		}
		foreach ( $path as $index ) {
			if ( ! is_int( $index ) && ! ctype_digit( (string) $index ) || (int) $index < 0 ) {
				return new WP_Error( 'closehub_invalid_block_path', 'block_path must contain non-negative indexes.', [ 'status' => 400 ] );
			}
		}
		$path = array_map( 'intval', array_values( $path ) );

		$replacement = parse_blocks( trim( (string) ( $input['block'] ?? '' ) ) );
		if ( 1 !== count( $replacement ) || empty( $replacement[0]['blockName'] ) ) {
			return new WP_Error( 'closehub_invalid_gutenberg_block', 'block must contain exactly one valid Gutenberg block.', [ 'status' => 400 ] );
		}
		$expected_name = self::canonical_block_name( sanitize_text_field( (string) ( $input['expected_block_name'] ?? '' ) ) );
		if ( '' === $expected_name || $expected_name !== $replacement[0]['blockName'] ) {
			return new WP_Error( 'closehub_block_type_mismatch', 'The replacement block must have the expected Gutenberg block type.', [ 'status' => 400 ] );
		}

		if ( ! hash_equals( hash( 'sha256', $post->post_content ), $expected_hash ) ) {
			return new WP_Error( 'closehub_content_changed', 'Post content has changed. Get the post again before replacing a block.', [ 'status' => 409 ] );
		}
		$blocks = self::replace_block_at_path( parse_blocks( $post->post_content ), $path, $replacement[0], $expected_name );
		if ( $blocks instanceof WP_Error ) {
			return $blocks;
		}
		$updated = self::replace_post_content_if_current( $post, $expected_hash, serialize_blocks( $blocks ) );
		if ( $updated instanceof WP_Error ) {
			return $updated;
		}

		return [ 'post_id' => $post_id, 'block_path' => $path, 'block_name' => $expected_name, 'content_hash' => $updated ];
	}

	private static function canonical_block_name( string $block_name ): string {
		return '' === $block_name || str_contains( $block_name, '/' ) ? $block_name : 'core/' . $block_name;
	}
	/**
	 * Lock the row before checking its raw bytes, then use WordPress's normal
	 * update lifecycle while the transaction prevents any other content writer
	 * from changing it. A PHP-level lock cannot coordinate Gutenberg, REST, or
	 * third-party writers that do not opt into it.
	 */
	private static function replace_post_content_if_current( WP_Post $post, string $expected_hash, string $content ): string|WP_Error {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'closehub_post_update_failed', 'Post could not be updated.', [ 'status' => 500 ] );
		}
		try {
			$current_content = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $post->ID ) );
			if ( ! is_string( $current_content ) || ! hash_equals( hash( 'sha256', $current_content ), $expected_hash ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'closehub_content_changed', 'Post content has changed. Get the post again before replacing a block.', [ 'status' => 409 ] );
			}

			$updated = wp_update_post( [ 'ID' => $post->ID, 'post_content' => wp_slash( $content ) ], true );
			if ( $updated instanceof WP_Error ) {
				$wpdb->query( 'ROLLBACK' );
				return $updated;
			}
			$saved = get_post( $post->ID );
			if ( ! $saved || false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'closehub_post_update_failed', 'Post could not be updated.', [ 'status' => 500 ] );
			}
			return hash( 'sha256', $saved->post_content );
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/** @return array<array<string,mixed>|WP_Error>|WP_Error */
	private static function replace_block_at_path( array $blocks, array $path, array $replacement, string $expected_name ): array|WP_Error {
		$index = array_shift( $path );
		if ( ! isset( $blocks[ $index ] ) ) {
			return new WP_Error( 'closehub_block_not_found', 'No Gutenberg block exists at block_path.', [ 'status' => 404 ] );
		}
		if ( [] === $path ) {
			if ( $expected_name !== ( $blocks[ $index ]['blockName'] ?? '' ) ) {
				return new WP_Error( 'closehub_block_type_mismatch', 'The block at block_path is not the expected Gutenberg block type.', [ 'status' => 409 ] );
			}
			$blocks[ $index ] = $replacement;
			return $blocks;
		}

		$children = self::replace_block_at_path( $blocks[ $index ]['innerBlocks'] ?? [], $path, $replacement, $expected_name );
		if ( $children instanceof WP_Error ) {
			return $children;
		}
		$blocks[ $index ]['innerBlocks'] = $children;
		return $blocks;
	}

	public static function trash_post( $input ): array|WP_Error {
		$post_id = absint( $input['post_id'] ?? 0 );
		$post = get_post( $post_id );
		if ( ! $post || ! CloseHub_REST_API::post_type_allowed( $post->post_type ) ) { return new WP_Error( 'closehub_post_not_found', 'Post not found.', [ 'status' => 404 ] ); }
		if ( defined( 'EMPTY_TRASH_DAYS' ) && 0 === (int) EMPTY_TRASH_DAYS ) { return new WP_Error( 'closehub_trash_disabled', 'WordPress trash is disabled.', [ 'status' => 409 ] ); }
		if ( ! wp_trash_post( $post_id ) ) { return new WP_Error( 'closehub_post_trash_failed', 'Post could not be moved to trash.', [ 'status' => 500 ] ); }
		return [ 'post_id' => $post_id, 'status' => 'trash' ];
	}

	public static function get_order_summary( $input ): array|WP_Error {
		$request = self::request( 'GET', '/closehub/v1/woocommerce/orders', $input );
		if ( ! $request->get_param( 'status' ) ) { $request->set_param( 'status', 'completed,processing' ); }
		return ( new CloseHub_REST_API() )->get_woocommerce_orders_for_mcp( $request );
	}

	private static function request( string $method, string $route, $input ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route );
		foreach ( is_array( $input ) ? $input : [] as $key => $value ) {
			$request->set_param( (string) $key, $value );
		}
		return $request;
	}

	/**
	 * CloseHub_REST_API::save_post_metadata() creates any category name that
	 * doesn't already exist without its own capability check — correct for
	 * the plain API-key-authenticated /posts route, which runs with no
	 * current WordPress user and where the key itself is the authorization.
	 * An MCP ability call is authenticated as a real WordPress user, though,
	 * so it must not let a caller without manage_categories create new
	 * taxonomy terms just by naming one that doesn't exist yet.
	 */
	private static function forbidden_new_categories( $categories, string $post_type = 'post' ): ?WP_Error {
		$categories = (array) $categories;
		if ( ! $categories ) { return null; }
		if ( ! is_object_in_taxonomy( $post_type, 'category' ) ) {
			return new WP_Error( 'closehub_categories_not_supported', sprintf( 'The "%s" post type does not support categories.', $post_type ), [ 'status' => 400 ] );
		}
		if ( current_user_can( 'manage_categories' ) ) { return null; }
		foreach ( $categories as $category ) {
			if ( ! term_exists( sanitize_text_field( (string) $category ), 'category' ) ) {
				return new WP_Error( 'closehub_category_forbidden', 'You cannot create categories.', [ 'status' => 403 ] );
			}
		}
		return null;
	}

	private static function post_data( WP_Post $post, bool $full = false ): array {
		$data = [ 'post_id' => $post->ID, 'post_type' => $post->post_type, 'title' => $post->post_title, 'status' => $post->post_status, 'url' => get_permalink( $post ), 'edit_url' => get_edit_post_link( $post->ID, 'raw' ), 'date' => $post->post_date ];
		if ( $full ) {
			$categories = is_object_in_taxonomy( $post->post_type, 'category' ) ? wp_get_post_categories( $post->ID, [ 'fields' => 'names' ] ) : [];
			$data += [ 'content' => $post->post_content, 'content_hash' => hash( 'sha256', $post->post_content ), 'excerpt' => $post->post_excerpt, 'categories' => $categories ] + CloseHub_REST_API::cms_metadata( $post->ID );
		}
		return $data;
	}
}
