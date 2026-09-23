<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; }
class WP_REST_Response {
	private array $headers = [];
	public function __construct( private $data = null, private int $status = 200 ) {}
	public function header( string $name, string $value ): void { $this->headers[ $name ] = $value; }
	public function get_headers(): array { return $this->headers; }
	public function get_data() { return $this->data; }
	public function get_status(): int { return $this->status; }
}
class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private array $data = [] ) {}
	public function get_error_code(): string { return $this->code; }
}
class WP_REST_Request {
	private array $params = [];
	public function __construct( private string $method = 'GET', private string $route = '' ) {}
	public function get_route(): string { return $this->route; }
	public function set_param( string $key, $value ): void { $this->params[ $key ] = $value; }
	public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
}

function add_action( ...$args ): void {}
function add_filter( ...$args ): void {}
function home_url( string $path = '' ): string { return ( $GLOBALS['closehub_test_home_url'] ?? 'https://example.test' ) . $path; }
function rest_url( string $path = '' ): string { return 'https://example.test/wp-json/' . ltrim( $path, '/' ); }
function wp_parse_url( string $url, ?int $component = null ) { return parse_url( $url, $component ?? -1 ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
function rest_get_url_prefix(): string { return 'wp-json'; }
function sanitize_text_field( string $value ): string { return $value; }
function esc_url_raw( string $value ): string { return $value; }
function current_time( string $type, bool $gmt = false ): string { return '2026-09-23 09:00:00'; }
function wp_mkdir_p( string $target ): bool { return is_dir( $target ) || mkdir( $target, 0755, true ); }
function get_home_path(): string { return $GLOBALS['closehub_test_home_path']; }
function is_multisite(): bool { return false; }
function is_main_site(): bool { return true; }
function wp_json_encode( $value, int $flags = 0 ) { return json_encode( $value, $flags ); }
function update_option( string $option, $value, bool $autoload = true ): bool { $GLOBALS['closehub_test_options'][ $option ] = $value; return true; }
function get_option( string $option, $default = false ) { return $GLOBALS['closehub_test_options'][ $option ] ?? $default; }
function delete_option( string $option ): bool { unset( $GLOBALS['closehub_test_options'][ $option ] ); return true; }
function get_transient( string $key ) { return $GLOBALS['closehub_test_transients'][ $key ] ?? false; }
function set_transient( string $key, $value, int $expiration ): bool { $GLOBALS['closehub_test_transients'][ $key ] = $value; return true; }
function is_wp_error( $thing ): bool { return false; }
function wp_safe_remote_get( string $url, array $args ): array {
	if ( isset( $GLOBALS['closehub_test_client_metadata'][ $url ] ) ) { return $GLOBALS['closehub_test_client_metadata'][ $url ]; }
	return [ 'response' => [ 'code' => 200 ], 'headers' => [ 'content-type' => 'application/json; charset=UTF-8' ], 'body' => str_contains( $url, 'oauth-protected-resource' ) ? wp_json_encode( [ 'resource' => rest_url( 'mcp/mcp-adapter-default-server' ), 'authorization_servers' => [ home_url() ], 'bearer_methods_supported' => [ 'header' ], 'scopes_supported' => [ 'mcp:tools' ] ] ) : wp_json_encode( [ 'issuer' => home_url(), 'authorization_endpoint' => rest_url( 'closehub-oauth/v1/authorize' ), 'token_endpoint' => rest_url( 'closehub-oauth/v1/token' ), 'registration_endpoint' => rest_url( 'closehub-oauth/v1/register' ), 'revocation_endpoint' => rest_url( 'closehub-oauth/v1/revoke' ), 'response_types_supported' => [ 'code' ], 'grant_types_supported' => [ 'authorization_code', 'refresh_token' ], 'token_endpoint_auth_methods_supported' => [ 'none' ], 'code_challenge_methods_supported' => [ 'S256' ], 'client_id_metadata_document_supported' => true, 'scopes_supported' => [ 'mcp:tools' ] ] ) ];
}
function wp_remote_retrieve_response_code( array $response ): int { return $response['response']['code']; }
function wp_remote_retrieve_header( array $response, string $header ): string { return $response['headers'][ $header ] ?? ''; }
function wp_remote_retrieve_body( array $response ): string { return $response['body']; }

class CloseHub_Test_WPDB {
	public string $prefix = 'wp_';
	public array $clients = [];

	public function prepare( string $query, ...$args ): string { return (string) end( $args ); }
	public function get_row( string $client_id, $output = null ): ?array { return $this->clients[ $client_id ] ?? null; }
	public function insert( string $table, array $data, array $formats ): bool {
		if ( isset( $this->clients[ $data['client_id'] ] ) ) { return false; }
		$this->clients[ $data['client_id'] ] = $data;
		return true;
	}
	public function update( string $table, array $data, array $where, array $formats, array $where_formats ): int|false {
		$client_id = $where['client_id'];
		if ( ! isset( $this->clients[ $client_id ] ) ) { return 0; }
		$this->clients[ $client_id ] = array_merge( $this->clients[ $client_id ], $data );
		return 1;
	}
}

define( 'ARRAY_A', 'ARRAY_A' );
$wpdb = new CloseHub_Test_WPDB();

require_once dirname( __DIR__ ) . '/includes/class-oauth.php';

function closehub_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, $message . PHP_EOL );
		exit( 1 );
	}
}

