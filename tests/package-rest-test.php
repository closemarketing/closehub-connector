<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
}
class WP_REST_Response {
	public function __construct( public mixed $data = null ) {}
}
class WP_REST_Request {
	public function __construct( private array $params = [], private array $files = [], private array $headers = [] ) {}
	public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
	public function get_file_params(): array { return $this->files; }
	public function get_header( string $key ): ?string { return $this->headers[ strtolower( $key ) ] ?? null; }
}
class CloseHub_REST_API {
	const NAMESPACE = 'closehub/v1';
}
class CloseHub_Package_Installer {
	public const TYPES = [ 'plugin', 'theme' ];
	public static array $calls = [];
	public static function install( string $type, string $zip, array $options = [] ): array { self::$calls[] = [ $type, $zip, $options ]; return [ 'slug' => $options['slug'], 'new_version' => '1.1.2' ]; }
	public static function rollback( string $type, string $slug, string $backup = '' ): array { self::$calls[] = [ 'rollback', $type, $slug, $backup ]; return [ 'restored_backup' => $backup ]; }
	public static function valid_slug( string $slug ): bool { return (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $slug ) && ! str_contains( $slug, '..' ); }
}

$GLOBALS['closehub_test_user']       = 0;
$GLOBALS['closehub_test_caps']       = [ 7 => [ 'install_themes' ] ];
$GLOBALS['closehub_test_transients'] = [];

function wp_set_current_user( int $id ): void { $GLOBALS['closehub_test_user'] = $id; }
function current_user_can( string $capability ): bool { return in_array( $capability, $GLOBALS['closehub_test_caps'][ $GLOBALS['closehub_test_user'] ] ?? [], true ); }
function set_transient( string $key, $value, int $ttl ): bool { $GLOBALS['closehub_test_transients'][ $key ] = $value; return true; }
function get_transient( string $key ) { return $GLOBALS['closehub_test_transients'][ $key ] ?? false; }
function delete_transient( string $key ): bool { $found = isset( $GLOBALS['closehub_test_transients'][ $key ] ); unset( $GLOBALS['closehub_test_transients'][ $key ] ); return $found; }
function is_wp_error( mixed $thing ): bool { return $thing instanceof WP_Error; }
function rest_ensure_response( $data ): WP_REST_Response { return new WP_REST_Response( $data ); }
function wp_max_upload_size(): int { return 2048; }
function size_format( int $bytes ): string { return $bytes . ' B'; }

require_once dirname( __DIR__ ) . '/includes/class-deploy-ticket.php';
require_once dirname( __DIR__ ) . '/includes/class-package-rest.php';

/** Stands in for PHP's upload handling (only real HTTP uploads pass it) and its php.ini limits. */
class Test_Package_REST extends CloseHub_Package_REST {
	protected function is_http_upload( string $path ): bool { return str_starts_with( $path, '/tmp/php' ); }
	protected function ini_bytes( string $setting ): int { return 1000; }
}

$fail   = static function ( string $message ): void { fwrite( STDERR, $message . "\n" ); exit( 1 ); };
$rest   = new Test_Package_REST();
$params = [ 'type' => 'theme', 'slug' => 'close-reclamafacturas-theme', 'overwrite' => true, 'activate' => false, 'clear_cache' => true ];
$file   = [ 'name' => 'close-reclamafacturas-theme-1.1.2.zip', 'tmp_name' => '/tmp/phpABC123', 'size' => 1000, 'error' => UPLOAD_ERR_OK ];
$expect = static function ( $result, string $code, int $status, string $label ) use ( $fail ): void {
	if ( ! $result instanceof WP_Error || $code !== $result->code || $status !== ( $result->data['status'] ?? 0 ) ) { $fail( "Expected {$code} ({$status}) for {$label}." ); }
};

// ── Ticket: single use, bound to user, type and slug ───────────────────────
$with_ticket = static fn ( string $ticket, array $p = [] ): WP_REST_Request => new WP_REST_Request( $p + $params, [], [ 'x-closehub-deploy-ticket' => $ticket ] );

$expect( $rest->authorize( new WP_REST_Request( $params ) ), 'closehub_deploy_ticket_missing', 401, 'no ticket' );
$expect( $rest->authorize( $with_ticket( 'not-a-ticket' ) ), 'closehub_deploy_ticket_missing', 401, 'a malformed ticket' );
$expect( $rest->authorize( $with_ticket( str_repeat( 'a', 64 ) ) ), 'closehub_deploy_ticket_invalid', 401, 'an unknown ticket' );

