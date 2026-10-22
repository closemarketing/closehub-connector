<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( private string $code, private string $message, private array $data = [] ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
}

$GLOBALS['closehub_test_blog_id'] = 1;
$GLOBALS['closehub_test_multisite'] = true;
$GLOBALS['closehub_test_wrap'] = true;
function is_multisite(): bool { return $GLOBALS['closehub_test_multisite']; }
function get_sites( array $args ): array { return [ (object) [ 'blog_id' => 1 ], (object) [ 'blog_id' => 9 ] ]; }
function switch_to_blog( int $blog_id ): void { $GLOBALS['closehub_test_blog_id'] = $blog_id; }
function restore_current_blog(): void { $GLOBALS['closehub_test_blog_id'] = 1; }
function get_site_url(): string { return 'https://site-' . $GLOBALS['closehub_test_blog_id'] . '.test'; }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function absint( mixed $value ): int { return abs( (int) $value ); }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { return 'closehub_network_wrap_ability' === $hook ? $GLOBALS['closehub_test_wrap'] : $value; }

require_once dirname( __DIR__ ) . '/includes/class-network.php';

function closehub_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
}

$seen = [];
$args = [
	'label'               => 'Get redirections',
	'description'         => 'Returns redirections.',
	'input_schema'        => [ 'type' => 'object', 'default' => [], 'properties' => [ 'status' => [ 'type' => 'string' ] ], 'additionalProperties' => false ],
	'output_schema'       => [ 'type' => 'object', 'properties' => [ 'total' => [ 'type' => 'integer' ] ] ],
	'execute_callback'    => function ( $input ) use ( &$seen ): array { $seen[] = [ $GLOBALS['closehub_test_blog_id'], $input ]; return [ 'total' => $GLOBALS['closehub_test_blog_id'] ]; },
	'permission_callback' => static fn( $input = null ): bool => true,
	'meta'                => [ 'mcp' => [ 'public' => true ] ],
];

// ── Third-party ability gains a required site_id, keeps additionalProperties:false valid, and is tagged as wrapped ──
$wrapped = CloseHub_Network::wrap_external_ability( $args, 'rank-math/get-redirections' );
closehub_test_assert( isset( $wrapped['input_schema']['properties']['site_id'], $wrapped['input_schema']['properties']['status'] ), 'site_id must be added next to existing input properties.' );
closehub_test_assert( isset( $wrapped['output_schema']['properties']['site_id'], $wrapped['output_schema']['properties']['total'] ), 'Output schema must allow site_id/site_url.' );
closehub_test_assert( str_contains( $wrapped['description'], 'site_id is required' ) && true === $wrapped['meta']['closehub_network'] && true === $wrapped['meta']['mcp']['public'], 'Description and meta must be updated without losing existing meta.' );
closehub_test_assert( $wrapped === CloseHub_Network::wrap_external_ability( $wrapped, 'rank-math/get-redirections' ), 'Wrapping twice must be a no-op.' );

// ── It runs on the chosen site only, without leaking site_id into the original callback ──
$missing = ( $wrapped['execute_callback'] )( [ 'status' => 'all' ] );
closehub_test_assert( is_wp_error( $missing ) && 'closehub_site_id_required' === $missing->get_error_code() && [] === $seen, 'Missing site_id must be rejected before running.' );
$result = ( $wrapped['execute_callback'] )( [ 'status' => 'all', 'site_id' => 9 ] );
closehub_test_assert( [ [ 9, [ 'status' => 'all' ] ] ] === $seen && 9 === $result['site_id'] && 9 === $result['total'] && 'https://site-9.test' === $result['site_url'], 'Ability must run on site 9 with the original input.' );
closehub_test_assert( 1 === $GLOBALS['closehub_test_blog_id'], 'Current site was not restored.' );
closehub_test_assert( true === ( $wrapped['permission_callback'] )( [ 'site_id' => 9 ] ), 'Permission is checked on the target site.' );

// ── List and scalar results go under `result`; non-object output schemas are wrapped ──
$list = CloseHub_Network::wrap_external_ability( [ 'output_schema' => [ 'type' => 'array' ], 'execute_callback' => static fn(): array => [ 1, 2 ] ] + $args, 'imagify/get-stats' );
closehub_test_assert( 'object' === $list['output_schema']['type'] && [ 'type' => 'array' ] === $list['output_schema']['properties']['result'], 'Array output schema must be nested under result.' );
$result = ( $list['execute_callback'] )( [ 'site_id' => 9 ] );
closehub_test_assert( [ 1, 2 ] === $result['result'] && 9 === $result['site_id'], 'List output must be returned under result.' );

// ── Ability without an input schema still receives null ──
$null_seen = 'unset';
$bare      = CloseHub_Network::wrap_external_ability( [ 'execute_callback' => function ( $input ) use ( &$null_seen ): array { $null_seen = $input; return [ 'ok' => true ]; }, 'permission_callback' => static fn(): bool => true ] + array_diff_key( $args, [ 'input_schema' => 1, 'output_schema' => 1, 'execute_callback' => 1, 'permission_callback' => 1 ] ), 'pixelyoursite/mcp-access-info' );
closehub_test_assert( isset( $bare['input_schema']['properties']['site_id'] ), 'Schema-less ability must gain a site_id-only schema.' );
( $bare['execute_callback'] )( [ 'site_id' => 9 ] );
closehub_test_assert( null === $null_seen, 'Schema-less ability must keep receiving null input.' );

// ── Left alone: own, adapter and core namespaces, opted-out abilities, non-object input ──
foreach ( [ 'closehub/get-post', 'mcp-adapter/discover-abilities', 'core/get-site-info' ] as $id ) {
	closehub_test_assert( $args === CloseHub_Network::wrap_external_ability( $args, $id ), "$id must not be wrapped." );
}
$GLOBALS['closehub_test_wrap'] = false;
closehub_test_assert( $args === CloseHub_Network::wrap_external_ability( $args, 'rank-math/get-redirections' ), 'Opt-out filter must be honoured.' );
$GLOBALS['closehub_test_wrap'] = true;
$scalar = [ 'input_schema' => [ 'type' => 'string' ] ] + $args;
closehub_test_assert( $scalar === CloseHub_Network::wrap_external_ability( $scalar, 'x/y' ), 'Non-object input cannot carry site_id and must be left alone.' );

// ── Single site: untouched ──
$GLOBALS['closehub_test_multisite'] = false;
closehub_test_assert( $args === CloseHub_Network::wrap_external_ability( $args, 'rank-math/get-redirections' ), 'Single-site abilities must be untouched.' );

echo "MCP external ability checks passed.\n";
