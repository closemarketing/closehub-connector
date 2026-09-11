<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
}

$GLOBALS['closehub_test_caps'] = [];
$GLOBALS['closehub_test_post'] = null;

function current_user_can( string $capability, ...$args ): bool { return in_array( $capability, $GLOBALS['closehub_test_caps'], true ); }
function absint( $value ): int { return abs( (int) $value ); }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ); }
function sanitize_email( string $value ): string { return trim( $value ); }
function sanitize_user( string $value, bool $strict = false ): string { return trim( $value ); }
function is_email( string $value ): bool { return (bool) filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function get_post( int $id ) { return $GLOBALS['closehub_test_post']; }

/** Minimal re-implementation of WP core's is_serialized(), enough for the values these tests exercise. */
function is_serialized( $data ): bool {
	if ( ! is_string( $data ) ) { return false; }
	$data = trim( $data );
	if ( 'N;' === $data ) { return true; }
	if ( strlen( $data ) < 4 || ':' !== $data[1] ) { return false; }
	$token = $data[0];
	return in_array( $token, [ 'a', 'O', 's' ], true ) && (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
}

require_once dirname( __DIR__ ) . '/includes/class-site-abilities.php';

// ── replace_serialized_value: plain strings are replaced directly ──────────────
$plain = CloseHub_Site_Abilities::replace_serialized_value( 'Visit http://old.example.com today', 'old.example.com', 'new.example.com' );
if ( 'Visit http://new.example.com today' !== $plain ) { fwrite( STDERR, "Plain string replace failed.\n" ); exit( 1 ); }

// ── replace_serialized_value: serialized values are unserialized, replaced, and re-serialized with correct lengths ──
$serialized = serialize( [ 'url' => 'http://old.example.com/page' ] );
$replaced   = CloseHub_Site_Abilities::replace_serialized_value( $serialized, 'old.example.com', 'new.example.com' );
$expected   = serialize( [ 'url' => 'http://new.example.com/page' ] );
if ( $expected !== $replaced ) { fwrite( STDERR, "Serialized value replace produced invalid output: $replaced\n" ); exit( 1 ); }
if ( false === unserialize( $replaced ) ) { fwrite( STDERR, "Re-serialized value is not valid serialized data.\n" ); exit( 1 ); }

// ── search_replace_domain: missing input is rejected before touching $wpdb ─────
$missing = CloseHub_Site_Abilities::search_replace_domain( [] );
if ( ! $missing instanceof WP_Error ) { fwrite( STDERR, "search_replace_domain should reject missing old_domain/new_domain.\n" ); exit( 1 ); }

// ── set_robots_txt: empty content is rejected ──────────────────────────────────
$empty_robots = CloseHub_Site_Abilities::set_robots_txt( [ 'content' => '   ' ] );
if ( ! $empty_robots instanceof WP_Error ) { fwrite( STDERR, "set_robots_txt should reject empty content.\n" ); exit( 1 ); }

// ── set_site_settings: invalid timezone and no-settings-provided are rejected ──
$invalid_tz = CloseHub_Site_Abilities::set_site_settings( [ 'timezone' => 'Not/A/Zone' ] );
if ( ! $invalid_tz instanceof WP_Error ) { fwrite( STDERR, "set_site_settings should reject an invalid timezone.\n" ); exit( 1 ); }
$no_settings = CloseHub_Site_Abilities::set_site_settings( [] );
if ( ! $no_settings instanceof WP_Error ) { fwrite( STDERR, "set_site_settings should require at least one setting.\n" ); exit( 1 ); }

// ── create_site_user: invalid email and invalid role are rejected ──────────────
$bad_email = CloseHub_Site_Abilities::create_site_user( [ 'email' => 'not-an-email' ] );
if ( ! $bad_email instanceof WP_Error ) { fwrite( STDERR, "create_site_user should reject an invalid email.\n" ); exit( 1 ); }
$bad_role = CloseHub_Site_Abilities::create_site_user( [ 'email' => 'client@example.com', 'role' => 'subscriber' ] );
if ( ! $bad_role instanceof WP_Error ) { fwrite( STDERR, "create_site_user should reject a role other than administrator/editor.\n" ); exit( 1 ); }

// ── can_create_user: administrator role requires promote_users, editor does not ──
$GLOBALS['closehub_test_caps'] = [ 'create_users' ];
if ( CloseHub_Site_Abilities::can_create_user( [ 'role' => 'administrator' ] ) ) { fwrite( STDERR, "A user without promote_users should not be able to create an administrator.\n" ); exit( 1 ); }
if ( ! CloseHub_Site_Abilities::can_create_user( [ 'role' => 'editor' ] ) ) { fwrite( STDERR, "A user with create_users should be able to create an editor.\n" ); exit( 1 ); }
$GLOBALS['closehub_test_caps'] = [ 'create_users', 'promote_users' ];
if ( ! CloseHub_Site_Abilities::can_create_user( [ 'role' => 'administrator' ] ) ) { fwrite( STDERR, "A user with promote_users should be able to create an administrator.\n" ); exit( 1 ); }

// ── set_post_noindex: missing post is rejected ─────────────────────────────────
$GLOBALS['closehub_test_post'] = null;
$missing_post                 = CloseHub_Site_Abilities::set_post_noindex( [ 'post_id' => 999, 'noindex' => true ] );
if ( ! $missing_post instanceof WP_Error ) { fwrite( STDERR, "set_post_noindex should reject a missing post.\n" ); exit( 1 ); }

echo "Site ability request checks passed.\n";
