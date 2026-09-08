<?php

defined( 'ABSPATH' ) || exit;

class CloseHub_REST_API {

	const NAMESPACE = 'closehub/v1';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		// ── Posts ──────────────────────────────────────────────────────────────
		register_rest_route( self::NAMESPACE, '/posts', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'create_post' ],
			'permission_callback' => [ $this, 'check_api_key' ],
			'args'                => [
				'title'   => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'content' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'wp_kses_post' ],
				'excerpt' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
				'featured_image_url' => [
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
					'validate_callback' => static fn( $v ) => empty( $v ) || (bool) wp_http_validate_url( $v ),
				],
				'seo_title'         => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'seo_description'   => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
				'seo_focus_keyword' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'categories'        => [
					'required' => false,
					'type'     => 'array',
					'items'    => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'default'  => [],
				],
				'status'  => [
					'required'          => false,
					'type'              => 'string',
					'default'           => 'publish',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => static fn( $v ) => in_array( $v, [ 'publish', 'draft', 'pending' ], true ),
				],
			],
		] );

		register_rest_route( self::NAMESPACE, '/posts/(?P<id>\d+)', [
			'methods'             => 'PUT',
			'callback'            => [ $this, 'update_post' ],
			'permission_callback' => [ $this, 'check_api_key' ],
			'args'                => [
				'id'      => [ 'required' => true, 'type' => 'integer', 'validate_callback' => 'is_numeric' ],
				'title'   => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'content' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'wp_kses_post' ],
				'excerpt' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
				'featured_image_url' => [
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
					'validate_callback' => static fn( $v ) => empty( $v ) || (bool) wp_http_validate_url( $v ),
				],
				'seo_title'         => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'seo_description'   => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
				'seo_focus_keyword' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'categories'        => [
					'required' => false,
					'type'     => 'array',
					'items'    => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				],
				'status'  => [
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => static fn( $v ) => in_array( $v, [ 'publish', 'draft', 'pending' ], true ),
				],
			],
		] );

		// ── WooCommerce ────────────────────────────────────────────────────────
		register_rest_route( self::NAMESPACE, '/woocommerce/orders', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_woocommerce_orders' ],
			'permission_callback' => [ $this, 'check_api_key' ],
			'args'                => [
				'after'  => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'before' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'status' => [ 'required' => false, 'type' => 'string', 'default' => 'completed,processing', 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );

		// ── Gravity Forms ──────────────────────────────────────────────────────
		register_rest_route( self::NAMESPACE, '/gravity-forms/forms', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_forms' ],
			'permission_callback' => [ $this, 'check_api_key' ],
		] );

		register_rest_route( self::NAMESPACE, '/gravity-forms/forms/(?P<id>\d+)', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_form' ],
			'permission_callback' => [ $this, 'check_api_key' ],
			'args'                => [
				'id' => [ 'required' => true, 'type' => 'integer', 'validate_callback' => 'is_numeric' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/gravity-forms/forms/(?P<id>\d+)/entries', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_form_entries' ],
			'permission_callback' => [ $this, 'check_api_key' ],
			'args'                => [
				'id'     => [ 'required' => true, 'type' => 'integer' ],
				'after'  => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'before' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );

		// ── Ping / verify connection ───────────────────────────────────────────
		register_rest_route( self::NAMESPACE, '/ping', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'ping' ],
			'permission_callback' => [ $this, 'check_api_key' ],
		] );
	}

	// ── Permission callback ────────────────────────────────────────────────────

	public function check_api_key( WP_REST_Request $request ): bool|WP_Error {
		$key = $request->get_header( 'X-CloseHub-Key' );
		if ( ! $key ) {
			$key = $request->get_param( 'closehub_key' );
		}
		if ( ! $key || ! CloseHub_API_Key::verify( (string) $key ) ) {
			return new WP_Error( 'closehub_unauthorized', 'Invalid or missing API key.', [ 'status' => 401 ] ); // phpcs:ignore
		}
		return true;
	}

	// ── Network helper ─────────────────────────────────────────────────────────

	/**
	 * On a multisite network, run a callback on every site and collect the
	 * results instead of the callback's single-site return value. Each entry
	 * always contains 'site_id' and 'url'. If $key is given, the callback's
	 * return value is nested under that key (needed when the value is itself
	 * a list, e.g. Gravity Forms); otherwise it is merged into the entry. A
	 * WP_Error is added under 'error' instead.
	 */
	private function run_across_network( callable $callback, ?string $key = null ): array {
		$results = [];

		foreach ( get_sites( [ 'number' => 0 ] ) as $site ) {
			$blog_id = (int) $site->blog_id;
			switch_to_blog( $blog_id );

			$entry = [
				'site_id' => $blog_id,
				'url'     => get_site_url(),
			];

			$data = $callback();
			if ( is_wp_error( $data ) ) {
				$entry['error'] = $data->get_error_message();
			} elseif ( null !== $key ) {
				$entry[ $key ] = $data;
			} else {
				$entry += $data;
			}

			restore_current_blog();
			$results[] = $entry;
		}

		return $results;
	}

	/**
	 * Wrap a data builder so it runs once on the current site, or across
	 * every site in the network (aggregated under 'sites') when multisite.
	 */
	private function respond( callable $data_builder, ?string $network_key = null ): WP_REST_Response|WP_Error {
		$result = $this->run( $data_builder, $network_key );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Same network fan-out as respond(), but returns the plain array/WP_Error
	 * shape a caller outside the REST response cycle needs — e.g. an MCP
	 * ability's execute_callback, which never sees a WP_REST_Response.
	 */
	public function run( callable $data_builder, ?string $network_key = null ): array|WP_Error {
		if ( is_multisite() ) {
			return [ 'sites' => $this->run_across_network( $data_builder, $network_key ) ];
		}

		return $data_builder();
	}

	// ── Route callbacks ────────────────────────────────────────────────────────

	public function ping(): WP_REST_Response|WP_Error {
		return $this->respond( fn() => $this->get_ping_data() );
	}

	public function create_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( fn() => $this->create_post_data( $request ) );
	}

	public function update_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( fn() => $this->update_post_data( $request ) );
	}

	public function get_woocommerce_orders( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( fn() => $this->get_woocommerce_orders_data( $request ) );
	}

	// ── MCP-facing wrappers ────────────────────────────────────────────────────
	//
	// CloseHub_Content_Abilities builds a WP_REST_Request and calls these
	// instead of a route callback (an MCP ability's execute_callback never
	// sees a WP_REST_Response to unwrap), but must still fan out across a
	// multisite network the same way the /posts and /woocommerce/orders
	// routes do — hence run() rather than calling the *_data() builders
	// directly, which stay private per this repo's REST API convention.

	/** @return array|WP_Error Same shape as respond() before rest_ensure_response() wraps it. */
	public function create_post_for_mcp( WP_REST_Request $request ): array|WP_Error {
		return $this->run( fn() => $this->create_post_data( $request ) );
	}

	/** @return array|WP_Error Same shape as respond() before rest_ensure_response() wraps it. */
	public function update_post_for_mcp( WP_REST_Request $request ): array|WP_Error {
		return $this->run( fn() => $this->update_post_data( $request ) );
	}

	/** @return array|WP_Error Same shape as respond() before rest_ensure_response() wraps it. */
	public function get_woocommerce_orders_for_mcp( WP_REST_Request $request ): array|WP_Error {
		return $this->run( fn() => $this->get_woocommerce_orders_data( $request ) );
	}

	public function list_forms(): WP_REST_Response|WP_Error {
		return $this->respond( fn() => $this->list_forms_data(), 'forms' );
	}

	public function get_form( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( fn() => $this->get_form_data( $request ) );
	}

	public function get_form_entries( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( fn() => $this->get_form_entries_data( $request ) );
	}

	// ── Data builders ───────────────────────────────────────────────────────────

	private function get_ping_data(): array {
		return [
			'ok'                 => true,
			'site'               => get_bloginfo( 'name' ),
			'url'                => get_site_url(),
			'version'            => get_bloginfo( 'version' ),
			'closehub_connector' => CLOSEHUB_VERSION,
		];
	}

	private function create_post_data( WP_REST_Request $request ): array|WP_Error {
		$requested_status = $request->get_param( 'status' );

		// Keep the post non-public while its metadata is being saved. This makes
		// every publication hook see the final categories, thumbnail, and SEO data.
		$post_id = wp_insert_post( [
			'post_title'   => $request->get_param( 'title' ),
			'post_content' => $request->get_param( 'content' ),
			'post_excerpt' => $request->get_param( 'excerpt' ) ?? '',
			'post_status'  => 'draft',
			'post_type'    => 'post',
		], true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$result = $this->save_post_metadata( $post_id, $request );
		if ( is_wp_error( $result ) ) {
			return $this->rollback_post( $post_id, $result );
		}

		if ( 'draft' !== $requested_status ) {
			$updated_post_id = wp_update_post( [
				'ID'          => $post_id,
				'post_status' => $requested_status,
			], true );

			if ( is_wp_error( $updated_post_id ) ) {
				return $this->rollback_post( $post_id, $updated_post_id );
			}
		}

		return $this->post_response( $post_id );
	}

	/**
	 * Update an existing post's core fields plus the same optional SEO,
	 * taxonomy, and featured-image data create_post_data() accepts — reusing
	 * save_post_metadata() so both paths stay in sync with whichever SEO
	 * plugin is active.
	 */
	private function update_post_data( WP_REST_Request $request ): array|WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'closehub_post_not_found', 'Post not found.', [ 'status' => 404 ] );
		}

		$fields = [ 'ID' => $post_id ];
		foreach ( [
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
			'status'  => 'post_status',
		] as $param => $post_field ) {
			if ( null !== $request->get_param( $param ) ) {
				$fields[ $post_field ] = $request->get_param( $param );
			}
		}

		if ( count( $fields ) > 1 ) {
			$result = wp_update_post( $fields, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$result = $this->save_post_metadata( $post_id, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->post_response( $post_id );
	}

	/** Post id/link plus whichever SEO and featured-image data is stored for it. */
	private function post_response( int $post_id ): array {
		return [ 'id' => $post_id, 'link' => get_permalink( $post_id ) ] + self::cms_metadata( $post_id );
	}

	/**
	 * The featured-image and whichever-SEO-plugin-is-active fields
	 * save_post_metadata() writes, read back for a response. Shared with
	 * CloseHub_Content_Abilities so get-post/list-posts return the same
	 * CloseHub-managed metadata create-post and update-post accept.
	 *
	 * @return array<string, string|null>
	 */
	public static function cms_metadata( int $post_id ): array {
		$data = [];

		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( $thumbnail_id ) {
			$data['featured_image_url'] = wp_get_attachment_url( $thumbnail_id ) ?: null;
		}

		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$data['seo_title']         = get_post_meta( $post_id, 'rank_math_title', true ) ?: null;
			$data['seo_description']   = get_post_meta( $post_id, 'rank_math_description', true ) ?: null;
			$data['seo_focus_keyword'] = get_post_meta( $post_id, 'rank_math_focus_keyword', true ) ?: null;
		} elseif ( defined( 'WPSEO_VERSION' ) ) {
			$data['seo_title']         = get_post_meta( $post_id, '_yoast_wpseo_title', true ) ?: null;
			$data['seo_description']   = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) ?: null;
			$data['seo_focus_keyword'] = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ) ?: null;
		}

		return $data;
	}

	/** Remove a failed post, or return an error that requires manual cleanup. */
	private function rollback_post( int $post_id, WP_Error $error ): WP_Error {
		if ( wp_delete_post( $post_id, true ) ) {
			return $error;
		}

		return new WP_Error(
			'closehub_post_rollback_failed',
			'Post metadata failed and the incomplete post could not be removed. Manual cleanup is required before retrying.',
			[
				'status'         => 500,
				'metadata_error' => $error->get_error_message(),
			]
		);
	}

	/** Save optional SEO, taxonomy, and featured-image data for a new post. */
	private function save_post_metadata( int $post_id, WP_REST_Request $request ): bool|WP_Error {
		$result = $this->save_seo_metadata( $post_id, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$categories = array_filter( array_map( 'sanitize_text_field', (array) $request->get_param( 'categories' ) ) );
		if ( $categories ) {
			$category_ids = [];

			foreach ( array_unique( $categories ) as $category_name ) {
				$term = term_exists( $category_name, 'category' );
				if ( ! $term ) {
					$term = wp_insert_term( $category_name, 'category' );
				}

				if ( is_wp_error( $term ) ) {
					// Another request can create the same term after term_exists()
					// but before wp_insert_term(). Reuse its id in that case.
					if ( 'term_exists' !== $term->get_error_code() || ! $term->get_error_data( 'term_exists' ) ) {
						return $term;
					}

					$term = (int) $term->get_error_data( 'term_exists' );
				}

				$category_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
			}

			$result = wp_set_post_categories( $post_id, $category_ids, false );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$featured_image_url = $request->get_param( 'featured_image_url' );
		if ( $featured_image_url ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$attachment_id = $this->sideload_featured_image( $featured_image_url, $post_id );
			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			if ( ! set_post_thumbnail( $post_id, (int) $attachment_id ) ) {
				if ( ! wp_delete_attachment( (int) $attachment_id, true ) ) {
					return new WP_Error(
						'closehub_featured_image_cleanup_failed',
						'The featured image could not be assigned and its uploaded attachment could not be removed. Manual cleanup is required before retrying.'
					);
				}

				return new WP_Error( 'closehub_featured_image_failed', 'The featured image could not be assigned to the post.' );
			}
		}

		return true;
	}

	/**
	 * Download and attach a featured image, including sources whose URL has no
	 * filename extension (for example, Google Drive's `uc` download endpoint).
	 */
	private function sideload_featured_image( string $image_url, int $post_id ): int|WP_Error {
		$temporary_file = download_url( $image_url );
		if ( is_wp_error( $temporary_file ) ) {
			return $temporary_file;
		}

		$attachment_id = media_handle_sideload(
			[
				// download_url() derives an extension from Content-Disposition or
				// Content-Type before this reaches WordPress's upload validation.
				'name'     => wp_basename( $temporary_file ),
				'tmp_name' => $temporary_file,
			],
			$post_id
		);

		if ( is_wp_error( $attachment_id ) && file_exists( $temporary_file ) ) {
			wp_delete_file( $temporary_file );
		}

		return $attachment_id;
	}

	/** Write SEO fields for whichever supported SEO plugin is active. */
	private function save_seo_metadata( int $post_id, WP_REST_Request $request ): bool|WP_Error {
		$seo_title         = (string) ( $request->get_param( 'seo_title' ) ?? '' );
		$seo_description   = (string) ( $request->get_param( 'seo_description' ) ?? '' );
		$seo_focus_keyword = (string) ( $request->get_param( 'seo_focus_keyword' ) ?? '' );

		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$result = $this->update_post_meta( $post_id, 'rank_math_title', $seo_title );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$result = $this->update_post_meta( $post_id, 'rank_math_description', $seo_description );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$result = $this->update_post_meta( $post_id, 'rank_math_focus_keyword', $seo_focus_keyword );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( defined( 'WPSEO_VERSION' ) ) {
			$result = $this->update_post_meta( $post_id, '_yoast_wpseo_title', $seo_title );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$result = $this->update_post_meta( $post_id, '_yoast_wpseo_metadesc', $seo_description );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$result = $this->update_post_meta( $post_id, '_yoast_wpseo_focuskw', $seo_focus_keyword );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/** Update a post meta value, reporting a rejected write as a REST error. */
	private function update_post_meta( int $post_id, string $key, string $value ): bool|WP_Error {
		if ( false !== update_post_meta( $post_id, $key, $value ) || get_post_meta( $post_id, $key, true ) === $value ) {
			return true;
		}

		return new WP_Error( 'closehub_seo_metadata_failed', sprintf( 'Could not save %s metadata.', $key ) );
	}

	private function get_woocommerce_orders_data( WP_REST_Request $request ): array|WP_Error {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'closehub_woo_missing', 'WooCommerce is not active.', [ 'status' => 503 ] );
		}

		$statuses = array_map(
			static fn( $s ) => 'wc-' . trim( $s ),
			explode( ',', $request->get_param( 'status' ) )
		);

		$orders = wc_get_orders( [
			'status'      => $statuses,
			'date_after'  => $request->get_param( 'after' ),
			'date_before' => $request->get_param( 'before' ),
			'limit'       => -1,
			'return'      => 'objects',
		] );

		$total_sales = 0.0;
		$items       = [];

		foreach ( $orders as $order ) {
			$total        = (float) $order->get_total();
			$total_sales += $total;
			$items[]      = [
				'id'     => $order->get_id(),
				'total'  => $total,
				'status' => $order->get_status(),
			];
		}

		$count = count( $items );

		return [
			'orders_count'  => $count,
			'total_sales'   => round( $total_sales, 2 ),
			'average_order' => $count > 0 ? round( $total_sales / $count, 2 ) : 0.0,
			'orders'        => $items,
		];
	}

	private function list_forms_data(): array|WP_Error {
		if ( ! class_exists( 'GFAPI' ) ) {
			return new WP_Error( 'closehub_gf_missing', 'Gravity Forms is not active.', [ 'status' => 503 ] );
		}

		$forms = GFAPI::get_forms();
		$data  = array_map( static fn( $f ) => [
			'id'        => (string) $f['id'],
			'title'     => $f['title'],
			'is_active' => (bool) $f['is_active'],
			'entries'   => (int) GFAPI::count_entries( $f['id'] ),
		], $forms );

		return array_values( $data );
	}

	private function get_form_data( WP_REST_Request $request ): array|WP_Error {
		if ( ! class_exists( 'GFAPI' ) ) {
			return new WP_Error( 'closehub_gf_missing', 'Gravity Forms is not active.', [ 'status' => 503 ] );
		}

		$form_id = (int) $request->get_param( 'id' );
		$form    = GFAPI::get_form( $form_id );

		if ( ! $form ) {
			return new WP_Error( 'closehub_gf_not_found', 'Form not found.', [ 'status' => 404 ] );
		}

		$entries    = GFAPI::get_entries( $form_id, [], [ 'direction' => 'DESC', 'key' => 'date_created' ], [ 'offset' => 0, 'page_size' => 1 ] );
		$last_entry = ! empty( $entries ) ? ( $entries[0]['date_created'] ?? null ) : null;

		return [
			'id'         => (string) $form['id'],
			'title'      => $form['title'],
			'is_active'  => (bool) $form['is_active'],
			'entries'    => (int) GFAPI::count_entries( $form_id ),
			'last_entry' => $last_entry,
		];
	}

	private function get_form_entries_data( WP_REST_Request $request ): array|WP_Error {
		if ( ! class_exists( 'GFAPI' ) ) {
			return new WP_Error( 'closehub_gf_missing', 'Gravity Forms is not active.', [ 'status' => 503 ] );
		}

		$form_id = (int) $request->get_param( 'id' );
		$search  = [];

		if ( $request->get_param( 'after' ) ) {
			$search['start_date'] = sanitize_text_field( $request->get_param( 'after' ) );
		}
		if ( $request->get_param( 'before' ) ) {
			$search['end_date'] = sanitize_text_field( $request->get_param( 'before' ) );
		}

		$count = GFAPI::count_entries( $form_id, $search );

		return [
			'form_id'     => $form_id,
			'total_count' => (int) $count,
		];
	}
}
