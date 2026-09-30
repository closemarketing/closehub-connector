<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = [] ) {}
}
class WP_REST_Request {
	public function __construct( private array $params = [] ) {}
	public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
}

$GLOBALS['closehub_test_thumbnail_id'] = 781;
$GLOBALS['closehub_test_set_thumbnail_calls'] = 0;

function is_wp_error( mixed $thing ): bool { return $thing instanceof WP_Error; }
function attachment_url_to_postid( string $url ): int { return 781; }
function get_post_type( int $post_id ): string { return 'attachment'; }
function get_post_thumbnail_id( int $post_id ): int { return $GLOBALS['closehub_test_thumbnail_id']; }
function set_post_thumbnail( int $post_id, int $attachment_id ): bool { ++$GLOBALS['closehub_test_set_thumbnail_calls']; return false; }
function update_post_meta( int $post_id, string $key, string $value ): bool { return true; }
function get_post_meta( int $post_id, string $key, bool $single ) { return ''; }
function sanitize_text_field( string $value ): string { return $value; }

require_once dirname( __DIR__ ) . '/includes/class-rest-api.php';

$save_post_metadata = new ReflectionMethod( CloseHub_REST_API::class, 'save_post_metadata' );
$save_post_metadata->setAccessible( true );
$result = $save_post_metadata->invoke( new CloseHub_REST_API(), 42, new WP_REST_Request( [
	'featured_image_url' => 'https://example.test/uploads/photo.jpg',
] ) );

if ( true !== $result || 0 !== $GLOBALS['closehub_test_set_thumbnail_calls'] ) {
	fwrite( STDERR, "An already assigned featured image must be treated as a successful no-op.\n" );
	exit( 1 );
}

echo "Featured image assignment checks passed.\n";
