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
				'id'    => [ 'required' => true, 'type' => 'integer' ],
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

	// ── Route callbacks ────────────────────────────────────────────────────────

	public function ping(): WP_REST_Response {
		return rest_ensure_response( [
			'ok'      => true,
			'site'    => get_bloginfo( 'name' ),
			'url'     => get_site_url(),
			'version' => get_bloginfo( 'version' ),
			'closehub_connector' => CLOSEHUB_VERSION,
		] );
	}

	public function create_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
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

		return rest_ensure_response( [
			'id'   => $post_id,
			'link' => get_permalink( $post_id ),
		] );
	}

	public function get_woocommerce_orders( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'closehub_woo_missing', 'WooCommerce is not active.', [ 'status' => 503 ] );
		}

		$statuses = array_map(
			static fn( $s ) => 'wc-' . trim( $s ),
			explode( ',', $request->get_param( 'status' ) )
		);

		$orders = wc_get_orders( [
			'status'       => $statuses,
			'date_after'   => $request->get_param( 'after' ),
			'date_before'  => $request->get_param( 'before' ),
			'limit'        => -1,
			'return'       => 'objects',
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

		return rest_ensure_response( [
			'orders_count'  => $count,
			'total_sales'   => round( $total_sales, 2 ),
			'average_order' => $count > 0 ? round( $total_sales / $count, 2 ) : 0.0,
			'orders'        => $items,
		] );
	}

	public function list_forms(): WP_REST_Response|WP_Error {
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

		return rest_ensure_response( array_values( $data ) );
	}

	public function get_form( WP_REST_Request $request ): WP_REST_Response|WP_Error {
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

		return rest_ensure_response( [
			'id'         => (string) $form['id'],
			'title'      => $form['title'],
			'is_active'  => (bool) $form['is_active'],
			'entries'    => (int) GFAPI::count_entries( $form_id ),
			'last_entry' => $last_entry,
		] );
	}

	public function get_form_entries( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! class_exists( 'GFAPI' ) ) {
			return new WP_Error( 'closehub_gf_missing', 'Gravity Forms is not active.', [ 'status' => 503 ] );
		}

		$form_id    = (int) $request->get_param( 'id' );
		$search     = [];

		if ( $request->get_param( 'after' ) ) {
			$search['start_date'] = sanitize_text_field( $request->get_param( 'after' ) );
		}
		if ( $request->get_param( 'before' ) ) {
			$search['end_date'] = sanitize_text_field( $request->get_param( 'before' ) );
		}

		$count = GFAPI::count_entries( $form_id, $search );

		return rest_ensure_response( [
			'form_id'     => $form_id,
			'total_count' => (int) $count,
		] );
	}
}
