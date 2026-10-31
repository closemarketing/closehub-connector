<?php

declare( strict_types=1 );

// WP's admin includes are stubbed out as empty files in a throwaway ABSPATH.
$abspath = sys_get_temp_dir() . '/closehub-zip-test-' . getmypid() . '/';
mkdir( $abspath . 'wp-admin/includes', 0777, true );
foreach ( [ 'file', 'plugin', 'theme', 'class-wp-upgrader' ] as $include ) { file_put_contents( $abspath . "wp-admin/includes/{$include}.php", "<?php\n" ); }
register_shutdown_function( static function () use ( $abspath ): void {
	foreach ( glob( $abspath . 'wp-admin/includes/*.php' ) as $file ) { unlink( $file ); }
	rmdir( $abspath . 'wp-admin/includes' ); rmdir( $abspath . 'wp-admin' ); rmdir( $abspath );
} );
define( 'ABSPATH', $abspath );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
	public function get_error_message(): string { return $this->message; }
	public function has_errors(): bool { return '' !== $this->code; }
}
class Automatic_Upgrader_Skin {
	public function get_errors() { return new WP_Error(); }
	public function get_upgrade_messages(): array { return []; }
}
class WP_Theme_Stub {
	public function get_stylesheet(): string { return 'my-theme'; }
	public function get( string $key ): string { return 'Name' === $key ? 'My Theme' : '2.0.0'; }
}
class Plugin_Upgrader {
	public function __construct( public $skin ) {}
	public function install( string $package, array $args = [] ) {
		$GLOBALS['closehub_test_install_args'] = [ 'file' => file_get_contents( $package ), 'args' => $args ];
		return $GLOBALS['closehub_test_install_result'];
	}
	public function plugin_info(): string { return 'my-plugin/my-plugin.php'; }
}
class Theme_Upgrader extends Plugin_Upgrader {
	public function theme_info() { return new WP_Theme_Stub(); }
}

$GLOBALS['closehub_test_caps']           = [ 'activate_plugins', 'switch_themes' ];
$GLOBALS['closehub_test_file_mods']      = true;
$GLOBALS['closehub_test_max']            = 1024;
$GLOBALS['closehub_test_install_result'] = true;
$GLOBALS['closehub_test_install_args']   = null;
$GLOBALS['closehub_test_plugins']        = [ 'my-plugin/my-plugin.php' => [ 'Name' => 'My Plugin', 'Version' => '1.0.0' ] ];
$GLOBALS['closehub_test_active']         = [];
$GLOBALS['closehub_test_stylesheet']     = 'twentytwentyfive';

function current_user_can( string $capability, ...$args ): bool { return in_array( $capability, $GLOBALS['closehub_test_caps'], true ); }
function is_wp_error( mixed $thing ): bool { return $thing instanceof WP_Error; }
function wp_is_file_mod_allowed( string $context ): bool { return $GLOBALS['closehub_test_file_mods']; }
function sanitize_file_name( string $name ): string { return preg_replace( '/[^A-Za-z0-9._-]/', '', $name ); }
function wp_max_upload_size(): int { return $GLOBALS['closehub_test_max']; }
function wp_tempnam( string $name ): string { return tempnam( sys_get_temp_dir(), 'chz' ); }
function wp_delete_file( string $path ): void { @unlink( $path ); }
function get_plugins(): array { return $GLOBALS['closehub_test_plugins']; }
function wp_clean_plugins_cache( bool $clear ): void {}
function is_plugin_active( string $file ): bool { return in_array( $file, $GLOBALS['closehub_test_active'], true ); }
function activate_plugin( string $file ) { $GLOBALS['closehub_test_active'][] = $file; return null; }
function get_stylesheet(): string { return $GLOBALS['closehub_test_stylesheet']; }
function switch_theme( string $stylesheet ): void { $GLOBALS['closehub_test_stylesheet'] = $stylesheet; }

require_once dirname( __DIR__ ) . '/includes/class-site-abilities.php';

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

$GLOBALS['closehub_test_file_mods'] = false;
$result = CloseHub_Site_Abilities::install_plugin_zip( $ok );
if ( ! $result instanceof WP_Error || 'closehub_file_mods_disabled' !== $result->code ) { $fail( 'DISALLOW_FILE_MODS must block zip installs.' ); }
$GLOBALS['closehub_test_file_mods'] = true;

// Plugin replace: overwrite defaults to true, the zip bytes reach the upgrader, replaced is reported.
$result = CloseHub_Site_Abilities::install_plugin_zip( $ok );
if ( $result instanceof WP_Error ) { $fail( 'A valid plugin zip should install.' ); }
if ( true !== ( $GLOBALS['closehub_test_install_args']['args']['overwrite_package'] ?? null ) ) { $fail( 'overwrite must default to true.' ); }
if ( $zip !== $GLOBALS['closehub_test_install_args']['file'] ) { $fail( 'The decoded zip must reach the upgrader unchanged.' ); }
if ( true !== $result['replaced'] || '1.0.0' !== $result['previous_version'] || false !== $result['activated'] ) { $fail( 'Replacing an installed, inactive plugin was reported wrongly.' ); }

CloseHub_Site_Abilities::install_plugin_zip( $ok + [ 'overwrite' => false ] );
if ( false !== $GLOBALS['closehub_test_install_args']['args']['overwrite_package'] ) { $fail( 'overwrite=false must be forwarded.' ); }

$result = CloseHub_Site_Abilities::install_plugin_zip( $ok + [ 'activate' => true ] );
if ( true !== $result['activated'] ) { $fail( 'activate=true must activate the plugin.' ); }

$GLOBALS['closehub_test_caps'] = [];
$result = CloseHub_Site_Abilities::install_plugin_zip( $ok + [ 'activate' => true ] );
if ( ! $result instanceof WP_Error || 'closehub_forbidden' !== $result->code ) { $fail( 'Activating without activate_plugins must be rejected.' ); }
$result = CloseHub_Site_Abilities::install_theme_zip( $ok + [ 'activate' => true ] );
if ( ! $result instanceof WP_Error || 'closehub_forbidden' !== $result->code ) { $fail( 'Activating a theme without switch_themes must be rejected.' ); }
if ( CloseHub_Site_Abilities::can_install_themes() || CloseHub_Site_Abilities::can_install_plugins() ) { $fail( 'Permission callbacks must require install_themes / install_plugins.' ); }
$GLOBALS['closehub_test_caps'] = [ 'activate_plugins', 'switch_themes' ];

// Upgrader failure surfaces as an error.
$GLOBALS['closehub_test_install_result'] = new WP_Error( 'folder_exists', 'Destination folder already exists.' );
$result = CloseHub_Site_Abilities::install_plugin_zip( $ok + [ 'overwrite' => false ] );
if ( ! $result instanceof WP_Error || 'folder_exists' !== $result->code ) { $fail( 'An upgrader error must be returned as is.' ); }
$GLOBALS['closehub_test_install_result'] = false;
$result = CloseHub_Site_Abilities::install_theme_zip( $ok );
if ( ! $result instanceof WP_Error || 'closehub_theme_zip_install_failed' !== $result->code ) { $fail( 'A falsy upgrader result must be an error.' ); }
$GLOBALS['closehub_test_install_result'] = true;

// Theme install + activate.
$result = CloseHub_Site_Abilities::install_theme_zip( $ok + [ 'activate' => true ] );
if ( $result instanceof WP_Error || 'my-theme' !== $result['stylesheet'] || '2.0.0' !== $result['version'] || true !== $result['activated'] ) { $fail( 'Theme zip install/activate result is wrong.' ); }

echo "Zip install ability checks passed.\n";
