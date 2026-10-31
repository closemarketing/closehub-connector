<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
}
/** Records what the abilities hand to the installer; its own behaviour is covered by package-installer-test.php. */
class CloseHub_Package_Installer {
	public const TYPES = [ 'plugin', 'theme' ];
	public static array $calls = [];
	public static function install( string $type, string $zip, array $options = [] ): array {
		self::$calls[] = [ 'install', $type, file_get_contents( $zip ), $options, $zip ];
		return [ 'type' => $type, 'slug' => 'my-plugin' ];
	}
	public static function rollback( string $type, string $slug, string $backup = '' ): array {
		self::$calls[] = [ 'rollback', $type, $slug, $backup ];
		return [ 'restored_backup' => $backup ];
	}
	public static function installed( string $type, string $slug ): ?array { return 'my-plugin' === $slug ? [ 'slug' => $slug, 'name' => 'My Plugin', 'version' => '1.0.0', 'active' => true ] : null; }
	public static function backups( string $type, string $slug ): array { return [ [ 'file' => '0.9.0--20261031-120000-abc.zip', 'version' => '0.9.0' ] ]; }
	public static function valid_slug( string $slug ): bool { return (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $slug ) && ! str_contains( $slug, '..' ); }
}

$GLOBALS['closehub_test_caps']     = [];
$GLOBALS['closehub_test_file_mods'] = true;

function current_user_can( string $capability, ...$args ): bool { return in_array( $capability, $GLOBALS['closehub_test_caps'], true ); }
function is_wp_error( mixed $thing ): bool { return $thing instanceof WP_Error; }
function wp_is_file_mod_allowed( string $context ): bool { return $GLOBALS['closehub_test_file_mods']; }
function sanitize_file_name( string $name ): string { return preg_replace( '/[^A-Za-z0-9._-]/', '', $name ); }
function wp_max_upload_size(): int { return 1024; }
function wp_tempnam( string $name ): string { return tempnam( sys_get_temp_dir(), 'chz' ); }
function wp_delete_file( string $path ): void { @unlink( $path ); }
function get_current_user_id(): int { return 7; }
function rest_url( string $path ): string { return 'https://example.com/wp-json/' . $path; }
function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
class CloseHub_REST_API {
	const NAMESPACE = 'closehub/v1';
}
class CloseHub_Deploy_Ticket {
	public const HEADER = 'X-CloseHub-Deploy-Ticket';
	public static array $issued = [];
	public static function issue( int $user_id, string $type, string $slug ): array { self::$issued[] = [ $user_id, $type, $slug ]; return [ 'ticket' => 'abc', 'expires_in' => 900 ]; }
}

require_once dirname( __DIR__ ) . '/includes/class-site-abilities.php';
// write_zip_to_temp_file() loads wp-admin/includes/file.php for wp_tempnam(), stubbed above.
@mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
@touch( ABSPATH . 'wp-admin/includes/file.php' );
register_shutdown_function( static function (): void { @unlink( ABSPATH . 'wp-admin/includes/file.php' ); @rmdir( ABSPATH . 'wp-admin/includes' ); @rmdir( ABSPATH . 'wp-admin' ); } );

$fail = static function ( string $message ): void { fwrite( STDERR, $message . "\n" ); exit( 1 ); };
$zip  = "PK\x03\x04fake-zip-bytes";
$ok   = [ 'filename' => 'my-plugin.zip', 'data_base64' => base64_encode( $zip ) ];

foreach ( [ 'install_plugin_zip', 'install_theme_zip' ] as $method ) {
	foreach ( [
		'missing filename'  => [ [ 'data_base64' => $ok['data_base64'] ], 'closehub_missing_filename' ],
		'missing data'      => [ [ 'filename' => 'a.zip' ], 'closehub_missing_data_base64' ],
		'not a zip name'    => [ [ 'filename' => 'a.php', 'data_base64' => $ok['data_base64'] ], 'closehub_invalid_filename' ],
		'bad base64'        => [ [ 'filename' => 'a.zip', 'data_base64' => '***' ], 'closehub_invalid_data_base64' ],
		'not a zip payload' => [ [ 'filename' => 'a.zip', 'data_base64' => base64_encode( '<?php echo 1;' ) ], 'closehub_invalid_zip' ],
		'too large'         => [ [ 'filename' => 'a.zip', 'data_base64' => base64_encode( "PK\x03\x04" . str_repeat( 'x', 2000 ) ) ], 'closehub_zip_too_large' ],
	] as $label => [ $input, $code ] ) {
		$result = CloseHub_Site_Abilities::$method( $input );
		if ( ! $result instanceof WP_Error || $code !== $result->code ) { $fail( "{$method}: expected {$code} for {$label}." ); }
	}
}
if ( CloseHub_Package_Installer::$calls ) { $fail( 'Invalid payloads must not reach the installer.' ); }

$GLOBALS['closehub_test_file_mods'] = false;
$result = CloseHub_Site_Abilities::install_plugin_zip( $ok );
if ( ! $result instanceof WP_Error || 'closehub_file_mods_disabled' !== $result->code ) { $fail( 'DISALLOW_FILE_MODS must block zip installs.' ); }
$GLOBALS['closehub_test_file_mods'] = true;

