<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['closehub_test_multisite']    = false;
$GLOBALS['closehub_test_options']      = [];
$GLOBALS['closehub_test_site_options'] = [];
$GLOBALS['closehub_test_current_user'] = 7;

function is_multisite(): bool { return $GLOBALS['closehub_test_multisite']; }
function get_option( string $name, $default = false ) { return $GLOBALS['closehub_test_options'][ $name ] ?? $default; }
function update_option( string $name, $value, bool $autoload = true ): bool { $GLOBALS['closehub_test_options'][ $name ] = $value; return true; }
function get_site_option( string $name, $default = false ) { return $GLOBALS['closehub_test_site_options'][ $name ] ?? $default; }
function update_site_option( string $name, $value ): bool { $GLOBALS['closehub_test_site_options'][ $name ] = $value; return true; }
function get_current_user_id(): int { return $GLOBALS['closehub_test_current_user']; }
function get_user_by( string $field, $value ) {
	if ( 'login' === $field ) {
		return 'root' === $value ? (object) [ 'ID' => 1 ] : false;
	}
	return in_array( $value, [ 1, 7 ], true ) ? (object) [ 'ID' => $value ] : false;
}
function get_users( array $args ): array { return [ 1 ]; }
function get_super_admins(): array { return [ 'root' ]; }

require_once dirname( __DIR__ ) . '/includes/class-api-key.php';

function closehub_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, $message . PHP_EOL );
		exit( 1 );
	}
}

$key = CloseHub_API_Key::regenerate();
closehub_test_assert( 1 === preg_match( '/^chk_[a-f0-9]{48}$/', $key ), 'The API key must use the chk_ prefix.' );
closehub_test_assert( 7 === CloseHub_API_Key::authenticate( $key ), 'The API key must authenticate as the user who generated it.' );
closehub_test_assert( null === CloseHub_API_Key::authenticate( $key . '0' ), 'A wrong key must be rejected.' );
closehub_test_assert( null === CloseHub_API_Key::authenticate( 'chk_' . str_repeat( '0', 48 ) ), 'A different well-formed key must be rejected.' );

$GLOBALS['closehub_test_current_user'] = 0;
$new_key = CloseHub_API_Key::regenerate();
closehub_test_assert( null === CloseHub_API_Key::authenticate( $key ), 'A regenerated key must invalidate the previous one.' );
closehub_test_assert( 7 === CloseHub_API_Key::authenticate( $new_key ), 'Regenerating without a user (WP-CLI/cron) must keep the previous user.' );

$GLOBALS['closehub_test_options']['closehub_api_key_user'] = 999;
closehub_test_assert( 1 === CloseHub_API_Key::authenticate( $new_key ), 'A deleted user must fall back to the first administrator.' );

$GLOBALS['closehub_test_multisite'] = true;
$GLOBALS['closehub_test_current_user'] = 7;
$network_key = CloseHub_API_Key::regenerate();
closehub_test_assert( isset( $GLOBALS['closehub_test_site_options']['closehub_api_key_user'] ), 'Multisite must store the acting user as a network option.' );
closehub_test_assert( 7 === CloseHub_API_Key::authenticate( $network_key ), 'The network key must authenticate as its user.' );
$GLOBALS['closehub_test_site_options']['closehub_api_key_user'] = 999;
closehub_test_assert( 1 === CloseHub_API_Key::authenticate( $network_key ), 'A deleted user must fall back to the first super admin.' );

echo "API key checks passed.\n";
