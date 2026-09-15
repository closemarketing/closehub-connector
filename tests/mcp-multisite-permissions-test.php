<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( private string $code, private string $message, private array $data = [] ) {}
	public function get_error_message(): string { return $this->message; }
}
class WP_REST_Request {}
class WP_REST_Response {}

$GLOBALS['closehub_test_blog_id'] = 1;
function is_multisite(): bool { return true; }
function get_sites( array $args ): array { return [ (object) [ 'blog_id' => 1 ], (object) [ 'blog_id' => 2 ], (object) [ 'blog_id' => 3 ] ]; }
function switch_to_blog( int $blog_id ): void { $GLOBALS['closehub_test_blog_id'] = $blog_id; }
function restore_current_blog(): void { $GLOBALS['closehub_test_blog_id'] = 1; }
function get_site_url(): string { return 'https://site-' . $GLOBALS['closehub_test_blog_id'] . '.test'; }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

require_once dirname( __DIR__ ) . '/includes/class-rest-api.php';

$executed = [];
$result   = ( new CloseHub_REST_API() )->run(
	function () use ( &$executed ): array {
		$executed[] = $GLOBALS['closehub_test_blog_id'];
		return [ 'updated' => true ];
	},
	null,
	fn(): bool => 2 !== $GLOBALS['closehub_test_blog_id']
);

if ( [ 1, 3 ] !== $executed ) { fwrite( STDERR, "Write callback ran on a site without permission.\n" ); exit( 1 ); }
if ( ! isset( $result['sites'][1]['error'] ) || isset( $result['sites'][1]['updated'] ) ) { fwrite( STDERR, "Denied site was not reported as forbidden.\n" ); exit( 1 ); }
if ( 1 !== $GLOBALS['closehub_test_blog_id'] ) { fwrite( STDERR, "Current site was not restored after network execution.\n" ); exit( 1 ); }

echo "MCP multisite permission checks passed.\n";
