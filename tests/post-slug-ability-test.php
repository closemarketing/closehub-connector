<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
}
class WP_Post {
	public function __construct( public int $ID, public string $post_name, public string $post_type = 'page' ) {}
}
class CloseHub_REST_API {
	public static function post_type_allowed( string $post_type ): bool { return in_array( $post_type, [ 'post', 'page', 'product' ], true ); }
}

$GLOBALS['closehub_slug_post']      = new WP_Post( 2788, 'old-slug' );
$GLOBALS['closehub_slug_abilities'] = [];
$GLOBALS['closehub_slug_update']    = [];
function get_post( int $id ): ?WP_Post { return $id === $GLOBALS['closehub_slug_post']->ID ? $GLOBALS['closehub_slug_post'] : null; }
function wp_update_post( array $postarr, bool $wp_error = false ): int { $GLOBALS['closehub_slug_update'] = $postarr; $GLOBALS['closehub_slug_post']->post_name = 'new-slug-2'; return $postarr['ID']; }
function sanitize_title( string $slug ): string { return '///' === $slug ? '' : 'new-slug'; }
function get_permalink( int $id ): string { return 'https://example.test/' . $GLOBALS['closehub_slug_post']->post_name . '/'; }
function wp_register_ability_category( string $id, array $args ): bool { return true; }
function wp_register_ability( string $id, array $args ): bool { $GLOBALS['closehub_slug_abilities'][ $id ] = $args; return true; }
function absint( $value ): int { return abs( (int) $value ); }

require_once dirname( __DIR__ ) . '/includes/class-content-abilities.php';

$result = CloseHub_Content_Abilities::update_post_slug( [ 'post_id' => 2788, 'slug' => 'New Slug' ] );
if ( $result instanceof WP_Error || 'new-slug' !== $GLOBALS['closehub_slug_update']['post_name'] || 'new-slug-2' !== $result['slug'] || 'https://example.test/new-slug-2/' !== $result['url'] ) { fwrite( STDERR, "The normalized input and saved unique slug should be returned.\n" ); exit( 1 ); }

$invalid = CloseHub_Content_Abilities::update_post_slug( [ 'post_id' => 2788, 'slug' => '///' ] );
if ( ! $invalid instanceof WP_Error || 'closehub_invalid_post_slug' !== $invalid->code ) { fwrite( STDERR, "An empty normalized slug should be rejected.\n" ); exit( 1 ); }

$missing = CloseHub_Content_Abilities::update_post_slug( [ 'post_id' => 0, 'slug' => 'new-slug' ] );
if ( ! $missing instanceof WP_Error || 'closehub_post_not_found' !== $missing->code ) { fwrite( STDERR, "A missing post should be rejected.\n" ); exit( 1 ); }

CloseHub_Content_Abilities::register_abilities();
$ability = $GLOBALS['closehub_slug_abilities']['closehub/update-post-slug'] ?? [];
if ( empty( $ability['meta']['annotations']['destructive'] ) || empty( $ability['meta']['annotations']['idempotent'] ) || [ 'post_id', 'slug' ] !== $ability['input_schema']['required'] ) { fwrite( STDERR, "The slug ability metadata or schema is incorrect.\n" ); exit( 1 ); }

echo "Post slug ability checks passed.\n";
