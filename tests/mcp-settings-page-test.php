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

echo "MCP settings page checks passed.\n";
