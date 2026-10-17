<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

function rest_url( string $path = '' ): string {
	return 'https://example.test/wp-json/' . ltrim( $path, '/' );
}
function home_url( string $path = '' ): string { return 'https://example.test' . $path; }

function esc_html_e( string $text ): void {
	echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}
function __( string $text ): string { return $text; }
function wp_nonce_field( string $action ): void { echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $action ) . '">'; }
function submit_button( string $text, string $type = 'primary', string $name = 'submit', bool $wrap = true ): void { echo '<button class="button ' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '">' . esc_html( $text ) . '</button>'; }
function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( string $text ): string { return esc_html( $text ); }
function sanitize_key( string $key ): string { return $key; }
function esc_textarea( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function get_user_by( string $field, int $user_id ) { return $user_id > 0 ? (object) [ 'user_login' => 'claude-team' ] : false; }

class CloseHub_MCP_Token {
	public static bool $configured = false;
	public static function get_user_id(): int { return self::$configured ? 7 : 0; }
	public static function is_configured(): bool { return self::$configured; }
}

class CloseHub_OAuth {
	public static bool $metadata_needs_regeneration = true;
	public static bool $managed_discovery_enabled = false;
	public static function well_known_files_need_regeneration(): bool { return self::$metadata_needs_regeneration; }
	public static function initialize_filesystem( string $url ): bool { return true; }
	public static function ensure_well_known_files(): bool { return ! self::$metadata_needs_regeneration; }
	public static function well_known_file_contents(): array { return [ 'oauth-protected-resource' => '{"resource":"https://example.test"}', 'oauth-authorization-server' => '{"issuer":"https://example.test"}' ]; }
	public static function managed_discovery_is_enabled(): bool { return self::$managed_discovery_enabled; }
}

require_once dirname( __DIR__ ) . '/includes/class-admin.php';

function closehub_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, $message . PHP_EOL );
		exit( 1 );
	}
}

$mcp_url = CloseHub_Admin::get_mcp_server_url();
closehub_test_assert(
	'https://example.test/wp-json/mcp/mcp-adapter-default-server' === $mcp_url,
	'The MCP URL must use WordPress rest_url().'
);
closehub_test_assert(
	'https://example.test/?rest_route=%2Fmcp%2Fmcp-adapter-default-server' === CloseHub_Admin::get_mcp_server_query_url(),
	'The Claude Team MCP URL must use WordPress query routing.'
);

$section = new ReflectionMethod( CloseHub_Admin::class, 'render_mcp_section' );
$section->setAccessible( true );

ob_start();
$section->invoke( new CloseHub_Admin() );
$output = (string) ob_get_clean();

closehub_test_assert( false !== strpos( $output, 'id="closehub-mcp-server-url"' ), 'The MCP URL field is missing.' );
closehub_test_assert( false !== strpos( $output, $mcp_url ), 'The MCP URL is missing from the settings section.' );
closehub_test_assert( false !== strpos( $output, 'navigator.clipboard.writeText' ), 'The MCP copy button is missing.' );
closehub_test_assert( false !== strpos( $output, 'MCP Adapter is not available' ), 'The unavailable Adapter status is missing.' );
closehub_test_assert( false !== strpos( $output, 'Always required' ), 'The Claude authentication instruction is missing.' );
closehub_test_assert( false !== strpos( $output, 'Anthropic’s hosted client metadata' ), 'The Claude OAuth client instruction is missing.' );
closehub_test_assert( false !== strpos( $output, 'Claude Team Shared Token' ), 'The Claude Team shared-token section is missing.' );
closehub_test_assert( false !== strpos( $output, 'id="closehub-mcp-query-url"' ), 'The Claude Team query URL field is missing.' );
closehub_test_assert( false !== strpos( $output, CloseHub_Admin::get_mcp_server_query_url() ), 'The Claude Team query URL is missing.' );
closehub_test_assert( false !== strpos( $output, 'closehub_mcp_token_action' ), 'The shared-token management action is missing.' );
closehub_test_assert( false !== strpos( $output, 'Generate Shared Token' ), 'The shared-token generation button is missing.' );
closehub_test_assert( false !== strpos( $output, 'does not use .well-known URLs or an OAuth Client ID' ), 'The Nginx-safe connection explanation is missing.' );
closehub_test_assert( false !== strpos( $output, 'closehub_regenerate_oauth_metadata' ), 'The OAuth metadata regeneration action is missing.' );
closehub_test_assert( false !== strpos( $output, 'Regenerate OAuth Metadata' ), 'The OAuth metadata regeneration button is missing.' );
closehub_test_assert( false !== strpos( $output, 'OAuth discovery metadata is missing, out of date, or cannot be updated' ), 'The OAuth metadata warning is missing when regeneration is required.' );
closehub_test_assert( false !== strpos( $output, 'Managed OAuth Discovery (Apache)' ), 'The managed Apache OAuth discovery mode is missing.' );
closehub_test_assert( false !== strpos( $output, 'closehub_enable_managed_oauth_discovery' ), 'The managed OAuth discovery action is missing.' );

