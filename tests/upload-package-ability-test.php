<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
}

$GLOBALS['closehub_test_caps']       = [];
$GLOBALS['closehub_test_abilities']  = [];
$GLOBALS['closehub_test_max_bytes']  = 10 * 1024 * 1024;
$GLOBALS['closehub_test_deleted']    = [];

function current_user_can( string $capability, ...$args ): bool { return in_array( $capability, $GLOBALS['closehub_test_caps'], true ); }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ); }
function wp_register_ability_category( string $id, array $args ): bool { return true; }
function wp_register_ability( string $id, array $args ): bool { $GLOBALS['closehub_test_abilities'][ $id ] = $args; return true; }
function apply_filters( string $tag, mixed $value ): mixed { return 'closehub_upload_package_max_bytes' === $tag ? $GLOBALS['closehub_test_max_bytes'] : $value; }
function wp_max_upload_size(): int { return 50 * 1024 * 1024; }
function esc_url_raw( string $url ): string { return $url; }
function wp_parse_url( string $url, int $component ) { return parse_url( $url, $component ); } // phpcs:ignore WordPress.WP.DeprecatedFunctions.parse_url
function wp_tempnam( string $filename = '' ): string { return tempnam( sys_get_temp_dir(), 'closehub-test-' ); }
function wp_delete_file( string $path ): void { $GLOBALS['closehub_test_deleted'][] = $path; @unlink( $path ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
function download_url( string $url, int $timeout = 300 ) { return $GLOBALS['closehub_test_download_result'] ?? new WP_Error( 'http_request_failed', 'stubbed download_url without a fixture.' ); }
function get_file_data( string $file, array $headers ): array {
	$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents
	$result   = [];
	foreach ( $headers as $field => $regex ) {
		$result[ $field ] = preg_match( '/^[ \t\/*#@]*' . preg_quote( $regex, '/' ) . ':(.*)$/mi', (string) $contents, $matches ) ? trim( $matches[1] ) : '';
	}
	return $result;
}

require_once dirname( __DIR__ ) . '/includes/class-site-abilities.php';

// ── can_manage_package: plugin, no overwrite/activate needs only install_plugins ──
$GLOBALS['closehub_test_caps'] = [ 'install_plugins' ];
if ( ! CloseHub_Site_Abilities::can_manage_package( [ 'type' => 'plugin', 'overwrite' => false ] ) ) {
	fwrite( STDERR, "install_plugins alone should be enough for a non-overwriting, non-activating plugin upload.\n" ); exit( 1 );
}

// ── can_manage_package: plugin overwrite (default true) additionally needs update_plugins ──
if ( CloseHub_Site_Abilities::can_manage_package( [ 'type' => 'plugin' ] ) ) {
	fwrite( STDERR, "Overwriting a plugin without update_plugins should be denied.\n" ); exit( 1 );
}
$GLOBALS['closehub_test_caps'] = [ 'install_plugins', 'update_plugins' ];
if ( ! CloseHub_Site_Abilities::can_manage_package( [ 'type' => 'plugin' ] ) ) {
	fwrite( STDERR, "install_plugins + update_plugins should allow overwriting a plugin.\n" ); exit( 1 );
}

// ── can_manage_package: activate additionally needs activate_plugins/switch_themes ──
if ( CloseHub_Site_Abilities::can_manage_package( [ 'type' => 'plugin', 'overwrite' => false, 'activate' => true ] ) ) {
	fwrite( STDERR, "Activating a plugin without activate_plugins should be denied.\n" ); exit( 1 );
}
$GLOBALS['closehub_test_caps'] = [ 'install_themes', 'update_themes' ];
if ( CloseHub_Site_Abilities::can_manage_package( [ 'type' => 'theme', 'activate' => true ] ) ) {
	fwrite( STDERR, "Activating a theme without switch_themes should be denied.\n" ); exit( 1 );
}
$GLOBALS['closehub_test_caps'] = [ 'install_themes', 'update_themes', 'switch_themes' ];
if ( ! CloseHub_Site_Abilities::can_manage_package( [ 'type' => 'theme', 'activate' => true ] ) ) {
	fwrite( STDERR, "A user with install/update/switch_themes should be able to overwrite and activate a theme.\n" ); exit( 1 );
}

// ── fetch_package_zip: exactly one of zip_base64/zip_url is required ───────────
$neither = CloseHub_Site_Abilities::fetch_package_zip( [] );
if ( ! $neither instanceof WP_Error ) { fwrite( STDERR, "fetch_package_zip should reject input with neither source.\n" ); exit( 1 ); }
$both = CloseHub_Site_Abilities::fetch_package_zip( [ 'zip_base64' => 'x', 'zip_url' => 'https://example.com/p.zip' ] );
if ( ! $both instanceof WP_Error ) { fwrite( STDERR, "fetch_package_zip should reject input with both sources.\n" ); exit( 1 ); }

// ── fetch_package_zip: non-https URL is rejected before any download attempt ──
$http_url = CloseHub_Site_Abilities::fetch_package_zip( [ 'zip_url' => 'http://example.com/p.zip' ] );
if ( ! $http_url instanceof WP_Error ) { fwrite( STDERR, "fetch_package_zip should reject a non-https zip_url.\n" ); exit( 1 ); }

// ── fetch_package_zip: invalid base64 is rejected ──────────────────────────────
$bad_base64 = CloseHub_Site_Abilities::fetch_package_zip( [ 'zip_base64' => '***not base64***' ] );
if ( ! $bad_base64 instanceof WP_Error ) { fwrite( STDERR, "fetch_package_zip should reject invalid base64.\n" ); exit( 1 ); }

// ── fetch_package_zip: base64 content that isn't a ZIP is rejected, and its temp file is cleaned up ──
$GLOBALS['closehub_test_deleted'] = [];
$not_zip                          = CloseHub_Site_Abilities::fetch_package_zip( [ 'zip_base64' => base64_encode( 'plain text, not a zip' ) ] );
if ( ! $not_zip instanceof WP_Error ) { fwrite( STDERR, "fetch_package_zip should reject non-ZIP base64 content.\n" ); exit( 1 ); }
if ( empty( $GLOBALS['closehub_test_deleted'] ) ) { fwrite( STDERR, "fetch_package_zip should delete the temp file for rejected non-ZIP content.\n" ); exit( 1 ); }

// ── fetch_package_zip: oversized base64 payload is rejected ────────────────────
$GLOBALS['closehub_test_max_bytes'] = 4;
$too_large                         = CloseHub_Site_Abilities::fetch_package_zip( [ 'zip_base64' => base64_encode( 'twenty bytes of data' ) ] );
if ( ! $too_large instanceof WP_Error ) { fwrite( STDERR, "fetch_package_zip should reject a payload over closehub_upload_package_max_bytes.\n" ); exit( 1 ); }
$GLOBALS['closehub_test_max_bytes'] = 10 * 1024 * 1024;

// ── Build a real plugin ZIP and a real theme ZIP to exercise inspect_package_zip ──
function closehub_test_build_zip( array $files ): string {
	$path = tempnam( sys_get_temp_dir(), 'closehub-test-fixture-' ) . '.zip';
	$zip  = new ZipArchive();
	$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	foreach ( $files as $name => $contents ) {
		$zip->addFromString( $name, $contents );
	}
	$zip->close();
	return $path;
}

$plugin_zip = closehub_test_build_zip( [
	'my-plugin/my-plugin.php' => "<?php\n/**\n * Plugin Name: My Plugin\n * Version: 1.3.0\n */\n",
	'my-plugin/readme.txt'    => 'stub',
] );
[ $slug, $version ] = CloseHub_Site_Abilities::inspect_package_zip( $plugin_zip, 'plugin' ) ?: [ null, null ];
if ( 'my-plugin' !== $slug || '1.3.0' !== $version ) { fwrite( STDERR, "inspect_package_zip should read the plugin slug and version from its header.\n" ); exit( 1 ); }
unlink( $plugin_zip );

$theme_zip = closehub_test_build_zip( [
	'my-theme/style.css'      => "/*\nTheme Name: My Theme\nVersion: 2.0.1\n*/\n",
	'my-theme/index.php'      => '<?php',
] );
[ $theme_slug, $theme_version ] = CloseHub_Site_Abilities::inspect_package_zip( $theme_zip, 'theme' ) ?: [ null, null ];
if ( 'my-theme' !== $theme_slug || '2.0.1' !== $theme_version ) { fwrite( STDERR, "inspect_package_zip should read the theme slug and version from style.css.\n" ); exit( 1 ); }
unlink( $theme_zip );

// ── inspect_package_zip: a ZIP without the expected header is rejected ─────────
$no_header_zip = closehub_test_build_zip( [ 'random/file.txt' => 'nothing here' ] );
$no_header     = CloseHub_Site_Abilities::inspect_package_zip( $no_header_zip, 'plugin' );
if ( ! $no_header instanceof WP_Error ) { fwrite( STDERR, "inspect_package_zip should reject a ZIP with no Plugin Name header.\n" ); exit( 1 ); }
unlink( $no_header_zip );

// ── looks_like_zip: magic-byte sniffing ─────────────────────────────────────────
$real_zip = closehub_test_build_zip( [ 'a.txt' => 'a' ] );
if ( ! CloseHub_Site_Abilities::looks_like_zip( $real_zip ) ) { fwrite( STDERR, "looks_like_zip should accept a real ZIP file.\n" ); exit( 1 ); }
unlink( $real_zip );
$plain_file = tempnam( sys_get_temp_dir(), 'closehub-test-plain-' );
file_put_contents( $plain_file, 'not a zip' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
if ( CloseHub_Site_Abilities::looks_like_zip( $plain_file ) ) { fwrite( STDERR, "looks_like_zip should reject a plain text file.\n" ); exit( 1 ); }
unlink( $plain_file );

// ── Ability registration: schema and annotations ────────────────────────────────
CloseHub_Site_Abilities::register_abilities();
$ability = $GLOBALS['closehub_test_abilities']['closehub/upload-package-zip'] ?? [];
if ( [ 'type' ] !== ( $ability['input_schema']['required'] ?? [] ) ) { fwrite( STDERR, "upload-package-zip should only require type.\n" ); exit( 1 ); }
if ( empty( $ability['meta']['annotations']['destructive'] ) ) { fwrite( STDERR, "upload-package-zip must be marked destructive: it can overwrite existing code.\n" ); exit( 1 ); }
if ( ! empty( $ability['meta']['annotations']['readonly'] ) || ! empty( $ability['meta']['annotations']['idempotent'] ) ) { fwrite( STDERR, "upload-package-zip must be non-readonly and non-idempotent.\n" ); exit( 1 ); }

echo "Upload package ability checks passed.\n";
