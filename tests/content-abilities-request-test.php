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
	public function create_post_for_mcp( WP_REST_Request $request ): array { return $request->params; }
	public function update_post_for_mcp( WP_REST_Request $request ): array { return $request->params; }
	public function get_woocommerce_orders_for_mcp( WP_REST_Request $request ): array { return $request->params; }
	public static function cms_metadata( int $post_id ): array { return []; }
}

$GLOBALS['closehub_test_can_manage_categories'] = true;
$GLOBALS['closehub_test_existing_terms']        = [];
function current_user_can( string $capability ): bool { return 'manage_categories' !== $capability || $GLOBALS['closehub_test_can_manage_categories']; }
function term_exists( string $term, string $taxonomy ): bool { return in_array( $term, $GLOBALS['closehub_test_existing_terms'], true ); }
function sanitize_text_field( string $value ): string { return $value; }
function absint( $value ): int { return abs( (int) $value ); }

require_once dirname( __DIR__ ) . '/includes/class-content-abilities.php';

$post = CloseHub_Content_Abilities::create_post( [ 'title' => 'Test', 'content' => '<p>Test</p>' ] );
if ( 'draft' !== $post['status'] || 'Test' !== $post['title'] ) { fwrite( STDERR, "Post input was not passed through.\n" ); exit( 1 ); }
$orders = CloseHub_Content_Abilities::get_order_summary( [ 'after' => '2026-01-01' ] );
if ( '2026-01-01' !== $orders['after'] ) { fwrite( STDERR, "Order input was not passed through.\n" ); exit( 1 ); }

// A user who can manage_categories may create a new one implicitly.
$GLOBALS['closehub_test_can_manage_categories'] = true;
$GLOBALS['closehub_test_existing_terms']        = [];
$created = CloseHub_Content_Abilities::create_post( [ 'title' => 'Test', 'content' => '<p>Test</p>', 'categories' => [ 'New Category' ] ] );
if ( $created instanceof WP_Error ) { fwrite( STDERR, "A manage_categories user should be able to create a new category.\n" ); exit( 1 ); }

// A user who cannot manage_categories is rejected for a category that doesn't exist yet...
$GLOBALS['closehub_test_can_manage_categories'] = false;
$GLOBALS['closehub_test_existing_terms']        = [];
$rejected = CloseHub_Content_Abilities::create_post( [ 'title' => 'Test', 'content' => '<p>Test</p>', 'categories' => [ 'New Category' ] ] );
if ( ! $rejected instanceof WP_Error ) { fwrite( STDERR, "A user without manage_categories should not be able to create a new category.\n" ); exit( 1 ); }

// ...but the same user may still assign an already-existing category.
$GLOBALS['closehub_test_existing_terms'] = [ 'Existing Category' ];
$allowed                                 = CloseHub_Content_Abilities::update_post( [ 'post_id' => 1, 'categories' => [ 'Existing Category' ] ] );
if ( $allowed instanceof WP_Error ) { fwrite( STDERR, "A user without manage_categories should still be able to assign an existing category.\n" ); exit( 1 ); }

echo "Content ability request checks passed.\n";
