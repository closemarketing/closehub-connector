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
function is_multisite(): bool { return $GLOBALS['closehub_test_multisite']; }
function get_sites( array $args ): array { return [ (object) [ 'blog_id' => 1 ], (object) [ 'blog_id' => 2 ], (object) [ 'blog_id' => 3 ] ]; }
function switch_to_blog( int $blog_id ): void { $GLOBALS['closehub_test_blog_id'] = $blog_id; }
function restore_current_blog(): void { $GLOBALS['closehub_test_blog_id'] = 1; }
function get_site_url(): string { return 'https://site-' . $GLOBALS['closehub_test_blog_id'] . '.test'; }
$GLOBALS['closehub_test_super'] = true;
$GLOBALS['closehub_test_member_of'] = [ 1 => true ];
function is_super_admin(): bool { return $GLOBALS['closehub_test_super']; }
function get_current_user_id(): int { return 7; }
function get_blogs_of_user( int $user_id ): array { return $GLOBALS['closehub_test_member_of']; }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function absint( mixed $value ): int { return abs( (int) $value ); }

require_once dirname( __DIR__ ) . '/includes/class-network.php';

function closehub_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
}

$ran      = [];
$callback = function ( $input ) use ( &$ran ): array { $ran[] = $GLOBALS['closehub_test_blog_id']; return [ 'url' => 'post-url', 'input' => $input ]; };
$allow    = static fn( $input = null ): bool => true;

// ── Fan-out: runs on every site, keeps the post's own `url`, restores the current site ──
$result = CloseHub_Network::execute( 'closehub/list-posts', $callback, $allow )( [ 'search' => 'x' ] );
closehub_test_assert( [ 1, 2, 3 ] === $ran, 'Fan-out ability did not run on every site.' );
closehub_test_assert( 3 === count( $result['sites'] ) && 'https://site-2.test' === $result['sites'][1]['site_url'] && 'post-url' === $result['sites'][1]['url'], 'Fan-out entries must carry site_id/site_url without overwriting data keys.' );
closehub_test_assert( 1 === $GLOBALS['closehub_test_blog_id'], 'Current site was not restored.' );

// ── Fan-out: site_ids limits the targets and is not forwarded to the ability ──
$ran    = [];
$result = CloseHub_Network::execute( 'closehub/list-posts', $callback, $allow )( [ 'site_ids' => [ 3 ] ] );
closehub_test_assert( [ 3 ] === $ran && ! isset( $result['sites'][0]['input']['site_ids'] ), 'site_ids did not limit the fan-out.' );
$unknown = CloseHub_Network::execute( 'closehub/list-posts', $callback, $allow )( [ 'site_ids' => [ 99 ] ] );
closehub_test_assert( is_wp_error( $unknown ) && 'closehub_site_not_found' === $unknown->get_error_code(), 'Unknown site_ids must be rejected.' );

// ── Fan-out: a site where the user lacks the capability reports an error and is not executed ──
$ran    = [];
$result = CloseHub_Network::execute( 'closehub/list-posts', $callback, static fn(): bool => 2 !== $GLOBALS['closehub_test_blog_id'] )( [] );
closehub_test_assert( [ 1, 3 ] === $ran && isset( $result['sites'][1]['error'] ), 'Denied site must be skipped and reported.' );

// ── Targeted: site_id is required, and a post-ID ability never fans out ──
$ran     = [];
$missing = CloseHub_Network::execute( 'closehub/trash-post', $callback, $allow )( [ 'post_id' => 5 ] );
closehub_test_assert( is_wp_error( $missing ) && 'closehub_site_id_required' === $missing->get_error_code() && [] === $ran, 'Targeted ability must require site_id and not run.' );
$result = CloseHub_Network::execute( 'closehub/trash-post', $callback, $allow )( [ 'post_id' => 5, 'site_id' => 2 ] );
closehub_test_assert( [ 2 ] === $ran && 2 === $result['site_id'] && ! isset( $result['input']['site_id'] ), 'Targeted ability must run only on the chosen site.' );
$denied = CloseHub_Network::execute( 'closehub/trash-post', $callback, static fn(): bool => false )( [ 'site_id' => 2 ] );
closehub_test_assert( is_wp_error( $denied ) && 'closehub_forbidden' === $denied->get_error_code(), 'Targeted ability must re-check permission on the chosen site.' );

