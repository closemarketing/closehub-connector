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
class CloseHub_Test_WPDB {
	public string $posts = 'posts';
	public bool $force_conflict = false;
	public function update( string $table, array $data, array $where ): int|false {
		if ( $this->force_conflict ) {
			$GLOBALS['closehub_test_post']->post_content = '<!-- wp:paragraph --><p>Concurrent edit</p><!-- /wp:paragraph -->';
			return 0;
		}
		if ( $where['post_content'] !== $GLOBALS['closehub_test_post']->post_content ) { return 0; }
		$GLOBALS['closehub_test_post']->post_content = $data['post_content'];
		return 1;
	}
}

$GLOBALS['closehub_test_post'] = new WP_Post( 2788, '<!-- wp:paragraph --><p>Old text</p><!-- /wp:paragraph --><!-- wp:heading {"level":2} --><h2>Heading</h2><!-- /wp:heading -->' );
$GLOBALS['closehub_test_last_capability'] = '';
$GLOBALS['wpdb'] = new CloseHub_Test_WPDB();
$GLOBALS['closehub_test_abilities'] = [];
function get_post( int $id ): ?WP_Post { return $id === $GLOBALS['closehub_test_post']->ID ? $GLOBALS['closehub_test_post'] : null; }
function current_time( string $type, bool $gmt = false ): string { return '2026-09-18 07:00:00'; }
function clean_post_cache( int $id ): void {}
function wp_register_ability_category( string $id, array $args ): bool { return true; }
function wp_register_ability( string $id, array $args ): bool { $GLOBALS['closehub_test_abilities'][ $id ] = $args; return true; }
function current_user_can( string $capability, ...$args ): bool { $GLOBALS['closehub_test_last_capability'] = $capability; return true; }
function absint( $value ): int { return abs( (int) $value ); }
function sanitize_text_field( string $value ): string { return trim( $value ); }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ); }

function parse_blocks( string $content ): array {
	if ( ! preg_match_all( '#<!-- wp:([^ ]+)(?: (\{.*?\}))? -->(.*?)<!-- /wp:\1 -->#s', $content, $matches, PREG_SET_ORDER ) ) { return []; }
	return array_map( static fn( array $match ): array => [ 'blockName' => str_contains( $match[1], '/' ) ? $match[1] : 'core/' . $match[1], 'attrs' => isset( $match[2] ) ? json_decode( $match[2], true ) : [], 'innerBlocks' => [], 'innerHTML' => $match[3], 'innerContent' => [ $match[3] ] ], $matches );
}
function serialize_blocks( array $blocks ): string {
	return implode( '', array_map( static function( array $block ): string {
		$attrs = $block['attrs'] ? ' ' . json_encode( $block['attrs'], JSON_UNESCAPED_SLASHES ) : '';
		$name = str_starts_with( $block['blockName'], 'core/' ) ? substr( $block['blockName'], 5 ) : $block['blockName'];
		return '<!-- wp:' . $name . $attrs . ' -->' . $block['innerHTML'] . '<!-- /wp:' . $name . ' -->';
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
	'block' => "\n  <!-- wp:paragraph --><p>New text</p><!-- /wp:paragraph -->\n",
] );
if ( $result instanceof WP_Error || ! str_contains( $GLOBALS['closehub_test_post']->post_content, '<p>New text</p>' ) || ! str_contains( $GLOBALS['closehub_test_post']->post_content, '<h2>Heading</h2>' ) ) { fwrite( STDERR, "Replacing one block should preserve siblings and allow whitespace around the replacement.\n" ); exit( 1 ); }

$stale = CloseHub_Content_Abilities::replace_gutenberg_block( [
	'post_id' => 2788, 'block_path' => [ 0 ], 'expected_block_name' => 'paragraph', 'expected_content_hash' => hash( 'sha256', $before ), 'block' => '<!-- wp:paragraph --><p>Stale</p><!-- /wp:paragraph -->',
] );
if ( ! $stale instanceof WP_Error || 'closehub_content_changed' !== $stale->code ) { fwrite( STDERR, "A stale content hash should be rejected.\n" ); exit( 1 ); }

$wrong_type = CloseHub_Content_Abilities::replace_gutenberg_block( [
	'post_id' => 2788, 'block_path' => [ 1 ], 'expected_block_name' => 'paragraph', 'expected_content_hash' => hash( 'sha256', $GLOBALS['closehub_test_post']->post_content ), 'block' => '<!-- wp:paragraph --><p>Wrong target</p><!-- /wp:paragraph -->',
] );
if ( ! $wrong_type instanceof WP_Error || 'closehub_block_type_mismatch' !== $wrong_type->code ) { fwrite( STDERR, "A mismatched target type should be rejected.\n" ); exit( 1 ); }

$GLOBALS['wpdb']->force_conflict = true;
$concurrent = CloseHub_Content_Abilities::replace_gutenberg_block( [
	'post_id' => 2788, 'block_path' => [ 0 ], 'expected_block_name' => 'core/paragraph', 'expected_content_hash' => hash( 'sha256', $GLOBALS['closehub_test_post']->post_content ), 'block' => '<!-- wp:paragraph --><p>Locked</p><!-- /wp:paragraph -->',
] );
if ( ! $concurrent instanceof WP_Error || 'closehub_content_changed' !== $concurrent->code ) { fwrite( STDERR, "A concurrent write must be rejected atomically.\n" ); exit( 1 ); }

CloseHub_Content_Abilities::register_abilities();
if ( empty( $GLOBALS['closehub_test_abilities']['closehub/replace-gutenberg-block']['meta']['annotations']['destructive'] ) ) { fwrite( STDERR, "Block replacement must be marked destructive.\n" ); exit( 1 ); }

echo "Gutenberg block ability checks passed.\n";
