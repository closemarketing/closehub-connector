<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
}
class WP_Post {
	public function __construct( public int $ID, public string $post_title, public string $post_name, public string $post_content, public string $post_type = 'wp_block', public string $post_status = 'publish', public string $post_excerpt = '' ) {}
}
class WP_Block_Patterns_Registry {
	private static ?self $instance = null;
	public static function get_instance(): self { return self::$instance ??= new self(); }
	public function patterns(): array {
		return [
			'twentytwentyfive/hero'  => [ 'name' => 'twentytwentyfive/hero', 'title' => 'Hero', 'description' => 'Big headline', 'categories' => [ 'banner' ], 'blockTypes' => [ 'core/post-content' ], 'content' => '<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->' ],
			'core/quote'             => [ 'name' => 'core/quote', 'title' => 'Quote', 'content' => '<!-- wp:quote /-->' ],
			'acme/pricing'           => [ 'name' => 'acme/pricing', 'title' => 'Pricing table', 'categories' => [ 'banner', 'text' ], 'content' => '<!-- wp:table /-->' ],
		];
	}
	public function get_all_registered(): array { return array_values( $this->patterns() ); }
	public function get_registered( string $name ): ?array { return $this->patterns()[ $name ] ?? null; }
}

$GLOBALS['closehub_test_abilities'] = [];
$GLOBALS['closehub_test_last_capability'] = '';
$GLOBALS['closehub_test_blocks'] = [ new WP_Post( 90, 'Footer CTA', 'footer-cta', '<!-- wp:paragraph --><p>Call us</p><!-- /wp:paragraph -->' ), new WP_Post( 91, 'Draft block', 'draft-block', 'x', 'wp_block', 'draft' ) ];
function wp_register_ability( string $id, array $args ): bool { $GLOBALS['closehub_test_abilities'][ $id ] = $args; return true; }
function current_user_can( string $capability, ...$args ): bool { $GLOBALS['closehub_test_last_capability'] = $capability; return true; }
function absint( $value ): int { return abs( (int) $value ); }
function sanitize_text_field( $value ): string { return trim( (string) $value ); }
function sanitize_key( $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function get_stylesheet(): string { return 'twentytwentyfive'; }
function get_template(): string { return 'twentytwentyfive'; }
function get_post( int $id ): ?WP_Post { foreach ( $GLOBALS['closehub_test_blocks'] as $post ) { if ( $post->ID === $id ) { return $post; } } return ( 5 === $id ) ? new WP_Post( 5, 'A page', 'a-page', 'body', 'page' ) : null; }
function get_posts( array $args ): array { return array_values( array_filter( $GLOBALS['closehub_test_blocks'], static fn( WP_Post $p ): bool => $p->post_status === $args['post_status'] ) ); }
function wp_get_post_terms( int $id, string $taxonomy, array $args ): array { return [ 'sections' ]; }
function is_multisite(): bool { return false; }

require_once dirname( __DIR__ ) . '/includes/class-network.php';
require_once dirname( __DIR__ ) . '/includes/class-pattern-abilities.php';

function closehub_pattern_fail( string $message ): void { fwrite( STDERR, $message . "\n" ); exit( 1 ); }

CloseHub_Pattern_Abilities::register_abilities();
foreach ( [ 'closehub/list-theme-patterns', 'closehub/get-theme-pattern' ] as $id ) {
	$ability = $GLOBALS['closehub_test_abilities'][ $id ] ?? null;
	if ( ! $ability ) { closehub_pattern_fail( "$id must be registered." ); }
	if ( empty( $ability['meta']['annotations']['readonly'] ) ) { closehub_pattern_fail( "$id must be read-only." ); }
	$ability['permission_callback']( [] );
	if ( 'edit_posts' !== $GLOBALS['closehub_test_last_capability'] ) { closehub_pattern_fail( "$id must require edit_posts." ); }
}
if ( ! in_array( 'closehub/get-theme-pattern', CloseHub_Pattern_Abilities::add_tools_to_default_server( [] )['tools'], true ) ) { closehub_pattern_fail( 'Pattern abilities must be added as MCP tools.' ); }

$all = CloseHub_Pattern_Abilities::list_patterns( [] );
if ( 4 !== $all['total'] ) { closehub_pattern_fail( 'List should return 3 registered patterns plus 1 published synced pattern.' ); }
$sources = array_column( $all['patterns'], 'source', 'slug' );
if ( 'theme' !== $sources['twentytwentyfive/hero'] || 'core' !== $sources['core/quote'] || 'plugin' !== $sources['acme/pricing'] || 'synced' !== $sources['footer-cta'] ) { closehub_pattern_fail( 'Pattern sources are wrong: ' . json_encode( $sources ) ); }
foreach ( $all['patterns'] as $pattern ) {
	if ( isset( $pattern['content'] ) ) { closehub_pattern_fail( 'List must never return pattern content.' ); }
}

$banner = CloseHub_Pattern_Abilities::list_patterns( [ 'category' => 'banner' ] );
if ( [ 'twentytwentyfive/hero', 'acme/pricing' ] !== array_column( $banner['patterns'], 'slug' ) ) { closehub_pattern_fail( 'Category filter failed.' ); }
$search = CloseHub_Pattern_Abilities::list_patterns( [ 'search' => 'PRICING' ] );
if ( [ 'acme/pricing' ] !== array_column( $search['patterns'], 'slug' ) ) { closehub_pattern_fail( 'Search must be case-insensitive.' ); }

$hero = CloseHub_Pattern_Abilities::get_pattern( [ 'slug' => 'twentytwentyfive/hero' ] );
if ( $hero instanceof WP_Error || ! str_contains( $hero['content'], '<h2>Hero</h2>' ) ) { closehub_pattern_fail( 'get by slug must return the content.' ); }
$synced = CloseHub_Pattern_Abilities::get_pattern( [ 'id' => 90 ] );
if ( $synced instanceof WP_Error || ! str_contains( $synced['content'], 'Call us' ) || 90 !== $synced['id'] ) { closehub_pattern_fail( 'get by id must return the synced pattern content.' ); }

foreach ( [ [ 'slug' => 'nope/missing' ], [ 'id' => 91 ], [ 'id' => 5 ] ] as $bad ) {
	$result = CloseHub_Pattern_Abilities::get_pattern( $bad );
	if ( ! $result instanceof WP_Error || 'closehub_pattern_not_found' !== $result->code ) { closehub_pattern_fail( 'Unknown, draft or non-wp_block patterns must be 404: ' . json_encode( $bad ) ); }
}
$none = CloseHub_Pattern_Abilities::get_pattern( [] );
if ( ! $none instanceof WP_Error || 'closehub_pattern_missing_identifier' !== $none->code ) { closehub_pattern_fail( 'get without slug or id must fail.' ); }

echo "Pattern ability checks passed.\n";