CloseHub_OAuth::$metadata_needs_regeneration = false;
ob_start();
$section->invoke( new CloseHub_Admin() );
$healthy_output = (string) ob_get_clean();
closehub_test_assert( false === strpos( $healthy_output, 'OAuth discovery metadata is missing, out of date, or cannot be updated' ), 'The OAuth metadata warning must be hidden when both files are current.' );

$_GET['closehub_notice'] = 'oauth_metadata_failed';
ob_start();
$section->invoke( new CloseHub_Admin() );
$failed_output = (string) ob_get_clean();
closehub_test_assert( false !== strpos( $failed_output, 'OAuth discovery metadata could not be written' ), 'A failed metadata regeneration must show a write error instead of success.' );
closehub_test_assert( false !== strpos( $failed_output, 'Show files to upload manually' ), 'A failed metadata regeneration must offer the manual upload files.' );
closehub_test_assert( false !== strpos( $failed_output, 'oauth-protected-resource' ), 'The protected-resource filename must be available for manual upload.' );
closehub_test_assert( false !== strpos( $failed_output, 'oauth-authorization-server' ), 'The authorization-server filename must be available for manual upload.' );
$_GET = [];

CloseHub_OAuth::$managed_discovery_enabled = true;
ob_start();
$section->invoke( new CloseHub_Admin() );
$managed_output = (string) ob_get_clean();
closehub_test_assert( false !== strpos( $managed_output, 'Managed OAuth Discovery is enabled.' ), 'The enabled managed discovery status is missing.' );
closehub_test_assert( false === strpos( $managed_output, 'OAuth discovery metadata is missing, out of date, or cannot be updated' ), 'Static-file warning must be hidden in managed discovery mode.' );

CloseHub_MCP_Token::$configured = true;
$token_property = new ReflectionProperty( CloseHub_Admin::class, 'new_mcp_token' );
$token_property->setAccessible( true );
$admin = new CloseHub_Admin();
$token_property->setValue( $admin, 'chm_' . str_repeat( 'a', 64 ) );
$notice_property = new ReflectionProperty( CloseHub_Admin::class, 'mcp_token_notice' );
$notice_property->setAccessible( true );
$notice_property->setValue( $admin, 'generated' );
ob_start();
$section->invoke( $admin );
$configured_output = (string) ob_get_clean();
closehub_test_assert( false !== strpos( $configured_output, 'id="closehub-mcp-shared-token"' ), 'The newly generated token field is missing.' );
closehub_test_assert( false !== strpos( $configured_output, 'value="Bearer chm_' . str_repeat( 'a', 64 ) . '"' ), 'The copyable header value must include Bearer and the full token.' );
closehub_test_assert( false !== strpos( $configured_output, 'Copy header value' ), 'The complete header value copy button is missing.' );
closehub_test_assert( false !== strpos( $configured_output, 'stores only its hash' ), 'The one-time token warning is missing.' );
closehub_test_assert( false !== strpos( $configured_output, 'choose “No sign-in”' ), 'The Claude Team no-sign-in instruction is missing.' );
closehub_test_assert( false !== strpos( $configured_output, 'named authorization.' ), 'The Claude Team header name is missing.' );
closehub_test_assert( false !== strpos( $configured_output, 'authorization: Bearer chm_' ), 'The Claude Team authorization header example is missing.' );
closehub_test_assert( false !== strpos( $configured_output, 'claude-team' ), 'The WordPress user mapped to the shared token is missing.' );
closehub_test_assert( false !== strpos( $configured_output, 'Revoke Shared Token' ), 'The shared-token revoke action is missing.' );

echo "MCP settings page checks passed.\n";
