<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private array $data = [] ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): array { return $this->data; }
}
function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }

class WP_REST_Request {
	public array $params = [];
	public function get_param( string $key ): mixed { return $this->params[ $key ] ?? null; }
	public function set_param( string $key, mixed $value ): void { $this->params[ $key ] = $value; }
}
class WP_REST_Server {
	const CREATABLE = 'POST';
	const READABLE  = 'GET';
}
class WP_REST_Response {
	public function __construct( public mixed $data = null ) {}
}
function rest_ensure_response( $response ) { return $response instanceof WP_REST_Response ? $response : new WP_REST_Response( $response ); }
function sanitize_text_field( string $value ): string { return $value; }
function wp_kses_post( string $value ): string { return $value; }
function maybe_serialize( $value ) { return is_array( $value ) ? serialize( $value ) : $value; }
function wp_generate_uuid4(): string { return 'uuid-' . bin2hex( random_bytes( 4 ) ); }
function is_multisite(): bool { return false; }
function current_time( string $format ) { return date( $format ); }
function wp_parse_url( string $url, ?int $component = -1 ) { return parse_url( $url, $component ?? -1 ); }
function wp_basename( string $path ): string { return basename( $path ); }

$GLOBALS['closehub_test_elmp_active'] = true;
$GLOBALS['closehub_test_woo_active']  = true;
$GLOBALS['closehub_test_products']    = [];   // sku => product_id
$GLOBALS['closehub_test_release']     = null; // last release payload passed to create()

function wc_get_product_id_by_sku( string $sku ): int {
	return $GLOBALS['closehub_test_products'][ $sku ] ?? 0;
}

class WC_Product_Download {
	private string $id = '';
	private string $name = '';
	private string $file = '';
	public function set_id( string $id ): void { $this->id = $id; }
	public function get_id(): string { return $this->id; }
	public function set_name( string $name ): void { $this->name = $name; }
	public function get_name(): string { return $this->name; }
	public function set_file( string $file ): void { $this->file = $file; }
	public function get_file(): string { return $this->file; }
}
class WC_Product {
	public bool $downloadable = false;
	public bool $saved = false;
	public function __construct( private int $id ) {}
	public function get_id(): int { return $this->id; }
	public function get_downloads(): array { return $GLOBALS['closehub_test_product_downloads'][ $this->id ] ?? []; }
	public function set_downloads( array $downloads ): void { $GLOBALS['closehub_test_product_downloads'][ $this->id ] = $downloads; }
	public function set_downloadable( bool $value ): void { $this->downloadable = $value; }
	public function save(): void { $this->saved = true; $GLOBALS['closehub_test_saved_downloads'] = $this->get_downloads(); }
}
function wc_get_product( int $id ) {
	if ( ! $GLOBALS['closehub_test_woo_active'] ) {
		return false;
	}
	return in_array( $id, $GLOBALS['closehub_test_products'], true ) ? new WC_Product( $id ) : false;
}
$GLOBALS['closehub_test_release_store']     = []; // id => array data, keeps update() state.
$GLOBALS['closehub_test_saved_downloads']   = null; // set by WC_Product::save() in the zip_url update test.
$GLOBALS['closehub_test_product_downloads'] = []; // product_id => downloads array, persisted across wc_get_product() calls.

