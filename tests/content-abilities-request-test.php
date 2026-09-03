<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {}
class WP_Post {}
class WP_REST_Request {
	public array $params = [];
	public function __construct( public string $method, public string $route ) {}
	public function set_param( string $key, mixed $value ): void { $this->params[ $key ] = $value; }
	public function get_param( string $key ): mixed { return $this->params[ $key ] ?? null; }
}
class CloseHub_REST_API {
	public function create_post_data( WP_REST_Request $request ): array { return $request->params; }
	public function get_woocommerce_orders_data( WP_REST_Request $request ): array { return $request->params; }
}

require_once dirname( __DIR__ ) . '/includes/class-content-abilities.php';

$post = CloseHub_Content_Abilities::create_post( [ 'title' => 'Test', 'content' => '<p>Test</p>' ] );
if ( 'draft' !== $post['status'] || 'Test' !== $post['title'] ) { fwrite( STDERR, "Post input was not passed through.\n" ); exit( 1 ); }
$orders = CloseHub_Content_Abilities::get_order_summary( [ 'after' => '2026-01-01' ] );
if ( '2026-01-01' !== $orders['after'] ) { fwrite( STDERR, "Order input was not passed through.\n" ); exit( 1 ); }

echo "Content ability request checks passed.\n";
