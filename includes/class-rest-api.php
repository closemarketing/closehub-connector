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
		if ( is_multisite() ) {
			return rest_ensure_response( [ 'sites' => $this->run_across_network( $data_builder, $network_key ) ] );
		}

		$result = $data_builder();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	// ── Route callbacks ────────────────────────────────────────────────────────

	public function ping(): WP_REST_Response|WP_Error {
		return $this->respond( fn() => $this->get_ping_data() );
	}

	public function create_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( fn() => $this->create_post_data( $request ) );
	}

	public function get_woocommerce_orders( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( fn() => $this->get_woocommerce_orders_data( $request ) );
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

		return [
			'id'   => $post_id,
			'link' => get_permalink( $post_id ),
		];
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
	private function save_post_metadata( int $post_id, WP_REST_Request $request ): true|WP_Error {
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
					return $term;
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

			$attachment_id = media_sideload_image( $featured_image_url, $post_id, null, 'id' );
			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			if ( ! set_post_thumbnail( $post_id, (int) $attachment_id ) ) {
				return new WP_Error( 'closehub_featured_image_failed', 'The featured image could not be assigned to the post.' );
			}
		}

		return true;
	}

	/** Write SEO fields for whichever supported SEO plugin is active. */
	private function save_seo_metadata( int $post_id, WP_REST_Request $request ): true|WP_Error {
		$seo_title         = $request->get_param( 'seo_title' );
		$seo_description   = $request->get_param( 'seo_description' );
		$seo_focus_keyword = $request->get_param( 'seo_focus_keyword' );

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
	private function update_post_meta( int $post_id, string $key, string $value ): true|WP_Error {
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
