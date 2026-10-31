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
	public function __construct( private array $params = [], private array $files = [] ) {}
	public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
	public function get_file_params(): array { return $this->files; }
}
class CloseHub_REST_API {
	const NAMESPACE = 'closehub/v1';
}
class CloseHub_Site_Abilities {
	public static function can_view_package( $input ): bool { return current_user_can( 'theme' === $input['type'] ? 'switch_themes' : 'activate_plugins' ); }
	public static function can_replace_package( $input ): bool { return current_user_can( 'update_plugins' ); }
	public static function get_installed_version( $input ): array { return [ 'slug' => $input['slug'], 'version' => '1.1.2' ]; }
}
class CloseHub_Package_Installer {
	public const TYPES = [ 'plugin', 'theme' ];
	public static array $calls = [];
	public static function install( string $type, string $zip, array $options = [] ): array { self::$calls[] = [ $type, $zip, $options ]; return [ 'slug' => $options['slug'], 'new_version' => '1.1.2' ]; }
	public static function rollback( string $type, string $slug, string $backup = '' ): array { self::$calls[] = [ 'rollback', $type, $slug, $backup ]; return [ 'restored_backup' => $backup ]; }
	public static function valid_slug( string $slug ): bool { return (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $slug ) && ! str_contains( $slug, '..' ); }
}

$GLOBALS['closehub_test_logged_in'] = true;
$GLOBALS['closehub_test_caps']      = [ 'read', 'install_themes', 'install_plugins', 'update_plugins', 'switch_themes' ];

function is_user_logged_in(): bool { return $GLOBALS['closehub_test_logged_in']; }
function current_user_can( string $capability ): bool { return in_array( $capability, $GLOBALS['closehub_test_caps'], true ); }
function is_wp_error( mixed $thing ): bool { return $thing instanceof WP_Error; }
function rest_ensure_response( $data ): WP_REST_Response { return new WP_REST_Response( $data ); }
function wp_max_upload_size(): int { return 2048; }
function size_format( int $bytes ): string { return $bytes . ' B'; }

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

// ── Permissions: application password user with the right capabilities ────
if ( true !== $rest->can_install( new WP_REST_Request( $params ) ) ) { $fail( 'A user with install_themes may install themes.' ); }
$GLOBALS['closehub_test_logged_in'] = false;
$expect( $rest->can_install( new WP_REST_Request( $params ) ), 'closehub_unauthorized', 401, 'an anonymous request' );
$expect( $rest->can_view( new WP_REST_Request( $params ) ), 'closehub_unauthorized', 401, 'an anonymous version check' );
$GLOBALS['closehub_test_logged_in'] = true;
$GLOBALS['closehub_test_caps']      = [ 'read' ];
$expect( $rest->can_install( new WP_REST_Request( $params ) ), 'closehub_forbidden', 403, 'a user without install_themes' );
$expect( $rest->can_rollback( new WP_REST_Request( $params ) ), 'closehub_forbidden', 403, 'a rollback without update caps' );
$expect( $rest->can_view( new WP_REST_Request( $params ) ), 'closehub_forbidden', 403, 'a version check without switch_themes' );
$GLOBALS['closehub_test_caps'] = [ 'read', 'install_themes', 'install_plugins', 'update_plugins', 'switch_themes' ];

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

$rest->rollback( new WP_REST_Request( [ 'type' => 'theme', 'slug' => 'close-reclamafacturas-theme', 'backup' => '' ] ) );
if ( [ 'rollback', 'theme', 'close-reclamafacturas-theme', '' ] !== CloseHub_Package_Installer::$calls[1] ) { $fail( 'Rollback must forward type, slug and backup.' ); }
$version = $rest->version( new WP_REST_Request( [ 'type' => 'theme', 'slug' => 'close-reclamafacturas-theme' ] ) );
if ( '1.1.2' !== $version->data['version'] ) { $fail( 'The version route must return the installed version.' ); }

echo "Package REST checks passed.\n";