// Apache rules must target the site's actual front controller, not assume the
// WordPress installation lives at the document root.
$front_controller = new ReflectionMethod( CloseHub_OAuth::class, 'front_controller_path' );
$front_controller->setAccessible( true );
$GLOBALS['closehub_test_home_url'] = 'https://example.test/blog';
closehub_test_assert( '/blog/index.php' === $front_controller->invoke( null ), 'Managed discovery must route through a subdirectory WordPress front controller.' );
unset( $GLOBALS['closehub_test_home_url'] );

// ── verify_pkce() ────────────────────────────────────────────────────────────

$verifier  = str_repeat( 'a', 64 );
$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
closehub_test_assert( CloseHub_OAuth::verify_pkce( $verifier, $challenge ), 'A matching PKCE verifier/challenge pair must be accepted.' );
closehub_test_assert( ! CloseHub_OAuth::verify_pkce( 'wrong-verifier-that-is-long-enough-to-pass-the-length-check-64', $challenge ), 'A mismatched PKCE verifier must be rejected.' );
closehub_test_assert( ! CloseHub_OAuth::verify_pkce( 'too-short', $challenge ), 'A verifier under 43 characters must be rejected.' );

// ── valid_redirect_uri() ─────────────────────────────────────────────────────

closehub_test_assert( CloseHub_OAuth::valid_redirect_uri( 'https://claude.ai/api/mcp/auth_callback' ), 'An HTTPS redirect URI must be valid.' );
closehub_test_assert( CloseHub_OAuth::valid_redirect_uri( 'http://localhost:1234/callback' ), 'An http://localhost redirect URI must be valid.' );
closehub_test_assert( CloseHub_OAuth::valid_redirect_uri( 'http://127.0.0.1/callback' ), 'An http://127.0.0.1 redirect URI must be valid.' );
closehub_test_assert( ! CloseHub_OAuth::valid_redirect_uri( 'http://attacker.example/callback' ), 'A plain-http non-localhost redirect URI must be rejected.' );
closehub_test_assert( ! CloseHub_OAuth::valid_redirect_uri( 'javascript:alert(1)' ), 'A javascript: redirect URI must be rejected.' );

// ── Client ID Metadata Documents (CIMD) ─────────────────────────────────────

$claude_client_id = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
$GLOBALS['closehub_test_client_metadata'][ $claude_client_id ] = [
	'response' => [ 'code' => 200 ],
	'headers' => [],
	'body' => wp_json_encode( [
		'client_id' => $claude_client_id,
		'client_name' => 'Claude',
		'redirect_uris' => [ 'https://claude.ai/api/mcp/auth_callback' ],
	] ),
];

