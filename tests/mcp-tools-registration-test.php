<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['closehub_test_filters'] = [];

function add_action( string $hook, callable $callback ): void {}
function add_filter( string $hook, callable $callback, int $priority = 10 ): void {
	$GLOBALS['closehub_test_filters'][ $hook ][ $priority ][] = $callback;
}
function wp_register_ability(): void {}

require_once dirname( __DIR__ ) . '/includes/class-content-abilities.php';
require_once dirname( __DIR__ ) . '/includes/class-site-abilities.php';

CloseHub_Content_Abilities::register();
CloseHub_Site_Abilities::register();

$config = [ 'tools' => [ 'mcp-adapter/discover-abilities' ] ];
foreach ( $GLOBALS['closehub_test_filters']['mcp_adapter_default_server_config'] as $callbacks ) {
	foreach ( $callbacks as $callback ) {
		$config = $callback( $config );
	}
}

$expected = [
	'mcp-adapter/discover-abilities',
	'closehub/list-posts',
	'closehub/get-post',
	'closehub/create-post',
	'closehub/update-post',
	'closehub/update-post-slug',
	'closehub/replace-gutenberg-block',
	'closehub/trash-post',
	'closehub/get-order-summary',
	'closehub/search-replace-domain',
	'closehub/set-robots-txt',
	'closehub/set-post-noindex',
	'closehub/flush-permalinks',
	'closehub/get-htaccess',
	'closehub/set-site-settings',
	'closehub/reassign-posts-author',
	'closehub/install-wp-org-plugin',
	'closehub/list-unused-plugins',
	'closehub/update-plugins-and-core',
	'closehub/create-site-user',
];

if ( $expected !== $config['tools'] ) {
	fwrite( STDERR, "Default MCP server tools did not include every CloseHub ability.\n" );
	exit( 1 );
}

echo "MCP tool registration checks passed.\n";
