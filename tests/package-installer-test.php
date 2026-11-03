<?php

declare( strict_types=1 );

// A throwaway WordPress layout: empty admin includes, plugins, themes, uploads.
$root = sys_get_temp_dir() . '/closehub-installer-test-' . getmypid();
foreach ( [ 'wp-admin/includes', 'plugins', 'themes', 'uploads', 'zips' ] as $dir ) { mkdir( "$root/$dir", 0777, true ); }
foreach ( [ 'file', 'plugin', 'theme', 'class-wp-upgrader' ] as $include ) { file_put_contents( "$root/wp-admin/includes/{$include}.php", "<?php\n" ); }
register_shutdown_function( static function () use ( $root ): void {
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $files as $file ) { $file->isDir() ? rmdir( (string) $file ) : unlink( (string) $file ); }
	rmdir( $root );
} );

define( 'ABSPATH', "$root/" );
define( 'WP_PLUGIN_DIR', "$root/plugins" );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function has_errors(): bool { return '' !== $this->code; }
}
class Automatic_Upgrader_Skin {
	public function get_errors() { return new WP_Error(); }
	public function get_upgrade_messages(): array { return [ 'Installing the package…' ]; }
}
/** Extracts into the destination like WP_Upgrader, honouring overwrite_package. */
class Plugin_Upgrader {
	public function __construct( public $skin ) {}
	protected function destination(): string { return WP_PLUGIN_DIR; }
	public function install( string $package, array $args = [] ) {
		if ( $GLOBALS['closehub_test_upgrader_fails'] ) { return new WP_Error( 'incompatible_archive', 'The package could not be installed.' ); }
		$zip = new ZipArchive();
		$zip->open( $package );
		$folder = $this->destination() . '/' . explode( '/', (string) $zip->getNameIndex( 0 ) )[0];
		if ( is_dir( $folder ) ) {
			if ( empty( $args['overwrite_package'] ) ) { return new WP_Error( 'folder_exists', 'Destination folder already exists.' ); }
			closehub_test_rmdir( $folder );
		}
		$zip->extractTo( $this->destination() );
		$zip->close();
		return true;
	}
}
class Theme_Upgrader extends Plugin_Upgrader {
	protected function destination(): string { return ABSPATH . 'themes'; }
}
class WP_Theme_Stub {
	public function __construct( private string $slug ) {}
	public function exists(): bool { return is_file( ABSPATH . "themes/{$this->slug}/style.css" ); }
	public function get( string $key ): string { return closehub_test_header( ABSPATH . "themes/{$this->slug}/style.css", 'Name' === $key ? 'Theme Name' : $key ); }
}

$GLOBALS['closehub_test_caps']            = [ 'install_plugins', 'update_plugins', 'activate_plugins', 'install_themes', 'update_themes', 'switch_themes' ];
$GLOBALS['closehub_test_file_mods']       = true;
$GLOBALS['closehub_test_upgrader_fails']  = false;
$GLOBALS['closehub_test_active_plugins']  = [];
$GLOBALS['closehub_test_stylesheet']      = 'twentytwentyfive';
$GLOBALS['closehub_test_rocket_cleared']  = 0;