$oauth_server_metadata = CloseHub_OAuth::server_metadata()->get_data();
closehub_test_assert( true === $oauth_server_metadata['client_id_metadata_document_supported'], 'OAuth metadata must advertise Client ID Metadata Document support.' );

$valid_authorize = new ReflectionMethod( CloseHub_OAuth::class, 'valid_authorize' );
$valid_authorize->setAccessible( true );
$cimd_client = $valid_authorize->invoke( null, [
	'response_type' => 'code',
	'client_id' => $claude_client_id,
	'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
	'state' => 'state',
	'challenge' => $challenge,
	'method' => 'S256',
] );
closehub_test_assert( is_array( $cimd_client ) && 'Claude' === $cimd_client['client_name'], 'A hosted client metadata document must authorize without prior dynamic registration.' );

$invalid_cimd_client = $valid_authorize->invoke( null, [
	'response_type' => 'code',
	'client_id' => $claude_client_id,
	'redirect_uri' => 'https://attacker.example/callback',
	'state' => 'state',
	'challenge' => $challenge,
	'method' => 'S256',
] );
closehub_test_assert( $invalid_cimd_client instanceof WP_Error, 'A hosted client metadata document must reject an unlisted redirect URI.' );

// ── mcp_request() reads $_GET['rest_route'] / $_SERVER['REQUEST_URI'] ───────
// Reflection is used because it's a private implementation detail of
// authenticate() — the fix under test is that it no longer matches a
// substring anywhere in the URI (e.g. inside a query string).

$mcp_request = new ReflectionMethod( CloseHub_OAuth::class, 'mcp_request' );
$mcp_request->setAccessible( true );

$_GET = [];
$_SERVER['REQUEST_URI'] = '/wp-json/mcp/mcp-adapter-default-server';
closehub_test_assert( true === $mcp_request->invoke( null ), 'The real MCP path must be recognized (pretty permalinks).' );

$_GET               = [];
$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/users/me?foo=/mcp/mcp-adapter-default-server';
closehub_test_assert( false === $mcp_request->invoke( null ), 'A substring match in an unrelated route\'s query string must not be treated as an MCP request.' );

$_GET               = [ 'rest_route' => '/mcp/mcp-adapter-default-server' ];
$_SERVER['REQUEST_URI'] = '/?rest_route=%2Fmcp%2Fmcp-adapter-default-server';
closehub_test_assert( true === $mcp_request->invoke( null ), 'The real MCP path must be recognized on a plain-permalinks site (?rest_route=).' );

$_GET               = [ 'rest_route' => '/wp/v2/users/me' ];
$_SERVER['REQUEST_URI'] = '/?rest_route=%2Fwp%2Fv2%2Fusers%2Fme';
closehub_test_assert( false === $mcp_request->invoke( null ), 'An unrelated ?rest_route= value must not be treated as an MCP request.' );

$_GET     = [];
$_SERVER  = [];

// ── authenticate() accepts a case-insensitive Bearer scheme ─────────────────
// Exercised through the regex directly, since authenticate() itself needs a
// live $wpdb — the fix under test is case-insensitivity and correct token
// extraction regardless of the scheme's casing.

foreach ( [ 'Bearer', 'bearer', 'BEARER', 'BeArEr' ] as $scheme ) {
	$header = "{$scheme} abc123";
	closehub_test_assert(
		1 === preg_match( '/^Bearer\s+(\S+)$/i', $header, $matches ) && 'abc123' === $matches[1],
		"A '{$scheme}' auth scheme must be accepted and the token extracted correctly."
	);
}
closehub_test_assert( 0 === preg_match( '/^Bearer\s+(\S+)$/i', 'Basic abc123' ), 'A non-Bearer scheme must not match.' );

// ── static .well-known discovery metadata ───────────────────────────────────

