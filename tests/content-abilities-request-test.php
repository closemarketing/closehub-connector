<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {}
class WP_Post {
	public $post_type;

	public function __construct( string $post_type = 'post' ) {
		$this->post_type = $post_type;
	}
}
class WP_REST_Request {
	public $params = [];
	public $method;
	public $route;
	public function __construct( string $method, string $route ) { $this->method = $method; $this->route = $route; }
	public function set_param( string $key, $value ): void { $this->params[ $key ] = $value; }
	public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
}
class CloseHub_REST_API {
	public function create_post_for_mcp( WP_REST_Request $request ): array { return $request->params; }
	public function update_post_for_mcp( WP_REST_Request $request ): array { return $request->params; }
	public function get_woocommerce_orders_for_mcp( WP_REST_Request $request ): array { return $request->params; }
	public static function cms_metadata( int $post_id ): array { return []; }
	public static function post_type_allowed( string $post_type ): bool { return in_array( $post_type, [ 'post', 'page', 'product' ], true ); }
}

$GLOBALS['closehub_test_can_manage_categories'] = true;
$GLOBALS['closehub_test_existing_terms']        = [];
$GLOBALS['closehub_test_post_type']             = 'post';
function current_user_can( string $capability ): bool { return 'manage_categories' !== $capability || $GLOBALS['closehub_test_can_manage_categories']; }
function term_exists( string $term, string $taxonomy ): bool { return in_array( $term, $GLOBALS['closehub_test_existing_terms'], true ); }
function sanitize_text_field( string $value ): string { return $value; }
function sanitize_key( string $key ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) ); }
function absint( $value ): int { return abs( (int) $value ); }
function is_object_in_taxonomy( string $object_type, string $taxonomy ): bool { return 'category' === $taxonomy && 'product' !== $object_type; }
function get_post( int $id ): WP_Post { return new WP_Post( $GLOBALS['closehub_test_post_type'] ); }

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

// create-post accepts an explicit post_type and passes it through.
$product = CloseHub_Content_Abilities::create_post( [ 'title' => 'A product', 'content' => '', 'post_type' => 'product' ] );
if ( $product instanceof WP_Error || 'product' !== $product['post_type'] ) { fwrite( STDERR, "post_type was not passed through to a supported non-post type.\n" ); exit( 1 ); }

// create-post rejects a post type that isn't registered/manageable.
$unsupported = CloseHub_Content_Abilities::create_post( [ 'title' => 'x', 'content' => '', 'post_type' => 'nav_menu_item' ] );
if ( ! $unsupported instanceof WP_Error ) { fwrite( STDERR, "An unsupported post_type should be rejected.\n" ); exit( 1 ); }

// update-post on an existing product may update fields it doesn't have categories for...
$GLOBALS['closehub_test_post_type'] = 'product';
$product_update                     = CloseHub_Content_Abilities::update_post( [ 'post_id' => 9132, 'title' => 'Renamed product' ] );
if ( $product_update instanceof WP_Error ) { fwrite( STDERR, "update-post should be able to write a non-post post_type such as product.\n" ); exit( 1 ); }

// ...but is rejected if it tries to set categories on a post type that doesn't support that taxonomy.
$product_categories = CloseHub_Content_Abilities::update_post( [ 'post_id' => 9132, 'categories' => [ 'Existing Category' ] ] );
if ( ! $product_categories instanceof WP_Error ) { fwrite( STDERR, "Categories should be rejected for a post_type that doesn't support the category taxonomy.\n" ); exit( 1 ); }
$GLOBALS['closehub_test_post_type'] = 'post';

echo "Content ability request checks passed.\n";
