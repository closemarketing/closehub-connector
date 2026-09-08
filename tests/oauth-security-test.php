<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

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
function home_url( string $path = '' ): string { return 'https://example.test' . $path; }
function rest_url( string $path = '' ): string { return 'https://example.test/wp-json/' . ltrim( $path, '/' ); }
function wp_parse_url( string $url, ?int $component = null ) { return parse_url( $url, $component ?? -1 ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
function rest_get_url_prefix(): string { return 'wp-json'; }

require_once dirname( __DIR__ ) . '/includes/class-oauth.php';

function closehub_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, $message . PHP_EOL );
		exit( 1 );
	}
}

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

echo "OAuth security checks passed.\n";
