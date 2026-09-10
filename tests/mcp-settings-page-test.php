<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

function rest_url( string $path = '' ): string {
	return 'https://example.test/wp-json/' . ltrim( $path, '/' );
}

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

class CloseHub_OAuth {
	public static bool $metadata_needs_regeneration = true;
	public static function well_known_files_need_regeneration(): bool { return self::$metadata_needs_regeneration; }
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
closehub_test_assert( false !== strpos( $output, 'closehub_regenerate_oauth_metadata' ), 'The OAuth metadata regeneration action is missing.' );
closehub_test_assert( false !== strpos( $output, 'Regenerate OAuth Metadata' ), 'The OAuth metadata regeneration button is missing.' );
closehub_test_assert( false !== strpos( $output, 'OAuth discovery metadata is missing, out of date, or cannot be updated' ), 'The OAuth metadata warning is missing when regeneration is required.' );

CloseHub_OAuth::$metadata_needs_regeneration = false;
ob_start();
$section->invoke( new CloseHub_Admin() );
$healthy_output = (string) ob_get_clean();
closehub_test_assert( false === strpos( $healthy_output, 'OAuth discovery metadata is missing, out of date, or cannot be updated' ), 'The OAuth metadata warning must be hidden when both files are current.' );

echo "MCP settings page checks passed.\n";