// ── Permission: passes when any target site allows, fails when none do ──
$allowed_on_3 = static fn(): bool => 3 === $GLOBALS['closehub_test_blog_id'];
closehub_test_assert( true === CloseHub_Network::permission( 'closehub/list-posts', $allowed_on_3 )( [] ), 'Fan-out permission should pass when one site allows it.' );
closehub_test_assert( false === CloseHub_Network::permission( 'closehub/trash-post', $allowed_on_3 )( [ 'site_id' => 2 ] ), 'Targeted permission must be checked on the chosen site.' );
closehub_test_assert( true === CloseHub_Network::permission( 'closehub/trash-post', $allowed_on_3 )( [ 'site_id' => 3 ] ), 'Targeted permission should pass on a permitted site.' );
closehub_test_assert( 1 === $GLOBALS['closehub_test_blog_id'], 'Current site was not restored after permission checks.' );

// ── Once: server-wide abilities run a single time on the current site ──
$ran = [];
CloseHub_Network::execute( 'closehub/update-plugins-and-core', $callback, $allow )( [] );
closehub_test_assert( [ 1 ] === $ran, 'A server-wide ability must run once.' );

// ── A translation link never fans out: translation_of needs exactly one site ──
$ran    = [];
$linked = CloseHub_Network::execute( 'closehub/create-post', $callback, $allow )( [ 'translation_of' => 5 ] );
closehub_test_assert( is_wp_error( $linked ) && 'closehub_site_id_required' === $linked->get_error_code() && [] === $ran, 'translation_of without site_ids must be rejected before running.' );
$linked = CloseHub_Network::execute( 'closehub/create-post', $callback, $allow )( [ 'translation_of' => 5, 'site_ids' => [ 2, 3 ] ] );
closehub_test_assert( is_wp_error( $linked ) && [] === $ran, 'translation_of with several sites must be rejected.' );
$linked = CloseHub_Network::execute( 'closehub/create-post', $callback, $allow )( [ 'translation_of' => 5, 'site_ids' => [ 2 ] ] );
closehub_test_assert( [ 2 ] === $ran && ! is_wp_error( $linked ), 'translation_of with one site must run on it.' );
$ran = [];
CloseHub_Network::execute( 'closehub/create-post', $callback, $allow )( [ 'title' => 'x' ] );
closehub_test_assert( [ 1, 2, 3 ] === $ran, 'create-post without translation_of must still fan out.' );

// ── A user who is not a super admin only reaches the sites they belong to ──
$GLOBALS['closehub_test_super']     = false;
$GLOBALS['closehub_test_member_of'] = [ 2 => true ];
$ran    = [];
$result = CloseHub_Network::execute( 'closehub/list-posts', $callback, $allow )( [] );
closehub_test_assert( [ 2 ] === $ran && 1 === count( $result['sites'] ), 'Fan-out must only cover the sites the user belongs to.' );
$hidden = CloseHub_Network::execute( 'closehub/list-posts', $callback, $allow )( [ 'site_ids' => [ 3 ] ] );
closehub_test_assert( is_wp_error( $hidden ) && 'closehub_site_not_found' === $hidden->get_error_code(), 'A hidden site must not be addressable.' );
$hidden = CloseHub_Network::execute( 'closehub/trash-post', $callback, $allow )( [ 'site_id' => 3 ] );
closehub_test_assert( is_wp_error( $hidden ) && 'closehub_site_not_found' === $hidden->get_error_code(), 'A hidden site must not be targetable.' );
$GLOBALS['closehub_test_super'] = true;

// ── site_id is declared required for targeted abilities only ──
closehub_test_assert( [ 'post_id', 'site_id' ] === CloseHub_Network::required( 'closehub/trash-post', [ 'post_id' ] ), 'Targeted schema must require site_id.' );
closehub_test_assert( [ 'title' ] === CloseHub_Network::required( 'closehub/create-post', [ 'title' ] ) && [] === CloseHub_Network::required( 'closehub/update-plugins-and-core', [] ), 'Fan-out and server-wide schemas must not require site_id.' );

// ── Single site: input and output are untouched ──
$GLOBALS['closehub_test_multisite'] = false;
$ran    = [];
$result = CloseHub_Network::execute( 'closehub/trash-post', $callback, $allow )( [ 'post_id' => 5 ] );
closehub_test_assert( [ 1 ] === $ran && 'post-url' === $result['url'] && ! isset( $result['sites'] ) && ! isset( $result['site_id'] ), 'Single-site output must be unchanged.' );
closehub_test_assert( [ 'post_id' ] === CloseHub_Network::required( 'closehub/trash-post', [ 'post_id' ] ), 'Single-site schema must not require site_id.' );
closehub_test_assert( [ 'post_id' => [] ] === CloseHub_Network::input_properties( 'closehub/trash-post', [ 'post_id' => [] ] ), 'Single-site schema must not gain site_id.' );

echo "MCP network ability checks passed.\n";
