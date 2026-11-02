<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'RANK_MATH_VERSION', '1' );
define( 'WPSEO_VERSION', '1' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = [] ) {}
}
class WP_REST_Request {
	public function __construct( private array $params = [] ) {}
	public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
}

$GLOBALS['closehub_test_meta'] = [];

function is_wp_error( mixed $thing ): bool { return $thing instanceof WP_Error; }
function update_post_meta( int $post_id, string $key, string $value ): bool {
	$changed = ( $GLOBALS['closehub_test_meta'][ $post_id ][ $key ] ?? null ) !== $value;
	$GLOBALS['closehub_test_meta'][ $post_id ][ $key ] = $value;
	return $changed;
}
function get_post_meta( int $post_id, string $key, bool $single = false ): string { return $GLOBALS['closehub_test_meta'][ $post_id ][ $key ] ?? ''; }

require_once dirname( __DIR__ ) . '/includes/class-rest-api.php';

$method = new ReflectionMethod( CloseHub_REST_API::class, 'save_seo_metadata' );
$method->setAccessible( true );
$api  = new CloseHub_REST_API();
$save = static fn( array $params ) => $method->invoke( $api, 7, new WP_REST_Request( $params ) );

$fail = static function ( string $message ): void { fwrite( STDERR, $message . "\n" ); exit( 1 ); };
$meta = static fn( string $key ): string => $GLOBALS['closehub_test_meta'][7][ $key ] ?? '';

$GLOBALS['closehub_test_meta'][7] = [
	'rank_math_title' => 'Old title', 'rank_math_description' => 'Old description', 'rank_math_focus_keyword' => 'old keyword',
	'_yoast_wpseo_title' => 'Old title', '_yoast_wpseo_metadesc' => 'Old description', '_yoast_wpseo_focuskw' => 'old keyword',
];

if ( true !== $save( [ 'seo_title' => 'New title' ] ) ) { $fail( 'Saving a single SEO field must succeed.' ); }
if ( 'New title' !== $meta( 'rank_math_title' ) || 'New title' !== $meta( '_yoast_wpseo_title' ) ) { $fail( 'The sent title must be written for every active SEO plugin.' ); }
foreach ( [ 'rank_math_description' => 'Old description', 'rank_math_focus_keyword' => 'old keyword', '_yoast_wpseo_metadesc' => 'Old description', '_yoast_wpseo_focuskw' => 'old keyword' ] as $key => $expected ) {
	if ( $expected !== $meta( $key ) ) { $fail( "Updating only the title must leave $key untouched." ); }
}

$save( [ 'seo_description' => '' ] );
if ( '' !== $meta( 'rank_math_description' ) || 'New title' !== $meta( 'rank_math_title' ) ) { $fail( 'An empty string must clear only the field that was sent.' ); }

$save( [] );
if ( 'New title' !== $meta( 'rank_math_title' ) || 'old keyword' !== $meta( 'rank_math_focus_keyword' ) ) { $fail( 'A request with no SEO fields must change nothing.' ); }

echo "OK\n";