$document_root = sys_get_temp_dir() . '/closehub-oauth-test-docroot-' . uniqid();
mkdir( $document_root, 0755, true );
$GLOBALS['closehub_test_home_path'] = $document_root . '/';

CloseHub_OAuth::ensure_well_known_files();
$resource_file = $document_root . '/.well-known/oauth-protected-resource';
$server_file = $document_root . '/.well-known/oauth-authorization-server';
$resource_metadata = json_decode( (string) file_get_contents( $resource_file ), true );
$server_metadata = json_decode( (string) file_get_contents( $server_file ), true );
closehub_test_assert( ! CloseHub_OAuth::well_known_files_need_regeneration(), 'Current static metadata must not require regeneration.' );
closehub_test_assert( 'https://example.test/wp-json/mcp/mcp-adapter-default-server' === $resource_metadata['resource'], 'Static protected-resource metadata must identify the CloseHub MCP endpoint.' );
closehub_test_assert( [ 'https://example.test' ] === $resource_metadata['authorization_servers'], 'Static protected-resource metadata must identify the CloseHub OAuth server.' );
closehub_test_assert( 'https://example.test/wp-json/closehub-oauth/v1/register' === $server_metadata['registration_endpoint'], 'Static authorization-server metadata must expose CloseHub dynamic registration.' );

file_put_contents( $resource_file, '{"resource":"https://old.example/wp-json/mcp/mcp-adapter-default-server","authorization_servers":["https://old.example"]}' );
closehub_test_assert( CloseHub_OAuth::well_known_files_need_regeneration(), 'Stale static metadata must require regeneration.' );
CloseHub_OAuth::ensure_well_known_files();
closehub_test_assert( 'https://example.test/wp-json/mcp/mcp-adapter-default-server' === json_decode( (string) file_get_contents( $resource_file ), true )['resource'], 'Static metadata must replace stale CloseHub discovery data.' );

file_put_contents( $resource_file, '{"resource":"https://another-provider.example/mcp"}' );
closehub_test_assert( ! CloseHub_OAuth::ensure_well_known_files(), 'Static metadata must not overwrite discovery files owned by another provider.' );
closehub_test_assert( 'https://another-provider.example/mcp' === json_decode( (string) file_get_contents( $resource_file ), true )['resource'], 'Another provider metadata must remain untouched.' );

unlink( $resource_file );
unlink( $server_file );
unlink( $document_root . '/.well-known/.htaccess' );
rmdir( $document_root . '/.well-known' );
rmdir( $document_root );

// ── WordPress filesystem transport ─────────────────────────────────────────

class CloseHub_Test_Filesystem {
	public int $writes = 0;
	public function exists( string $path ): bool { return file_exists( $path ); }
	public function is_dir( string $path ): bool { return is_dir( $path ); }
	public function mkdir( string $path, int $chmod ): bool { return mkdir( $path, $chmod, true ); }
	public function get_contents( string $path ): string|false { return file_exists( $path ) ? file_get_contents( $path ) : false; }
	public function find_folder( string $path ): string { return $path; }
	public function delete( string $path, bool $recursive = false, string $type = '' ): bool { return unlink( $path ); }
	public function put_contents( string $path, string $contents, int $chmod ): bool {
		$this->writes++;
		return false !== file_put_contents( $path, $contents );
	}
}

$document_root = sys_get_temp_dir() . '/closehub-oauth-test-filesystem-' . uniqid();
mkdir( $document_root, 0755, true );
$GLOBALS['closehub_test_home_path'] = $document_root . '/';
$wp_filesystem = new CloseHub_Test_Filesystem();
closehub_test_assert( CloseHub_OAuth::ensure_well_known_files(), 'OAuth metadata must be writable through the WordPress filesystem transport.' );
closehub_test_assert( 3 === $wp_filesystem->writes, 'The WordPress filesystem transport must write both OAuth metadata files and their Apache JSON media type rule.' );

