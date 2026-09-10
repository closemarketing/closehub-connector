<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['closehub_test_filters'] = [];

function wp_register_ability( string $name, array $args ): void {}
function add_action( string $hook, callable $callback ): void {}
function add_filter( string $hook, callable $callback ): void { $GLOBALS['closehub_test_filters'][ $hook ] = $callback; }

require_once dirname( __DIR__ ) . '/includes/class-content-abilities.php';

CloseHub_Content_Abilities::register();

if ( ! isset( $GLOBALS['closehub_test_filters']['mcp_adapter_default_server_config'] ) ) {
	fwrite( STDERR, "The CloseHub MCP tools filter was not registered.\n" );
	exit( 1 );
}

$config = ( $GLOBALS['closehub_test_filters']['mcp_adapter_default_server_config'] )( [ 'tools' => [ 'mcp-adapter/discover-abilities' ] ] );
$expected_tools = [
	'mcp-adapter/discover-abilities',
	'closehub/list-posts',
	'closehub/get-post',
	'closehub/create-post',
	'closehub/update-post',
	'closehub/trash-post',
	'closehub/get-order-summary',
];

if ( $expected_tools !== $config['tools'] ) {
	fwrite( STDERR, "The default MCP server does not include every CloseHub tool.\n" );
	exit( 1 );
}

echo "Content ability registration checks passed.\n";
