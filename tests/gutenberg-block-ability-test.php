<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
}
class WP_Post {
	public function __construct( public int $ID, public string $post_content, public string $post_type = 'page' ) {}
}
class CloseHub_REST_API {
	public static function post_type_allowed( string $post_type ): bool { return in_array( $post_type, [ 'post', 'page' ], true ); }
}

$GLOBALS['closehub_test_post'] = new WP_Post( 2788, '<!-- wp:paragraph --><p>Old text</p><!-- /wp:paragraph --><!-- wp:heading {"level":2} --><h2>Heading</h2><!-- /wp:heading -->' );
$GLOBALS['closehub_test_last_capability'] = '';
function get_post( int $id ): ?WP_Post { return $id === $GLOBALS['closehub_test_post']->ID ? $GLOBALS['closehub_test_post'] : null; }
function get_post_field( string $field, int $id ): string { return $GLOBALS['closehub_test_post']->post_content; }
function wp_update_post( array $postarr, bool $wp_error = false ): int { $GLOBALS['closehub_test_post']->post_content = $postarr['post_content']; return $postarr['ID']; }
function current_user_can( string $capability, ...$args ): bool { $GLOBALS['closehub_test_last_capability'] = $capability; return true; }
function absint( $value ): int { return abs( (int) $value ); }
function sanitize_text_field( string $value ): string { return trim( $value ); }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ); }

function parse_blocks( string $content ): array {
	if ( ! preg_match_all( '#<!-- wp:([^ ]+)(?: (\{.*?\}))? -->(.*?)<!-- /wp:\1 -->#s', $content, $matches, PREG_SET_ORDER ) ) { return []; }
	return array_map( static fn( array $match ): array => [ 'blockName' => $match[1], 'attrs' => isset( $match[2] ) ? json_decode( $match[2], true ) : [], 'innerBlocks' => [], 'innerHTML' => $match[3], 'innerContent' => [ $match[3] ] ], $matches );
}
function serialize_blocks( array $blocks ): string {
	return implode( '', array_map( static function( array $block ): string {
		$attrs = $block['attrs'] ? ' ' . json_encode( $block['attrs'], JSON_UNESCAPED_SLASHES ) : '';
		return '<!-- wp:' . $block['blockName'] . $attrs . ' -->' . $block['innerHTML'] . '<!-- /wp:' . $block['blockName'] . ' -->';
	}, $blocks ) );
}

require_once dirname( __DIR__ ) . '/includes/class-content-abilities.php';

// get-post exposes raw post_content, so its permission callback must use the
// same object-level edit capability required by block replacement, not read_post.
CloseHub_Content_Abilities::can_edit_post( [ 'post_id' => 2788 ] );
if ( 'edit_post' !== $GLOBALS['closehub_test_last_capability'] ) { fwrite( STDERR, "get-post permission checks must use edit_post.\n" ); exit( 1 ); }

$before = $GLOBALS['closehub_test_post']->post_content;
$result = CloseHub_Content_Abilities::replace_gutenberg_block( [
	'post_id' => 2788,
	'block_path' => [ 0 ],
	'expected_block_name' => 'paragraph',
	'expected_content_hash' => hash( 'sha256', $before ),
	'block' => '<!-- wp:paragraph --><p>New text</p><!-- /wp:paragraph -->',
] );
if ( $result instanceof WP_Error || ! str_contains( $GLOBALS['closehub_test_post']->post_content, '<p>New text</p>' ) || ! str_contains( $GLOBALS['closehub_test_post']->post_content, '<h2>Heading</h2>' ) ) { fwrite( STDERR, "Replacing one block should preserve its siblings.\n" ); exit( 1 ); }

$stale = CloseHub_Content_Abilities::replace_gutenberg_block( [
	'post_id' => 2788, 'block_path' => [ 0 ], 'expected_block_name' => 'paragraph', 'expected_content_hash' => hash( 'sha256', $before ), 'block' => '<!-- wp:paragraph --><p>Stale</p><!-- /wp:paragraph -->',
] );
if ( ! $stale instanceof WP_Error || 'closehub_content_changed' !== $stale->code ) { fwrite( STDERR, "A stale content hash should be rejected.\n" ); exit( 1 ); }

$wrong_type = CloseHub_Content_Abilities::replace_gutenberg_block( [
	'post_id' => 2788, 'block_path' => [ 1 ], 'expected_block_name' => 'paragraph', 'expected_content_hash' => hash( 'sha256', $GLOBALS['closehub_test_post']->post_content ), 'block' => '<!-- wp:paragraph --><p>Wrong target</p><!-- /wp:paragraph -->',
] );
if ( ! $wrong_type instanceof WP_Error || 'closehub_block_type_mismatch' !== $wrong_type->code ) { fwrite( STDERR, "A mismatched target type should be rejected.\n" ); exit( 1 ); }

echo "Gutenberg block ability checks passed.\n";
