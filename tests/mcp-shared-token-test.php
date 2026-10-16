<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['closehub_test_multisite']    = false;
$GLOBALS['closehub_test_options']      = [];
$GLOBALS['closehub_test_site_options'] = [];

class WP_User {
	public function __construct( public int $ID, public string $user_login ) {}
}

function is_multisite(): bool { return $GLOBALS['closehub_test_multisite']; }
function get_option( string $name, $default = false ) { return $GLOBALS['closehub_test_options'][ $name ] ?? $default; }
function update_option( string $name, $value, bool $autoload = true ): bool { $GLOBALS['closehub_test_options'][ $name ] = $value; return true; }
function delete_option( string $name ): bool { unset( $GLOBALS['closehub_test_options'][ $name ] ); return true; }
function get_site_option( string $name, $default = false ) { return $GLOBALS['closehub_test_site_options'][ $name ] ?? $default; }
function update_site_option( string $name, $value ): bool { $GLOBALS['closehub_test_site_options'][ $name ] = $value; return true; }
function delete_site_option( string $name ): bool { unset( $GLOBALS['closehub_test_site_options'][ $name ] ); return true; }
function get_user_by( string $field, int $user_id ) {
	return in_array( $user_id, [ 7, 8 ], true ) ? new WP_User( $user_id, 'mcp-user-' . $user_id ) : false;
}
function wp_json_encode( $value ): string { return (string) json_encode( $value ); }

require_once dirname( __DIR__ ) . '/includes/class-mcp-token.php';

function closehub_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, $message . PHP_EOL );
		exit( 1 );
	}
}

$token = CloseHub_MCP_Token::generate( 7 );
closehub_test_assert( is_string( $token ) && 1 === preg_match( '/^chm_[a-f0-9]{64}$/', $token ), 'A shared MCP token must use the chm_ prefix and 256 bits of randomness.' );
$stored = $GLOBALS['closehub_test_options']['closehub_mcp_shared_token'];
closehub_test_assert( ! str_contains( wp_json_encode( $stored ), $token ), 'The plaintext shared token must not be stored.' );
closehub_test_assert( hash( 'sha256', $token ) === $stored['hash'], 'The shared token hash must be stored.' );
closehub_test_assert( 7 === CloseHub_MCP_Token::authenticate( $token ), 'The generated shared token must authenticate as its mapped user.' );
closehub_test_assert( null === CloseHub_MCP_Token::authenticate( $token . '0' ), 'A malformed shared token must be rejected.' );
closehub_test_assert( null === CloseHub_MCP_Token::authenticate( 'chm_' . str_repeat( '0', 64 ) ), 'A different well-formed shared token must be rejected.' );
closehub_test_assert( CloseHub_MCP_Token::is_configured(), 'A valid shared token must report as configured.' );

CloseHub_MCP_Token::revoke();
closehub_test_assert( ! CloseHub_MCP_Token::is_configured(), 'Revoking a shared token must invalidate it immediately.' );
closehub_test_assert( null === CloseHub_MCP_Token::authenticate( $token ), 'A revoked shared token must not authenticate.' );

$GLOBALS['closehub_test_multisite'] = true;
$network_token = CloseHub_MCP_Token::generate( 8 );
closehub_test_assert( is_string( $network_token ), 'A network shared token must be generated.' );
closehub_test_assert( isset( $GLOBALS['closehub_test_site_options']['closehub_mcp_shared_token'] ), 'Multisite must store the shared token as a network option.' );
closehub_test_assert( 8 === CloseHub_MCP_Token::authenticate( $network_token ), 'The network shared token must authenticate as its mapped user.' );
CloseHub_MCP_Token::revoke();
closehub_test_assert( ! isset( $GLOBALS['closehub_test_site_options']['closehub_mcp_shared_token'] ), 'Revocation must remove the network option.' );

echo "MCP shared token checks passed.\n";