if ( ! $GLOBALS['closehub_test_elmp_active'] ) {
	// Simulate the plugin being inactive: the guarded symbols simply don't exist.
} else {
	class Test_ELM_Release_Model {
		public function __construct( private array $data ) {}
		public function get_product_id(): ?int { return $this->data['product_id'] ?? null; }
		public function to_array(): array { return $this->data; }
	}
	function elmp_find_release( int $release_id ) {
		if ( ! isset( $GLOBALS['closehub_test_release_store'][ $release_id ] ) ) {
			return false;
		}
		return new Test_ELM_Release_Model( $GLOBALS['closehub_test_release_store'][ $release_id ] );
	}
	function elmp_update_release( array $release_update_data, Test_ELM_Release_Model $release ) {
		$id                                              = $release->to_array()['id'];
		$GLOBALS['closehub_test_release_store'][ $id ]   = array_merge( $release->to_array(), $release_update_data );
		return new Test_ELM_Release_Model( $GLOBALS['closehub_test_release_store'][ $id ] );
	}
	function elmp_create_release(): void {}
	class ELMP_Release_Source_Abstract {
		const REST_API = 'rest_api';
	}
	// phpcs:ignore Squiz.Classes.ClassFileName -- test stub mirrors the real plugin's namespaced class.
	eval( '
		namespace Enwikuna\Enwikuna_License_Manager_Pro;
		class ELMP_Release {
			private static $instance;
			public static function get_instance() { return self::$instance ??= new self(); }
			public function create( array $data ) {
				$GLOBALS["closehub_test_release"] = $data;
				if ( empty( $data["version"] ) ) { return false; }
				$id                                            = count( $GLOBALS["closehub_test_release_store"] ) + 1;
				$data                                          = $data + [ "id" => $id ];
				$GLOBALS["closehub_test_release_store"][ $id ] = $data;
				return new \Test_ELM_Release_Model( $data );
			}
		}
	' );
}

$GLOBALS['closehub_test_registered_routes'] = []; // route => route args, captured by register_rest_route().
function add_action( string $hook, callable $callback, int $priority = 10 ): void {}
function register_rest_route( string $namespace, string $route, array $args ): void {
	$GLOBALS['closehub_test_registered_routes'][ $route ] = $args;
}

require_once dirname( __DIR__ ) . '/includes/class-rest-api.php';

( new CloseHub_REST_API() )->register_routes();

function call_create_elm_release_data( WP_REST_Request $request ) {
	$api    = new CloseHub_REST_API();
	$method = new ReflectionMethod( CloseHub_REST_API::class, 'create_elm_release_data' );
	$method->setAccessible( true );
	return $method->invoke( $api, $request );
}

function call_update_elm_release_data( WP_REST_Request $request ) {
	$api    = new CloseHub_REST_API();
	$method = new ReflectionMethod( CloseHub_REST_API::class, 'update_elm_release_data' );
	$method->setAccessible( true );
	return $method->invoke( $api, $request );
}

function make_request( array $params ): WP_REST_Request {
	$request         = new WP_REST_Request();
	$request->params = $params;
	return $request;
}

// 2) No product_id/product_sku resolves -> 400.
$GLOBALS['closehub_test_products'] = [ 'CONECOMDAT' => 501 ];
$result                            = call_create_elm_release_data( make_request( [
	'version'   => '1.0.3',
	'changelog' => 'Fixed pagination.',
	'zip_url'   => '/var/www/vhosts/close.technology/shop-downloads/connect-ecommerce-datisa-1.0.3.zip',
] ) );
if ( ! $result instanceof WP_Error || 'closehub_elm_missing_product' !== $result->get_error_code() ) {
	fwrite( STDERR, "Expected closehub_elm_missing_product when no product_id/product_sku resolves.\n" );
	exit( 1 );
}

// 3) Unknown product_sku -> 400 (does not resolve to any id).
$result = call_create_elm_release_data( make_request( [
	'product_sku' => 'UNKNOWN-SKU',
	'version'     => '1.0.3',
	'changelog'   => 'Fixed pagination.',
	'zip_url'     => '/path/to.zip',
] ) );
if ( ! $result instanceof WP_Error || 'closehub_elm_missing_product' !== $result->get_error_code() ) {
	fwrite( STDERR, "Expected closehub_elm_missing_product for an unknown SKU.\n" );
	exit( 1 );
}

// 4) product_id given directly, but no matching WooCommerce product -> 404.
$result = call_create_elm_release_data( make_request( [
	'product_id' => 9999,
	'version'    => '1.0.3',
	'changelog'  => 'Fixed pagination.',
	'zip_url'    => '/path/to.zip',
] ) );
if ( ! $result instanceof WP_Error || 'closehub_elm_product_not_found' !== $result->get_error_code() ) {
	fwrite( STDERR, "Expected closehub_elm_product_not_found for an unmatched product_id.\n" );
	exit( 1 );
}

// 5) Happy path via product_sku: creates the release and updates the download file.
$GLOBALS['closehub_test_release'] = null;
$result                           = call_create_elm_release_data( make_request( [
	'product_sku' => 'CONECOMDAT',
	'version'     => '1.0.3',
	'changelog'   => 'Fixed pagination.',
	'zip_url'     => '/var/www/vhosts/close.technology/shop-downloads/connect-ecommerce-datisa-1.0.3.zip',
] ) );
if ( $result instanceof WP_Error ) {
	fwrite( STDERR, "Happy path via product_sku should not error: " . $result->get_error_message() . "\n" );
	exit( 1 );
}
if ( 501 !== ( $GLOBALS['closehub_test_release']['product_id'] ?? null ) ) {
	fwrite( STDERR, "product_sku was not resolved to the matching product_id before creating the release.\n" );
	exit( 1 );
}
if ( '1.0.3' !== ( $result['version'] ?? null ) ) {
	fwrite( STDERR, "Release response did not include the created version.\n" );
	exit( 1 );
}

// 6) product_id takes precedence when both product_id and product_sku are given.
$GLOBALS['closehub_test_products']['OTHER-SKU'] = 777;
$GLOBALS['closehub_test_release']               = null;
$result                                          = call_create_elm_release_data( make_request( [
	'product_id'  => 501,
	'product_sku' => 'OTHER-SKU',
	'version'     => '2.0.0',
	'changelog'   => 'Test',
	'zip_url'     => '/path/to.zip',
] ) );
if ( $result instanceof WP_Error ) {
	fwrite( STDERR, "Happy path via product_id should not error: " . $result->get_error_message() . "\n" );
	exit( 1 );
}
if ( 501 !== ( $GLOBALS['closehub_test_release']['product_id'] ?? null ) ) {
	fwrite( STDERR, "Explicit product_id should take precedence over product_sku.\n" );
	exit( 1 );
}

// 7) Optional tested/requires/requires_php/upgrade_notice are passed through when given...
$GLOBALS['closehub_test_release'] = null;
$result                           = call_create_elm_release_data( make_request( [
	'product_sku'    => 'CONECOMDAT',
	'version'        => '1.0.3',
	'changelog'      => 'Fixed pagination.',
	'zip_url'        => '/path/to.zip',
	'tested'         => '6.7',
	'requires'       => '6.0',
	'requires_php'   => '8.1',
	'upgrade_notice' => 'Please back up before updating.',
] ) );
if ( $result instanceof WP_Error ) {
	fwrite( STDERR, "Happy path with optional fields should not error: " . $result->get_error_message() . "\n" );
	exit( 1 );
}
foreach ( [ 'tested' => '6.7', 'requires' => '6.0', 'requires_php' => '8.1', 'upgrade_notice' => 'Please back up before updating.' ] as $field => $expected ) {
	if ( $expected !== ( $GLOBALS['closehub_test_release'][ $field ] ?? null ) ) {
		fwrite( STDERR, "Expected {$field} to be passed through to the release, got: " . var_export( $GLOBALS['closehub_test_release'][ $field ] ?? null, true ) . "\n" );
		exit( 1 );
	}
}

// ...and default to null when omitted, rather than an empty string.
$GLOBALS['closehub_test_release'] = null;
$result                           = call_create_elm_release_data( make_request( [
	'product_sku' => 'CONECOMDAT',
	'version'     => '1.0.3',
	'changelog'   => 'Fixed pagination.',
	'zip_url'     => '/path/to.zip',
] ) );
if ( $result instanceof WP_Error ) {
	fwrite( STDERR, "Happy path without optional fields should not error: " . $result->get_error_message() . "\n" );
	exit( 1 );
}
foreach ( [ 'tested', 'requires', 'requires_php', 'upgrade_notice' ] as $field ) {
	if ( null !== ( $GLOBALS['closehub_test_release'][ $field ] ?? null ) ) {
		fwrite( STDERR, "Expected {$field} to be null when omitted, got: " . var_export( $GLOBALS['closehub_test_release'][ $field ] ?? null, true ) . "\n" );
		exit( 1 );
	}
}

// 8) PUT /elm-releases/{id}: unknown id -> 404.
$result = call_update_elm_release_data( make_request( [ 'id' => 9999 ] ) );
if ( ! $result instanceof WP_Error || 'closehub_elm_release_not_found' !== $result->get_error_code() ) {
	fwrite( STDERR, "Expected closehub_elm_release_not_found for an unknown release id.\n" );
	exit( 1 );
}

// 9) PUT fills in tested/requires/requires_php without touching other fields or creating a duplicate.
$created = call_create_elm_release_data( make_request( [
	'product_sku' => 'CONECOMDAT',
	'version'     => '1.0.3',
	'changelog'   => 'Original changelog.',
	'zip_url'     => '/path/to.zip',
] ) );
$release_id    = $created['id'];
$store_before  = count( $GLOBALS['closehub_test_release_store'] );
$updated       = call_update_elm_release_data( make_request( [
	'id'           => $release_id,
	'tested'       => '7.1.1',
	'requires'     => '6.3',
	'requires_php' => '7.4',
] ) );
if ( $updated instanceof WP_Error ) {
	fwrite( STDERR, "Update with valid id should not error: " . $updated->get_error_message() . "\n" );
	exit( 1 );
}
if ( count( $GLOBALS['closehub_test_release_store'] ) !== $store_before ) {
	fwrite( STDERR, "Update should not create a new release row.\n" );
	exit( 1 );
}
foreach ( [ 'tested' => '7.1.1', 'requires' => '6.3', 'requires_php' => '7.4' ] as $field => $expected ) {
	if ( $expected !== ( $updated[ $field ] ?? null ) ) {
		fwrite( STDERR, "Expected updated {$field} to be {$expected}, got: " . var_export( $updated[ $field ] ?? null, true ) . "\n" );
		exit( 1 );
	}
}
if ( 'Original changelog.' !== ( $updated['changelog'] ?? null ) ) {
	fwrite( STDERR, "Fields not included in the PUT request should be left unchanged.\n" );
	exit( 1 );
}

// 10) PUT with zip_url updates the release's product download file.
$GLOBALS['closehub_test_saved_downloads'] = null;
$updated_with_zip                         = call_update_elm_release_data( make_request( [
	'id'      => $release_id,
	'zip_url' => '/var/www/vhosts/close.technology/shop-downloads/connect-ecommerce-datisa-1.0.3.zip',
] ) );
if ( $updated_with_zip instanceof WP_Error ) {
	fwrite( STDERR, "Update with zip_url should not error: " . $updated_with_zip->get_error_message() . "\n" );
	exit( 1 );
}
$downloads = $GLOBALS['closehub_test_saved_downloads'];
if ( empty( $downloads ) || '/var/www/vhosts/close.technology/shop-downloads/connect-ecommerce-datisa-1.0.3.zip' !== reset( $downloads )->get_file() ) {
	fwrite( STDERR, "Product download file was not updated to the new zip_url.\n" );
	exit( 1 );
}

// 11) Updating a product's download file must not delete or overwrite its
// other, unrelated downloads (e.g. a manual). The new entry gets its own
// deterministic id derived from the zip's filename, not the first existing
// download's id, so it never clobbers an unrelated file just because that
// file happened to be listed first.
$GLOBALS['closehub_test_product_downloads'][501] = [
	'existing-manual-id' => ( function () {
		$d = new WC_Product_Download();
		$d->set_id( 'existing-manual-id' );
		$d->set_name( 'unrelated-file.pdf' );
		$d->set_file( '/path/to/unrelated-file.pdf' );
		return $d;
	} )(),
];
call_update_elm_release_data( make_request( [
	'id'      => $release_id,
	'zip_url' => '/path/to/new.zip',
] ) );
$downloads_after = $GLOBALS['closehub_test_product_downloads'][501];
if ( ! isset( $downloads_after['existing-manual-id'] ) || '/path/to/unrelated-file.pdf' !== $downloads_after['existing-manual-id']->get_file() ) {
	fwrite( STDERR, "Updating the download file must not delete or modify pre-existing, unrelated downloads on the product.\n" );
	exit( 1 );
}
if ( count( $downloads_after ) !== 2 ) {
	fwrite( STDERR, "Expected a new download entry to be added alongside the unrelated one, not merged into it. Got: " . count( $downloads_after ) . " entries.\n" );
	exit( 1 );
}
$new_entry = array_values( array_filter( $downloads_after, static fn( $d ) => 'existing-manual-id' !== $d->get_id() ) )[0] ?? null;
if ( ! $new_entry || '/path/to/new.zip' !== $new_entry->get_file() ) {
	fwrite( STDERR, "Expected a new download entry pointing at the new zip.\n" );
	exit( 1 );
}

// 11b) Calling it again with the SAME filename updates that entry in place
// (no third entry), so repeat releases of the same plugin don't pile up
// duplicate downloads under new ids each time.
call_update_elm_release_data( make_request( [
	'id'      => $release_id,
	'zip_url' => '/different/path/to/new.zip', // same basename as above: new.zip
] ) );
$downloads_after_second = $GLOBALS['closehub_test_product_downloads'][501];
if ( count( $downloads_after_second ) !== 2 ) {
	fwrite( STDERR, "A second update with the same zip filename should update the existing entry in place, not add a third. Got: " . count( $downloads_after_second ) . " entries.\n" );
	exit( 1 );
}

// 12) Route registration: zip_url's validate_callback rejects an empty string.
$create_zip_url_arg = $GLOBALS['closehub_test_registered_routes']['/elm-releases']['args']['zip_url'];
if ( ( $create_zip_url_arg['validate_callback'] )( '' ) ) {
	fwrite( STDERR, "POST /elm-releases zip_url should reject an empty string.\n" );
	exit( 1 );
}
if ( ! ( $create_zip_url_arg['validate_callback'] )( '/path/to.zip' ) ) {
	fwrite( STDERR, "POST /elm-releases zip_url should accept a non-empty path.\n" );
	exit( 1 );
}

// 13) Route registration: zip_url's sanitize_callback preserves percent-encoded
// characters that sanitize_text_field() would otherwise strip (e.g. a signed
// URL's %2F/%3D sequences), since this value is a path/URL, never rendered as HTML.
$create_zip_url_sanitize = $create_zip_url_arg['sanitize_callback'];
$sanitized               = call_user_func( $create_zip_url_sanitize, "/path/signed%2Furl%3Dabc123.zip\n" );
if ( '/path/signed%2Furl%3Dabc123.zip' !== $sanitized ) {
	fwrite( STDERR, "Expected percent-encoded characters to survive sanitization, got: {$sanitized}\n" );
	exit( 1 );
}

// 14) Route registration: id's validate_callback tolerates WP's 3-argument call
// (value, request, param key) — this used to fatal because is_numeric() itself
// was registered directly, and it only accepts 1 argument.
$create_id_arg = $GLOBALS['closehub_test_registered_routes']['/elm-releases/(?P<id>\d+)']['args']['id'];
if ( ! ( $create_id_arg['validate_callback'] )( '21', make_request( [] ), 'id' ) ) {
	fwrite( STDERR, "PUT /elm-releases/{id} id validator should accept a numeric string called with WP's 3 arguments.\n" );
	exit( 1 );
}

// 15) A product_sku of "0" is falsy in PHP but a legitimate SKU value; it
// must still resolve instead of being treated as "no SKU given".
$GLOBALS['closehub_test_products']['0'] = 999;
$GLOBALS['closehub_test_release']       = null;
$result                                 = call_create_elm_release_data( make_request( [
	'product_sku' => '0',
	'version'     => '1.0.0',
	'changelog'   => 'Test',
	'zip_url'     => '/path/to.zip',
] ) );
if ( $result instanceof WP_Error ) {
	fwrite( STDERR, "product_sku '0' should resolve like any other SKU, got: " . $result->get_error_message() . "\n" );
	exit( 1 );
}
if ( 999 !== ( $GLOBALS['closehub_test_release']['product_id'] ?? null ) ) {
	fwrite( STDERR, "product_sku '0' was not resolved to its matching product_id.\n" );
	exit( 1 );
}

// 16) The download name must come from the zip's path, not include a query
// string — a signed URL's query parameters must not end up in the
// customer-facing download name.
$GLOBALS['closehub_test_product_downloads'][501] = [];
call_update_elm_release_data( make_request( [
	'id'      => $release_id,
	'zip_url' => 'https://s3.example.com/plugin.zip?X-Amz-Credential=abc%2Fdef',
] ) );
$signed_download = reset( $GLOBALS['closehub_test_product_downloads'][501] );
if ( ! $signed_download instanceof WC_Product_Download ) {
	fwrite( STDERR, "Expected a download entry to be created for the signed URL.\n" );
	exit( 1 );
}
if ( 'plugin.zip' !== $signed_download->get_name() ) {
	fwrite( STDERR, "Expected the download name to be the bare filename without the query string, got: " . $signed_download->get_name() . "\n" );
	exit( 1 );
}
if ( 'https://s3.example.com/plugin.zip?X-Amz-Credential=abc%2Fdef' !== $signed_download->get_file() ) {
	fwrite( STDERR, "Expected the full signed URL, including its query string, to still be stored as the download file.\n" );
	exit( 1 );
}

echo "OK\n";
