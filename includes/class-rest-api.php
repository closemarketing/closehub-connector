<?php

defined( 'ABSPATH' ) || exit;

class CloseHub_REST_API {

	const NAMESPACE = 'closehub/v1';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		// ── Posts ──────────────────────────────────────────────────────────────
		$post_args = [
			'title'   => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'content' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'wp_kses_post' ],
			'excerpt' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
			'status'  => [
				'required'          => false,
				'type'              => 'string',
				'default'           => 'publish',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static fn( $v ) => in_array( $v, [ 'publish', 'draft', 'pending' ], true ),
			],
		];

		register_rest_route( self::NAMESPACE, '/posts', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'create_post' ],
			'permission_callback' => [ $this, 'check_api_key' ],
			'args'                => $post_args,
		] );

		// ── WooCommerce ────────────────────────────────────────────────────────
		$orders_args = [
			'after'  => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'before' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'status' => [ 'required' => false, 'type' => 'string', 'default' => 'completed,processing', 'sanitize_callback' => 'sanitize_text_field' ],
		];

		register_rest_route( self::NAMESPACE, '/woocommerce/orders', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_woocommerce_orders' ],
			'permission_callback' => [ $this, 'check_api_key' ],
			'args'                => $orders_args,
		] );

		// ── Gravity Forms ──────────────────────────────────────────────────────
		register_rest_route( self::NAMESPACE, '/gravity-forms/forms', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_forms' ],
			'permission_callback' => [ $this, 'check_api_key' ],
		] );

		$form_args = [
			'id' => [ 'required' => true, 'type' => 'integer', 'validate_callback' => 'is_numeric' ],
		];

		register_rest_route( self::NAMESPACE, '/gravity-forms/forms/(?P<id>\d+)', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_form' ],
			'permission_callback' => [ $this, 'check_api_key' ],
			'args'                => $form_args,
		] );

		$form_entries_args = [
			'id'     => [ 'required' => true, 'type' => 'integer' ],
			'after'  => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'before' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
		];

		register_rest_route( self::NAMESPACE, '/gravity-forms/forms/(?P<id>\d+)/entries', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_form_entries' ],
			'permission_callback' => [ $this, 'check_api_key' ],
			'args'                => $form_entries_args,
		] );

		// ── Ping / verify connection ───────────────────────────────────────────
		register_rest_route( self::NAMESPACE, '/ping', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'ping' ],
			'permission_callback' => [ $this, 'check_api_key' ],
		] );

		// ── Network (multisite) ────────────────────────────────────────────────
		if ( is_multisite() ) {
			register_rest_route( self::NAMESPACE, '/network/ping', [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'network_ping' ],
				'permission_callback' => [ $this, 'check_network_api_key' ],
			] );

			register_rest_route( self::NAMESPACE, '/network/posts', [
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'network_create_post' ],
				'permission_callback' => [ $this, 'check_network_api_key' ],
				'args'                => $post_args,
			] );

			register_rest_route( self::NAMESPACE, '/network/woocommerce/orders', [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'network_get_woocommerce_orders' ],
				'permission_callback' => [ $this, 'check_network_api_key' ],
				'args'                => $orders_args,
			] );

			register_rest_route( self::NAMESPACE, '/network/gravity-forms/forms', [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'network_list_forms' ],
				'permission_callback' => [ $this, 'check_network_api_key' ],
			] );

			register_rest_route( self::NAMESPACE, '/network/gravity-forms/forms/(?P<id>\d+)', [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'network_get_form' ],
				'permission_callback' => [ $this, 'check_network_api_key' ],
				'args'                => $form_args,
			] );

			register_rest_route( self::NAMESPACE, '/network/gravity-forms/forms/(?P<id>\d+)/entries', [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'network_get_form_entries' ],
				'permission_callback' => [ $this, 'check_network_api_key' ],
				'args'                => $form_entries_args,
			] );
		}
	}

	// ── Permission callbacks ───────────────────────────────────────────────────

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

	public function check_network_api_key( WP_REST_Request $request ): bool|WP_Error {
		$key = $request->get_header( 'X-CloseHub-Network-Key' );
		if ( ! $key ) {
			$key = $request->get_param( 'closehub_network_key' );
		}
		if ( ! $key || ! CloseHub_API_Key::verify_network( (string) $key ) ) {
			return new WP_Error( 'closehub_unauthorized', 'Invalid or missing network API key.', [ 'status' => 401 ] ); // phpcs:ignore
		}
		return true;
	}

	// ── Network helper ─────────────────────────────────────────────────────────

	/**
	 * Run a callback on every site in the network and collect the results.
	 * Each entry always contains 'site_id' and 'url'. If $key is given, the
	 * callback's return value is nested under that key (needed when the
	 * value is itself a list, e.g. Gravity Forms); otherwise it is merged
	 * into the entry. A WP_Error is added under 'error' instead.
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

	// ── Route callbacks (single site) ──────────────────────────────────────────

	public function ping(): WP_REST_Response {
		return rest_ensure_response( $this->get_ping_data() );
	}

	public function create_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->create_post_data( $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public function get_woocommerce_orders( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->get_woocommerce_orders_data( $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public function list_forms(): WP_REST_Response|WP_Error {
		$result = $this->list_forms_data();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public function get_form( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->get_form_data( $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public function get_form_entries( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->get_form_entries_data( $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	// ── Route callbacks (network) ──────────────────────────────────────────────

	public function network_ping(): WP_REST_Response {
		return rest_ensure_response( [
			'sites' => $this->run_across_network( fn() => $this->get_ping_data() ),
		] );
	}

	public function network_create_post( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( [
			'sites' => $this->run_across_network( fn() => $this->create_post_data( $request ) ),
		] );
	}

	public function network_get_woocommerce_orders( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( [
			'sites' => $this->run_across_network( fn() => $this->get_woocommerce_orders_data( $request ) ),
		] );
	}

	public function network_list_forms(): WP_REST_Response {
		return rest_ensure_response( [
			'sites' => $this->run_across_network( fn() => $this->list_forms_data(), 'forms' ),
		] );
	}

	public function network_get_form( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( [
			'sites' => $this->run_across_network( fn() => $this->get_form_data( $request ) ),
		] );
	}

	public function network_get_form_entries( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( [
			'sites' => $this->run_across_network( fn() => $this->get_form_entries_data( $request ) ),
		] );
	}

	// ── Data builders (shared by single-site and network callbacks) ───────────

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
		$post_id = wp_insert_post( [
			'post_title'   => $request->get_param( 'title' ),
			'post_content' => $request->get_param( 'content' ),
			'post_excerpt' => $request->get_param( 'excerpt' ) ?? '',
			'post_status'  => $request->get_param( 'status' ),
			'post_type'    => 'post',
		], true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return [
			'id'   => $post_id,
			'link' => get_permalink( $post_id ),
		];
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
