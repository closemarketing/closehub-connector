<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = [] ) {}
}
class WP_Post {
	public function __construct( public string $post_type = 'post' ) {}
}

$GLOBALS['closehub_wpml_enabled'] = false;
$GLOBALS['closehub_wpml_actions'] = [];
function has_filter( string $hook ): bool { return $GLOBALS['closehub_wpml_enabled'] && in_array( $hook, [ 'wpml_active_languages', 'wpml_element_language_details' ], true ); }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	if ( 'wpml_active_languages' === $hook ) { return [ 'en' => [], 'es' => [] ]; }
	if ( 'wpml_element_type' === $hook ) { return 'post_post'; }
	if ( 'wpml_element_language_details' === $hook && 7 === (int) ( $args[0]['element_id'] ?? 0 ) ) { return (object) [ 'trid' => 22, 'language_code' => 'en', 'source_language_code' => null ]; }
	return $value;
}
function do_action( string $hook, mixed ...$args ): void { $GLOBALS['closehub_wpml_actions'][] = [ $hook, $args[0] ?? null ]; }
function get_post( int $id ): ?WP_Post { return in_array( $id, [ 7, 8 ], true ) ? new WP_Post() : null; }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

require_once dirname( __DIR__ ) . '/includes/class-wpml.php';

if ( ! is_wp_error( CloseHub_WPML::validate( 'es' ) ) ) { fwrite( STDERR, "WPML requests must fail safely when WPML is unavailable.\n" ); exit( 1 ); }
$GLOBALS['closehub_wpml_enabled'] = true;
if ( 'es' !== CloseHub_WPML::validate( 'es' ) || ! is_wp_error( CloseHub_WPML::validate( 'fr' ) ) ) { fwrite( STDERR, "Language validation did not use active WPML languages.\n" ); exit( 1 ); }
if ( true !== CloseHub_WPML::assign( 8, 'es', 7 ) ) { fwrite( STDERR, "Could not assign a WPML translation.\n" ); exit( 1 ); }
$action = $GLOBALS['closehub_wpml_actions'][0] ?? [];
if ( 'wpml_set_element_language_details' !== ( $action[0] ?? '' ) || 22 !== ( $action[1]['trid'] ?? 0 ) || 'en' !== ( $action[1]['source_language_code'] ?? '' ) ) { fwrite( STDERR, "Translation was not linked to its source group.\n" ); exit( 1 ); }

echo "WPML bridge checks passed.\n";
