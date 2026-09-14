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
		self::ability( 'closehub/get-post', 'Get post', 'Get one post, page, product, or other supported content item and its CloseHub-managed metadata.', [ self::class, 'get_post' ], [ self::class, 'can_read_post' ], true, true, [ 'post_id' => [ 'type' => 'integer' ] ], [ 'post_id' ] );
		self::ability( 'closehub/create-post', 'Create post', 'Create a post as a draft unless another valid status is supplied. Pass post_type to create a page, product, or other registered content type instead of a post.', [ self::class, 'create_post' ], [ self::class, 'can_create_post' ], false, false, self::post_fields( true ), [ 'title', 'content' ] );
		self::ability( 'closehub/update-post', 'Update post', 'Update an existing post, page, product, or other supported content item and its CloseHub metadata.', [ self::class, 'update_post' ], [ self::class, 'can_edit_post' ], false, false, self::post_fields( false ), [ 'post_id' ] );
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
	public static function can_read_post( $input ): bool { return current_user_can( 'read_post', absint( $input['post_id'] ?? 0 ) ); }
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
		return ( new CloseHub_REST_API() )->create_post_for_mcp( $request );
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
		return ( new CloseHub_REST_API() )->update_post_for_mcp( $request );
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
			$data += [ 'content' => $post->post_content, 'excerpt' => $post->post_excerpt, 'categories' => $categories ] + CloseHub_REST_API::cms_metadata( $post->ID );
		}
		return $data;
	}
}
