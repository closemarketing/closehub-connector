<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = [] ) {}
}
class WP_Post {
	public function __construct( public int $ID = 0, public string $post_type = 'post' ) {}
}

// 10 (es, trid 1) is being linked as the en translation of 20 (trid 2); 30 is the en translation that force would detach.
$GLOBALS['rows']     = [ 10 => [ 1, 'en', null ], 20 => [ 2, 'es', null ], 30 => [ 2, 'en', 'es' ] ];
$GLOBALS['editable'] = [ 10, 20, 30 ];

class Fake_WPDB {
	public string $prefix = 'wp_';
	public function prepare( string $query, mixed ...$args ): array { return [ $query, is_array( $args[0] ?? null ) ? $args[0] : $args ]; }
	public function get_results( array $q ): array {
		$out = [];
		foreach ( $GLOBALS['rows'] as $id => [ $trid, $lang, $src ] ) {
			if ( $trid === $q[1][0] ) { $out[] = (object) [ 'trid' => $trid, 'element_id' => $id, 'language_code' => $lang, 'source_language_code' => $src ]; }
		}
		return $out;
	}
}
$wpdb = new Fake_WPDB();

function get_current_blog_id(): int { return 1; }
function has_filter( string $hook ): bool { return true; }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	if ( 'wpml_element_type' === $hook ) { return 'post_' . $value; }
	if ( 'wpml_element_language_details' === $hook ) {
		$row = $GLOBALS['rows'][ (int) $args[0]['element_id'] ] ?? null;
		return $row ? (object) [ 'trid' => $row[0], 'language_code' => $row[1], 'source_language_code' => $row[2] ] : null;
	}
	return $value;
}
function get_post( int $id ): ?WP_Post { return isset( $GLOBALS['rows'][ $id ] ) ? new WP_Post( $id ) : null; }
function current_user_can( string $cap, int $post_id = 0 ): bool { return in_array( $post_id, $GLOBALS['editable'], true ); }
function absint( mixed $v ): int { return abs( (int) $v ); }
function sanitize_key( string $key ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) ); }

require_once dirname( __DIR__ ) . '/includes/class-wpml.php';
class CloseHub_REST_API { public static function post_type_allowed( string $type ): bool { return true; } }
require_once dirname( __DIR__ ) . '/includes/class-content-abilities.php';

$input = [ 'post_id' => 10, 'translation_of' => 20, 'language' => 'en' ];
if ( ! CloseHub_Content_Abilities::can_link_translations( $input ) ) { fwrite( STDERR, "A plain link needs only the two posts.\n" ); exit( 1 ); }
if ( ! CloseHub_Content_Abilities::can_link_translations( $input + [ 'force' => true ] ) ) { fwrite( STDERR, "Force is allowed when every affected post is editable.\n" ); exit( 1 ); }

$GLOBALS['editable'] = [ 10, 20 ];
if ( ! CloseHub_Content_Abilities::can_link_translations( $input ) ) { fwrite( STDERR, "Without force the third post is untouched.\n" ); exit( 1 ); }
if ( CloseHub_Content_Abilities::can_link_translations( $input + [ 'force' => true ] ) ) { fwrite( STDERR, "Force must be denied when it would detach a post the user cannot edit.\n" ); exit( 1 ); }

echo "Forced translation link permission checks passed.\n";
