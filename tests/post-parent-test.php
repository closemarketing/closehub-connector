<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = [] ) {}
}
class WP_Post {
	public function __construct( public string $post_type ) {}
}
class WP_REST_Request {
	public function __construct( private array $params = [] ) {}
	public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
}

// 10 = page, 11 = child of 10, 12 = product, 20 = hierarchical CPT, 21 = CPT.
$GLOBALS['closehub_test_types']     = [ 10 => 'page', 11 => 'page', 12 => 'product', 20 => 'docs', 21 => 'docs' ];
$GLOBALS['closehub_test_ancestors'] = [ 11 => [ 10 ] ];
$GLOBALS['closehub_test_can_edit']  = true;

function is_wp_error( mixed $thing ): bool { return $thing instanceof WP_Error; }
function absint( $value ): int { return abs( (int) $value ); }
function is_post_type_hierarchical( string $post_type ): bool { return in_array( $post_type, [ 'page', 'docs' ], true ); }
function get_post( int $id ) { return isset( $GLOBALS['closehub_test_types'][ $id ] ) ? new WP_Post( $GLOBALS['closehub_test_types'][ $id ] ) : null; }
function get_post_ancestors( int $id ): array { return $GLOBALS['closehub_test_ancestors'][ $id ] ?? []; }
function get_current_user_id(): int { return 1; }
function current_user_can( string $capability, int $id = 0 ): bool { return $GLOBALS['closehub_test_can_edit']; }

require_once dirname( __DIR__ ) . '/includes/class-rest-api.php';

$method = new ReflectionMethod( CloseHub_REST_API::class, 'parent_id_for' );
$method->setAccessible( true );
$api  = new CloseHub_REST_API();
$call = static fn( string $type, array $params, int $post_id = 0 ) => $method->invoke( $api, $type, new WP_REST_Request( $params ), $post_id );

$fail = static function ( string $message ): void { fwrite( STDERR, $message . "\n" ); exit( 1 ); };

if ( null !== $call( 'page', [] ) ) { $fail( 'A missing parent_id must leave the parent untouched (null).' ); }
if ( 10 !== $call( 'page', [ 'parent_id' => 10 ] ) ) { $fail( 'A page can be nested under another page.' ); }
if ( 20 !== $call( 'docs', [ 'parent_id' => 20 ] ) ) { $fail( 'A hierarchical custom post type can be nested under its own type.' ); }
if ( 0 !== $call( 'page', [ 'parent_id' => 0 ] ) ) { $fail( 'parent_id 0 must move the post to the top level.' ); }
if ( 0 !== $call( 'page', [ 'parent_id' => 0 ], 11 ) ) { $fail( 'parent_id 0 must work on update.' ); }

foreach ( [
	'non-hierarchical type' => [ 'post', [ 'parent_id' => 10 ], 0, 'closehub_parent_not_supported' ],
	'missing parent'        => [ 'page', [ 'parent_id' => 999 ], 0, 'closehub_parent_invalid' ],
	'cross-type parent'     => [ 'page', [ 'parent_id' => 12 ], 0, 'closehub_parent_invalid' ],
	'self parent'           => [ 'page', [ 'parent_id' => 10 ], 10, 'closehub_parent_cycle' ],
	'descendant parent'     => [ 'page', [ 'parent_id' => 11 ], 10, 'closehub_parent_cycle' ],
] as $label => [ $type, $params, $post_id, $code ] ) {
	$result = $call( $type, $params, $post_id );
	if ( ! $result instanceof WP_Error || $code !== $result->code ) { $fail( "Expected {$code} for {$label}." ); }
}

$GLOBALS['closehub_test_can_edit'] = false;
$result = $call( 'page', [ 'parent_id' => 10 ] );
if ( ! $result instanceof WP_Error || 'closehub_parent_forbidden' !== $result->code ) { $fail( 'A user who cannot edit the parent must be rejected.' ); }

echo "Post parent checks passed.\n";