$issued = CloseHub_Deploy_Ticket::issue( 7, 'theme', 'close-reclamafacturas-theme' );
if ( ! preg_match( '/^[a-f0-9]{64}$/', $issued['ticket'] ) || 900 !== $issued['expires_in'] ) { $fail( 'A ticket is 64 hex chars and lasts 15 minutes.' ); }
if ( array_key_exists( 'closehub_deploy_ticket_' . $issued['ticket'], $GLOBALS['closehub_test_transients'] ) || str_contains( serialize( $GLOBALS['closehub_test_transients'] ), $issued['ticket'] ) ) { $fail( 'Only the ticket hash may be stored.' ); }
if ( true !== $rest->authorize( $with_ticket( $issued['ticket'] ) ) || 7 !== $GLOBALS['closehub_test_user'] ) { $fail( 'A valid ticket must act as the user it was issued to.' ); }
$expect( $rest->authorize( $with_ticket( $issued['ticket'] ) ), 'closehub_deploy_ticket_invalid', 401, 'a reused ticket' );

$other = CloseHub_Deploy_Ticket::issue( 7, 'theme', 'close-reclamafacturas-theme' );
$expect( $rest->authorize( $with_ticket( $other['ticket'], [ 'slug' => 'twentytwentyfive' ] ) ), 'closehub_deploy_ticket_mismatch', 403, 'a ticket for another slug' );
$expect( $rest->authorize( $with_ticket( $other['ticket'] ) ), 'closehub_deploy_ticket_invalid', 401, 'a ticket burned by a mismatch' );
$plugin = CloseHub_Deploy_Ticket::issue( 7, 'theme', 'my-plugin' );
$expect( $rest->authorize( $with_ticket( $plugin['ticket'], [ 'type' => 'plugin', 'slug' => 'my-plugin' ] ) ), 'closehub_deploy_ticket_mismatch', 403, 'a theme ticket used for a plugin' );

$expired = CloseHub_Deploy_Ticket::issue( 7, 'theme', 'close-reclamafacturas-theme' );
foreach ( $GLOBALS['closehub_test_transients'] as &$stored ) { $stored['expires'] = time() - 1; }
unset( $stored );
$expect( $rest->authorize( $with_ticket( $expired['ticket'] ) ), 'closehub_deploy_ticket_invalid', 401, 'an expired ticket' );

$demoted = CloseHub_Deploy_Ticket::issue( 8, 'theme', 'close-reclamafacturas-theme' );
$expect( $rest->authorize( $with_ticket( $demoted['ticket'] ) ), 'closehub_forbidden', 403, 'a user who lost install_themes' );

// ── Upload checks ──────────────────────────────────────────────────────────
$expect( $rest->install( new WP_REST_Request( [ 'type' => 'theme' ], [ 'file' => $file ] ) ), 'closehub_package_invalid_slug', 400, 'a missing slug' );
$expect( $rest->install( new WP_REST_Request( $params ) ), 'closehub_package_missing_file', 400, 'no file field' );
$_SERVER['CONTENT_LENGTH'] = 5000;
$too_large = $rest->install( new WP_REST_Request( $params ) );
$expect( $too_large, 'closehub_package_too_large', 413, 'a body over post_max_size' );
if ( ! str_contains( $too_large->message, 'post_max_size' ) ) { $fail( 'The error must name the limit that was hit.' ); }
unset( $_SERVER['CONTENT_LENGTH'] );
$expect( $rest->install( new WP_REST_Request( $params, [ 'file' => [ 'error' => UPLOAD_ERR_INI_SIZE ] + $file ] ) ), 'closehub_package_too_large', 413, 'upload_max_filesize exceeded' );
$expect( $rest->install( new WP_REST_Request( $params, [ 'file' => [ 'error' => UPLOAD_ERR_PARTIAL ] + $file ] ) ), 'closehub_package_upload_failed', 400, 'a partial upload' );
$expect( $rest->install( new WP_REST_Request( $params, [ 'file' => [ 'tmp_name' => '/etc/passwd' ] + $file ] ) ), 'closehub_package_upload_failed', 400, 'a file that was not uploaded' );
$expect( $rest->install( new WP_REST_Request( $params, [ 'file' => [ 'name' => 'theme.tar.gz' ] + $file ] ) ), 'closehub_invalid_filename', 400, 'a non-zip file' );
$expect( $rest->install( new WP_REST_Request( $params, [ 'file' => [ 'size' => 4096 ] + $file ] ) ), 'closehub_package_too_large', 413, 'a file over wp_max_upload_size' );
if ( CloseHub_Package_Installer::$calls ) { $fail( 'Rejected uploads must not reach the installer.' ); }

// ── Happy path: the uploaded temp file and flags reach the installer ───────
$response = $rest->install( new WP_REST_Request( [ 'activate' => true ] + $params, [ 'file' => $file ] ) );
if ( ! $response instanceof WP_REST_Response || '1.1.2' !== $response->data['new_version'] ) { $fail( 'A valid upload should return the installer result.' ); }
if ( [ 'theme', '/tmp/phpABC123', [ 'slug' => 'close-reclamafacturas-theme', 'overwrite' => true, 'activate' => true, 'clear_cache' => true ] ] !== CloseHub_Package_Installer::$calls[0] ) { $fail( 'The upload and its flags must be forwarded to the installer.' ); }

echo "Package REST checks passed.\n";
