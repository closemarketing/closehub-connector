<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

function rest_url( string $path = '' ): string { return 'https://example.test/wp-json/' . ltrim( $path, '/' ); }
function home_url( string $path = '' ): string { return 'https://example.test' . $path; }
function get_site_url(): string { return 'https://example.test'; }
function esc_html_e( string $text ): void { echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( string $text ): string { return esc_attr( $text ); }
function __( string $text ): string { return $text; }
function _n( string $single, string $plural, int $number ): string { return 1 === $number ? $single : $plural; }
function wp_nonce_field( string $action ): void { echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $action ) . '">'; }
function submit_button( string $text, string $type = 'primary', string $name = 'submit', bool $wrap = true ): void { echo '<button class="button ' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '">' . esc_html( $text ) . '</button>'; }
function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( string $text ): string { return esc_html( $text ); }
function esc_textarea( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function wp_kses( string $text, array $allowed ): string { return $text; }
function sanitize_key( string $key ): string { return $key; }
function is_multisite(): bool { return false; }
function get_user_by( string $field, int $user_id ) { return $user_id > 0 ? (object) [ 'user_login' => 'davidperez' ] : false; }

class CloseHub_API_Key {
	public static function get(): string { return 'chk_' . str_repeat( 'a', 48 ); }
	public static function get_user_id(): int { return 7; }
}

class CloseHub_OAuth {
	public static bool $metadata_needs_regeneration = true;
	public static bool $managed_discovery_enabled = false;
	public static function well_known_files_need_regeneration(): bool { return self::$metadata_needs_regeneration; }
	public static function managed_discovery_is_enabled(): bool { return self::$managed_discovery_enabled; }
	public static function well_known_file_contents(): array { return [ 'oauth-protected-resource' => '{"resource":"https://example.test"}', 'oauth-authorization-server' => '{"issuer":"https://example.test"}' ]; }
}

require_once dirname( __DIR__ ) . '/includes/class-admin.php';

function closehub_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, $message . PHP_EOL );
		exit( 1 );
	}
}

closehub_test_assert( 'https://example.test/wp-json/mcp/mcp-adapter-default-server' === CloseHub_Admin::get_mcp_server_url(), 'The MCP URL must use WordPress rest_url().' );
closehub_test_assert( 'https://example.test/?rest_route=%2Fmcp%2Fmcp-adapter-default-server' === CloseHub_Admin::get_mcp_server_query_url(), 'The query-routed MCP URL must use WordPress query routing.' );

$render = new ReflectionMethod( CloseHub_Admin::class, 'render_settings' );
$render->setAccessible( true );
$page = static function () use ( $render ): string {
	ob_start();
	$render->invoke( new CloseHub_Admin(), false );
	return (string) ob_get_clean();
};

$output = $page();
$key    = 'chk_' . str_repeat( 'a', 48 );
closehub_test_assert( false !== strpos( $output, 'One API key connects both' ), 'The single-key intro is missing.' );
closehub_test_assert( false !== strpos( $output, 'type="password" id="closehub-api-key" value="' . $key . '"' ), 'The API key must be masked by default.' );
closehub_test_assert( false !== strpos( $output, 'data-closehub-copy="Bearer ' . $key . '"' ), 'The complete Bearer header value must be copyable.' );
closehub_test_assert( false !== strpos( $output, 'data-closehub-copy="https://example.test"' ), 'The copyable Site URL is missing.' );
closehub_test_assert( false !== strpos( $output, '/closehub/v1/elm-releases/{id}' ) && false !== strpos( $output, '/closehub/v1/posts/{id}' ), 'All REST endpoints must be listed.' );
closehub_test_assert( false !== strpos( $output, 'https://example.test/wp-json/closehub/v1/' ), 'The CloseHub endpoint is missing.' );
closehub_test_assert( false !== strpos( $output, CloseHub_Admin::get_mcp_server_query_url() ), 'The MCP server URL is missing.' );
closehub_test_assert( false !== strpos( $output, 'davidperez' ), 'The acting user is missing.' );
closehub_test_assert( false !== strpos( $output, 'data-closehub-copy="authorization"' ), 'The header name must be copyable on its own.' );
closehub_test_assert( false !== strpos( $output, 'No sign-in' ), 'The Claude Team no-sign-in instruction is missing.' );
closehub_test_assert( false !== strpos( $output, 'MCP Adapter is not available' ), 'The unavailable Adapter status is missing.' );
closehub_test_assert( false !== strpos( $output, 'name="closehub_regenerate"' ), 'The regenerate action is missing.' );
closehub_test_assert( false !== strpos( $output, 'data-closehub-confirm' ), 'Regenerating must ask for confirmation.' );
closehub_test_assert( false !== strpos( $output, 'Always required' ), 'The OAuth Claude instruction is missing.' );
closehub_test_assert( false !== strpos( $output, 'closehub_regenerate_oauth_metadata' ), 'The OAuth metadata regeneration action is missing.' );
closehub_test_assert( false !== strpos( $output, 'closehub_enable_managed_oauth_discovery' ), 'The managed OAuth discovery action is missing.' );
closehub_test_assert( false !== strpos( $output, 'OAuth discovery metadata is missing' ), 'The OAuth metadata warning is missing when regeneration is required.' );
closehub_test_assert( false === strpos( $output, 'nav-tab' ), 'The settings page must not use tabs.' );
closehub_test_assert( false === strpos( $output, 'chm_' ), 'The legacy shared token must be gone.' );

CloseHub_OAuth::$metadata_needs_regeneration = false;
closehub_test_assert( false === strpos( $page(), 'OAuth discovery metadata is missing' ), 'The metadata warning must be hidden when files are current.' );

$_GET['closehub_notice'] = 'oauth_metadata_failed';
$failed = $page();
closehub_test_assert( false !== strpos( $failed, 'OAuth discovery metadata could not be written' ), 'A failed regeneration must show a write error.' );
closehub_test_assert( false !== strpos( $failed, 'oauth-authorization-server' ), 'A failed regeneration must offer the manual upload files.' );

$_GET['closehub_notice'] = 'regenerated';
closehub_test_assert( false !== strpos( $page(), 'New API key generated' ), 'The regenerated notice is missing.' );
$_GET = [];

CloseHub_OAuth::$managed_discovery_enabled = true;
$managed = $page();
closehub_test_assert( false !== strpos( $managed, 'Managed OAuth Discovery is enabled.' ), 'The managed discovery status is missing.' );
closehub_test_assert( false === strpos( $managed, 'closehub_enable_managed_oauth_discovery' ), 'The enable button must be hidden once enabled.' );

echo "MCP settings page checks passed.\n";