// The decoded zip reaches the installer with the right type and options, and the temp file is removed.
CloseHub_Site_Abilities::install_theme_zip( $ok + [ 'slug' => 'my-theme', 'activate' => true ] );
[ , $type, $contents, $options, $path ] = CloseHub_Package_Installer::$calls[0];
if ( 'theme' !== $type || $zip !== $contents ) { $fail( 'The decoded zip must reach the installer as a theme.' ); }
if ( [ 'slug' => 'my-theme', 'overwrite' => true, 'activate' => true ] !== $options ) { $fail( 'slug, overwrite (default true) and activate must be forwarded.' ); }
if ( file_exists( $path ) ) { $fail( 'The temporary zip must be deleted.' ); }
CloseHub_Site_Abilities::install_plugin_zip( $ok + [ 'overwrite' => false ] );
if ( 'plugin' !== CloseHub_Package_Installer::$calls[1][1] || false !== CloseHub_Package_Installer::$calls[1][3]['overwrite'] ) { $fail( 'overwrite=false must be forwarded for plugins.' ); }

// get-installed-version
$version = CloseHub_Site_Abilities::get_installed_version( [ 'type' => 'plugin', 'slug' => 'my-plugin' ] );
if ( true !== $version['installed'] || '1.0.0' !== $version['version'] || 1 !== count( $version['backups'] ) ) { $fail( 'get-installed-version should report version and backups.' ); }
$missing = CloseHub_Site_Abilities::get_installed_version( [ 'type' => 'plugin', 'slug' => 'nope' ] );
if ( false !== $missing['installed'] || isset( $missing['version'] ) ) { $fail( 'A missing plugin must report installed=false.' ); }
$invalid = CloseHub_Site_Abilities::get_installed_version( [ 'type' => 'plugin', 'slug' => '../x' ] );
if ( ! $invalid instanceof WP_Error ) { $fail( 'An unsafe slug must be rejected.' ); }

// rollback-package
CloseHub_Site_Abilities::rollback_package( [ 'type' => 'theme', 'slug' => 'my-theme', 'backup' => 'b.zip' ] );
if ( [ 'rollback', 'theme', 'my-theme', 'b.zip' ] !== CloseHub_Package_Installer::$calls[2] ) { $fail( 'rollback-package must forward type, slug and backup.' ); }

// create-deploy-ticket: bound to the current user, type and slug, pointing at the upload route.
$ticket = CloseHub_Site_Abilities::create_deploy_ticket( [ 'type' => 'theme', 'slug' => 'my-theme' ] );
if ( [ [ 7, 'theme', 'my-theme' ] ] !== CloseHub_Deploy_Ticket::$issued ) { $fail( 'The ticket must be issued for the current user, type and slug.' ); }
if ( 'abc' !== $ticket['ticket'] || 'https://example.com/wp-json/closehub/v1/packages/install?type=theme&slug=my-theme' !== $ticket['upload_url'] || 'X-CloseHub-Deploy-Ticket' !== $ticket['header'] ) { $fail( 'create-deploy-ticket must return the ticket, upload URL and header.' ); }
if ( ! CloseHub_Site_Abilities::create_deploy_ticket( [ 'type' => 'theme', 'slug' => '../x' ] ) instanceof WP_Error ) { $fail( 'No ticket for an unsafe slug.' ); }
$GLOBALS['closehub_test_file_mods'] = false;
if ( ! CloseHub_Site_Abilities::create_deploy_ticket( [ 'type' => 'theme', 'slug' => 'my-theme' ] ) instanceof WP_Error || 1 !== count( CloseHub_Deploy_Ticket::$issued ) ) { $fail( 'No ticket when file mods are disabled.' ); }
$GLOBALS['closehub_test_file_mods'] = true;

// Permission callbacks.
if ( CloseHub_Site_Abilities::can_install_plugins() || CloseHub_Site_Abilities::can_install_themes() || CloseHub_Site_Abilities::can_install_package( [ 'type' => 'theme' ] ) || CloseHub_Site_Abilities::can_view_package( [ 'type' => 'theme' ] ) || CloseHub_Site_Abilities::can_replace_package( [ 'type' => 'plugin' ] ) ) { $fail( 'Nothing must be allowed without capabilities.' ); }
$GLOBALS['closehub_test_caps'] = [ 'install_themes', 'switch_themes' ];
if ( ! CloseHub_Site_Abilities::can_install_package( [ 'type' => 'theme' ] ) || CloseHub_Site_Abilities::can_install_package( [ 'type' => 'plugin' ] ) ) { $fail( 'A deploy ticket needs install_* for its type.' ); }
if ( ! CloseHub_Site_Abilities::can_view_package( [ 'type' => 'theme' ] ) || CloseHub_Site_Abilities::can_replace_package( [ 'type' => 'theme' ] ) ) { $fail( 'Rolling back a theme needs update_themes too.' ); }
$GLOBALS['closehub_test_caps'] = [ 'install_plugins', 'update_plugins' ];
if ( ! CloseHub_Site_Abilities::can_replace_package( [ 'type' => 'plugin' ] ) || CloseHub_Site_Abilities::can_view_package( [ 'type' => 'plugin' ] ) ) { $fail( 'Plugin permissions are wrong.' ); }

echo "Zip install ability checks passed.\n";