function closehub_test_rmdir( string $dir ): void {
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $files as $file ) { $file->isDir() ? rmdir( (string) $file ) : unlink( (string) $file ); }
	rmdir( $dir );
}
function closehub_test_header( string $file, string $header ): string {
	return preg_match( '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':(.*)$/mi', (string) @file_get_contents( $file ), $m ) ? trim( $m[1] ) : '';
}
function current_user_can( string $capability, ...$args ): bool { return in_array( $capability, $GLOBALS['closehub_test_caps'], true ); }
function is_wp_error( mixed $thing ): bool { return $thing instanceof WP_Error; }
function wp_is_file_mod_allowed( string $context ): bool { return $GLOBALS['closehub_test_file_mods']; }
function wp_strip_all_tags( string $text ): string { return strip_tags( $text ); }
function wp_upload_dir( $time = null, bool $create = true ): array { return [ 'basedir' => ABSPATH . 'uploads' ]; }
function untrailingslashit( string $value ): string { return rtrim( $value, '/\\' ); }
function wp_mkdir_p( string $dir ): bool { return is_dir( $dir ) || mkdir( $dir, 0777, true ); }
function wp_generate_password( int $length, bool $special ): string { return substr( str_shuffle( 'abcdefghijklmnopqrstuvwxyz0123456789' ), 0, $length ); }
function wp_tempnam( string $name ): string { return tempnam( sys_get_temp_dir(), 'chi' ); }
function wp_delete_file( string $path ): void { @unlink( $path ); }
function get_theme_root( string $slug ): string { return ABSPATH . 'themes'; }
function wp_clean_plugins_cache( bool $clear ): void {}
function wp_clean_themes_cache(): void {}
function get_plugins(): array {
	$plugins = [];
	foreach ( glob( WP_PLUGIN_DIR . '/*/*.php' ) as $file ) {
		$name = closehub_test_header( $file, 'Plugin Name' );
		if ( $name ) { $plugins[ basename( dirname( $file ) ) . '/' . basename( $file ) ] = [ 'Name' => $name, 'Version' => closehub_test_header( $file, 'Version' ) ]; }
	}
	return $plugins;
}
function is_plugin_active( string $file ): bool { return in_array( $file, $GLOBALS['closehub_test_active_plugins'], true ); }
function is_multisite(): bool { return false; }
function activate_plugin( string $file ) { $GLOBALS['closehub_test_active_plugins'][] = $file; return null; }
function wp_get_theme( string $slug ): WP_Theme_Stub { return new WP_Theme_Stub( $slug ); }
function get_stylesheet(): string { return $GLOBALS['closehub_test_stylesheet']; }
function get_template(): string { return $GLOBALS['closehub_test_stylesheet']; }
function switch_theme( string $slug ): void { $GLOBALS['closehub_test_stylesheet'] = $slug; }
function rocket_clean_domain(): void { ++$GLOBALS['closehub_test_rocket_cleared']; }
function did_action( string $hook ): int { return 0; }

require_once dirname( __DIR__ ) . '/includes/class-site-abilities.php';
require_once dirname( __DIR__ ) . '/includes/class-package-installer.php';

$fail = static function ( string $message ): void { fwrite( STDERR, $message . "\n" ); exit( 1 ); };

/** Build a zip in the temp layout. $files maps the path inside the zip to its contents. */
$make_zip = static function ( string $name, array $files ) use ( $root ): string {
	$path = "$root/zips/$name";
	$zip  = new ZipArchive();
	$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	foreach ( $files as $file => $contents ) { $zip->addFromString( $file, $contents ); }
	$zip->close();
	return $path;
};
$plugin_zip = static fn( string $version, string $slug = 'my-plugin' ) => $make_zip( "$slug-$version.zip", [ "$slug/$slug.php" => "<?php\n/**\n * Plugin Name: My Plugin\n * Version: $version\n */\n", "$slug/readme.txt" => $version ] );
$theme_zip  = static fn( string $version ) => $make_zip( "my-theme-$version.zip", [ 'my-theme/style.css' => "/*\nTheme Name: My Theme\nVersion: $version\n*/\n", 'my-theme/index.php' => '<?php' ] );
$expect_error = static function ( $result, string $code, string $label ) use ( $fail ): void {
	if ( ! $result instanceof WP_Error || $code !== $result->code ) { $fail( "Expected {$code} for {$label}, got " . ( $result instanceof WP_Error ? $result->code : 'success' ) . '.' ); }
};

// ── zip_root: structure checks ─────────────────────────────────────────────
if ( 'my-plugin' !== CloseHub_Package_Installer::zip_root( $plugin_zip( '1.0.0' ), 'plugin' ) ) { $fail( 'A plugin zip should report its folder.' ); }
$expect_error( CloseHub_Package_Installer::zip_root( $plugin_zip( '1.0.0' ), 'theme' ), 'closehub_package_invalid_structure', 'a plugin sent as a theme' );
$expect_error( CloseHub_Package_Installer::zip_root( $make_zip( 'two.zip', [ 'a/a.php' => '<?php', 'b/b.php' => '<?php' ] ), 'plugin' ), 'closehub_package_invalid_structure', 'two root folders' );
$expect_error( CloseHub_Package_Installer::zip_root( $make_zip( 'loose.zip', [ 'loose.php' => '<?php' ] ), 'plugin' ), 'closehub_package_invalid_structure', 'a file at the zip root' );
$expect_error( CloseHub_Package_Installer::zip_root( $make_zip( 'evil.zip', [ 'my-plugin/../../evil.php' => '<?php' ] ), 'plugin' ), 'closehub_invalid_zip', 'a path traversal entry' );
$expect_error( CloseHub_Package_Installer::zip_root( $make_zip( 'not-a.zip', [] ) . '.missing', 'plugin' ), 'closehub_invalid_zip', 'a file that is not a zip' );
if ( 'my-plugin' !== CloseHub_Package_Installer::zip_root( $make_zip( 'mac.zip', [ 'my-plugin/my-plugin.php' => '<?php', '__MACOSX/my-plugin/._my-plugin.php' => '' ] ), 'plugin' ) ) { $fail( '__MACOSX metadata must be ignored.' ); }

// ── Fresh install ──────────────────────────────────────────────────────────
$result = CloseHub_Package_Installer::install( 'plugin', $plugin_zip( '1.0.0' ), [ 'slug' => 'my-plugin' ] );
if ( $result instanceof WP_Error ) { $fail( 'Fresh install failed: ' . $result->message ); }
if ( false !== $result['replaced'] || null !== $result['previous_version'] || '1.0.0' !== $result['new_version'] || null !== $result['backup'] || false !== $result['active'] ) { $fail( 'Fresh install result is wrong.' ); }
if ( 1 !== $GLOBALS['closehub_test_rocket_cleared'] || ! array_key_exists( 'opcache', $result['cache'] ) ) { $fail( 'Caches must be cleared after installing.' ); }

// ── Guards ─────────────────────────────────────────────────────────────────
$expect_error( CloseHub_Package_Installer::install( 'plugin', $plugin_zip( '2.0.0' ), [ 'slug' => 'other-plugin' ] ), 'closehub_package_slug_mismatch', 'a zip for another plugin' );
$expect_error( CloseHub_Package_Installer::install( 'plugin', $plugin_zip( '2.0.0' ), [ 'slug' => '../x' ] ), 'closehub_package_invalid_slug', 'an unsafe slug' );
$expect_error( CloseHub_Package_Installer::install( 'widget', $plugin_zip( '2.0.0' ) ), 'closehub_package_invalid_type', 'an unknown type' );
$expect_error( CloseHub_Package_Installer::install( 'plugin', $plugin_zip( '2.0.0' ), [ 'overwrite' => false ] ), 'closehub_package_exists', 'overwrite=false on an installed plugin' );
$GLOBALS['closehub_test_caps'] = [ 'install_plugins' ];
$expect_error( CloseHub_Package_Installer::install( 'plugin', $plugin_zip( '2.0.0' ) ), 'closehub_forbidden', 'replacing without update_plugins' );
$expect_error( CloseHub_Package_Installer::install( 'plugin', $plugin_zip( '1.0.0', 'new-plugin' ), [ 'activate' => true ] ), 'closehub_forbidden', 'activating without activate_plugins' );
$GLOBALS['closehub_test_caps'] = [ 'install_plugins', 'update_plugins', 'activate_plugins', 'install_themes', 'update_themes', 'switch_themes' ];
$GLOBALS['closehub_test_file_mods'] = false;
$expect_error( CloseHub_Package_Installer::install( 'plugin', $plugin_zip( '2.0.0' ) ), 'closehub_file_mods_disabled', 'DISALLOW_FILE_MODS' );
$GLOBALS['closehub_test_file_mods'] = true;
if ( '1.0.0' !== CloseHub_Package_Installer::installed( 'plugin', 'my-plugin' )['version'] ) { $fail( 'Rejected installs must not touch the installed copy.' ); }

// ── Replace: backup first, active plugin stays active ──────────────────────
$GLOBALS['closehub_test_active_plugins'] = [ 'my-plugin/my-plugin.php' ];
$result = CloseHub_Package_Installer::install( 'plugin', $plugin_zip( '2.0.0' ), [ 'slug' => 'my-plugin' ] );
if ( $result instanceof WP_Error ) { $fail( 'Replace failed: ' . $result->message ); }
if ( true !== $result['replaced'] || '1.0.0' !== $result['previous_version'] || '2.0.0' !== $result['new_version'] || true !== $result['active'] ) { $fail( 'Replace result is wrong.' ); }
$backups = CloseHub_Package_Installer::backups( 'plugin', 'my-plugin' );
if ( 1 !== count( $backups ) || $result['backup'] !== $backups[0]['file'] || '1.0.0' !== $backups[0]['version'] ) { $fail( 'The replaced copy must be backed up with its version.' ); }
if ( 'my-plugin' !== CloseHub_Package_Installer::zip_root( CloseHub_Package_Installer::backup_dir( 'plugin', 'my-plugin' ) . '/' . $backups[0]['file'], 'plugin' ) ) { $fail( 'A backup must be an installable zip of the same folder.' ); }
if ( ! is_file( CloseHub_Package_Installer::backup_dir() . '/.htaccess' ) || ! is_file( CloseHub_Package_Installer::backup_dir( 'plugin', 'my-plugin' ) . '/index.php' ) ) { $fail( 'The backup folder must block direct access.' ); }

// ── Upgrader failure keeps the backup and reports the messages ─────────────
$GLOBALS['closehub_test_upgrader_fails'] = true;
$result = CloseHub_Package_Installer::install( 'plugin', $plugin_zip( '3.0.0' ) );
$expect_error( $result, 'incompatible_archive', 'an upgrader failure' );
if ( empty( $result->data['backup'] ) || [ 'Installing the package…' ] !== $result->data['upgrader_messages'] ) { $fail( 'An upgrader error must include the backup and upgrader messages.' ); }
$GLOBALS['closehub_test_upgrader_fails'] = false;

// ── Rollback ───────────────────────────────────────────────────────────────
$result = CloseHub_Package_Installer::rollback( 'plugin', 'my-plugin', $backups[0]['file'] );
if ( $result instanceof WP_Error || '1.0.0' !== $result['new_version'] || '2.0.0' !== $result['previous_version'] || $backups[0]['file'] !== $result['restored_backup'] ) { $fail( 'Rollback should reinstall the chosen backup.' ); }
$expect_error( CloseHub_Package_Installer::rollback( 'plugin', 'my-plugin', '../../wp-config.php' ), 'closehub_backup_not_found', 'a backup name outside the folder' );
$expect_error( CloseHub_Package_Installer::rollback( 'plugin', 'never-installed' ), 'closehub_backup_not_found', 'a plugin without backups' );

// ── Only the last BACKUPS_TO_KEEP backups are kept ─────────────────────────
foreach ( [ '4.0.0', '5.0.0', '6.0.0', '7.0.0', '8.0.0' ] as $version ) {
	CloseHub_Package_Installer::install( 'plugin', $plugin_zip( $version ) );
}
if ( CloseHub_Package_Installer::BACKUPS_TO_KEEP !== count( CloseHub_Package_Installer::backups( 'plugin', 'my-plugin' ) ) ) { $fail( 'Old backups must be pruned.' ); }

// ── Themes ─────────────────────────────────────────────────────────────────
$result = CloseHub_Package_Installer::install( 'theme', $theme_zip( '1.1.2' ), [ 'slug' => 'my-theme', 'activate' => true ] );
if ( $result instanceof WP_Error || '1.1.2' !== $result['new_version'] || true !== $result['active'] || 'my-theme' !== get_stylesheet() ) { $fail( 'Theme install and activate failed.' ); }
$result = CloseHub_Package_Installer::install( 'theme', $theme_zip( '1.1.3' ), [ 'slug' => 'my-theme', 'clear_cache' => false ] );
if ( $result instanceof WP_Error || '1.1.2' !== $result['previous_version'] || isset( $result['cache'] ) || 1 !== count( CloseHub_Package_Installer::backups( 'theme', 'my-theme' ) ) ) { $fail( 'Theme replace failed.' ); }

echo "Package installer checks passed.\n";