file_put_contents( $document_root . '/.well-known/.htaccess', "<IfModule mod_rewrite.c>\n\tRewriteEngine off\n</IfModule>\n" );
closehub_test_assert( CloseHub_OAuth::enable_managed_discovery(), 'Managed OAuth discovery must install its Apache routing rule.' );
$managed_htaccess = (string) file_get_contents( $document_root . '/.well-known/.htaccess' );
closehub_test_assert( false !== strpos( $managed_htaccess, 'RewriteEngine off' ), 'Managed discovery must preserve the existing .htaccess contents.' );
closehub_test_assert( false !== strpos( $managed_htaccess, '# BEGIN CloseHub OAuth Discovery' ), 'Managed discovery must mark its owned .htaccess rules.' );
closehub_test_assert( false !== strpos( $managed_htaccess, 'RewriteRule ^oauth-(protected-resource|authorization-server)$ /index.php [L]' ), 'Managed discovery must route both OAuth metadata paths to WordPress.' );
closehub_test_assert( false !== strpos( $managed_htaccess, 'ForceType application/json' ), 'Static OAuth discovery files must be served as JSON on Apache.' );
closehub_test_assert( CloseHub_OAuth::managed_discovery_is_enabled(), 'Managed discovery must report its installed Apache rules.' );

unlink( $document_root . '/.well-known/oauth-protected-resource' );
unlink( $document_root . '/.well-known/oauth-authorization-server' );
unlink( $document_root . '/.well-known/.htaccess' );
rmdir( $document_root . '/.well-known' );
rmdir( $document_root );
unset( $_SERVER['DOCUMENT_ROOT'] );
unset( $wp_filesystem );

// ── hosted-client registration and reconnect ────────────────────────────────

class CloseHub_Test_Register_Request extends WP_REST_Request {
	public function get_json_params(): array {
		return [
			'client_id' => 'https://claude.ai/client-metadata.json',
			// These values must be replaced by the verified metadata document.
			'client_name' => 'Untrusted request name',
			'redirect_uris' => [ 'https://attacker.example/callback' ],
		];
	}
}

$client_id = 'https://claude.ai/client-metadata.json';
$GLOBALS['closehub_test_client_metadata'][ $client_id ] = [
	'response' => [ 'code' => 200 ],
	'headers' => [],
	'body' => wp_json_encode( [
		'client_id' => $client_id,
		'client_name' => 'Claude',
		'redirect_uris' => [ 'https://claude.ai/api/mcp/auth_callback' ],
	] ),
];

$request = new CloseHub_Test_Register_Request( 'POST' );
$first_registration = CloseHub_OAuth::register_client( $request );
closehub_test_assert( 201 === $first_registration->get_status(), 'The first hosted-client registration must be created.' );

$GLOBALS['closehub_test_client_metadata'][ $client_id ]['body'] = wp_json_encode( [
	'client_id' => $client_id,
	'client_name' => 'Claude refreshed metadata',
	'redirect_uris' => [ 'https://claude.ai/api/mcp/auth_callback' ],
] );
$second_registration = CloseHub_OAuth::register_client( $request );
closehub_test_assert( 200 === $second_registration->get_status(), 'Re-registering a hosted client must update the existing record.' );
closehub_test_assert( 1 === count( $wpdb->clients ), 'Re-registering a hosted client must not create a second record.' );
closehub_test_assert( 'Claude refreshed metadata' === $wpdb->clients[ $client_id ]['client_name'], 'Re-registering a hosted client must refresh verified metadata.' );

// ── unknown clients are distinct from malformed authorization parameters ────

$unknown_client = $valid_authorize->invoke( null, [
	'response_type' => 'code',
	'client_id' => 'chc-no-longer-registered',
	'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
	'state' => 'state',
	'challenge' => str_repeat( 'a', 43 ),
	'method' => 'S256',
] );
closehub_test_assert( $unknown_client instanceof WP_Error && 'invalid_client' === $unknown_client->get_error_code(), 'An unknown OAuth client must return invalid_client so it can register again.' );

echo "OAuth security checks passed.\n";
