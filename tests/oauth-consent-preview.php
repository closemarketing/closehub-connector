<?php

declare( strict_types=1 );

/**
 * Render the OAuth consent screen without starting WordPress or an OAuth flow.
 *
 * Run: php -S 127.0.0.1:8971 tests/oauth-consent-preview.php
 * Then open http://127.0.0.1:8971/ in a browser.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'CLOSEHUB_PLUGIN_FILE', dirname( __DIR__ ) . '/closehub-connector.php' );

class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; }
class WP_REST_Response {
	public function __construct( private $data = null, private int $status = 200 ) {}
	public function header( string $name, string $value ): void {}
	public function get_data() { return $this->data; }
}
class WP_REST_Request { public function get_route(): string { return ''; } public function get_param( string $key ) { return null; } }
class CloseHub_Preview_Ability {
	public function __construct( private string $label, private string $description ) {}
	public function get_label(): string { return $this->label; }
	public function get_description(): string { return $this->description; }
}

function add_action( ...$args ): void {}
function add_filter( ...$args ): void {}
function home_url( string $path = '' ): string { return 'https://wp-test.example.com' . $path; }
function rest_url( string $path = '' ): string { return 'https://wp-test.example.com/wp-json/' . ltrim( $path, '/' ); }
function wp_parse_url( string $url, ?int $component = null ) { return parse_url( $url, $component ?? -1 ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
function rest_get_url_prefix(): string { return 'wp-json'; }
function sanitize_text_field( string $value ): string { return $value; }
function esc_url_raw( string $value ): string { return $value; }
function esc_html_e( string $text ): void { echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( string $url ): string { return $url; }
function wp_nonce_field( string $action, string $name ): void { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="preview">'; }
function plugins_url( string $path, string $plugin ): string { return '/assets/' . ltrim( $path, '/' ); }
function wp_get_abilities( array $args = [] ): array {
	return [
		'closehub/list-posts'        => new CloseHub_Preview_Ability( 'List posts', 'List or search WordPress content.' ),
		'closehub/create-post'       => new CloseHub_Preview_Ability( 'Create post', 'Create draft content for review or publication.' ),
		'closehub/update-post'       => new CloseHub_Preview_Ability( 'Update post', 'Update existing WordPress content.' ),
		'closehub/get-order-summary' => new CloseHub_Preview_Ability( 'Get order summary', 'Read WooCommerce sales information.' ),
		'closehub/clear-cache'       => new CloseHub_Preview_Ability( 'Clear site cache', 'Purge the page cache of the active caching plugin.' ),
	];
}

$request_path = parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
if ( '/assets/logo-closehub.svg' === $request_path ) {
	header( 'Content-Type: image/svg+xml' );
	readfile( dirname( __DIR__ ) . '/assets/logo-closehub.svg' );
	return;
}

require_once dirname( __DIR__ ) . '/includes/class-oauth.php';

$method = new ReflectionMethod( CloseHub_OAuth::class, 'consent_page' );
$method->setAccessible( true );
$response = $method->invoke( null, [ 'client_name' => 'Claude' ], [
	'response_type' => 'code',
	'client_id'     => 'preview-client',
	'redirect_uri'  => 'https://claude.ai/api/mcp/auth_callback',
	'state'         => 'preview',
	'challenge'     => str_repeat( 'a', 43 ),
	'method'        => 'S256',
] );

echo $response->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- consent_page() returns escaped HTML.
